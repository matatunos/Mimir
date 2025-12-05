<?php
/**
 * File Management Class
 */
class FileManager {
    private $db;
    private $auth;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->auth = new Auth();
    }

    /**
     * Upload a file
     */
    public function upload($file, $userId, $folderId = null) {
        try {
            // Validate file
            if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                throw new Exception("Invalid file upload");
            }

            $fileSize = $file['size'];
            $originalFilename = basename($file['name']);
            $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

            // Check file extension
            if (!in_array($extension, ALLOWED_EXTENSIONS)) {
                throw new Exception("File type not allowed");
            }

            // Check file size
            $maxSize = SystemConfig::get('max_file_size', MAX_FILE_SIZE_DEFAULT);
            if ($fileSize > $maxSize) {
                throw new Exception("File size exceeds maximum allowed");
            }

            // Check storage quota
            if (!$this->auth->hasStorageSpace($userId, $fileSize)) {
                throw new Exception("Storage quota exceeded");
            }

            // Generate unique filename
            $storedFilename = uniqid() . '_' . time() . '.' . $extension;
            $filePath = UPLOAD_DIR . $storedFilename;

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new Exception("Failed to move uploaded file");
            }

            // Calculate file hash
            $fileHash = hash_file('sha256', $filePath);

            // Get MIME type
            $mimeType = mime_content_type($filePath);

            // Calculate expiration date if configured
            $fileLifetimeDays = SystemConfig::get('file_lifetime_days', 0);
            $expiresAt = null;
            if ($fileLifetimeDays > 0) {
                $expiresAt = date('Y-m-d H:i:s', strtotime("+$fileLifetimeDays days"));
            }

            // Insert file record
            $stmt = $this->db->prepare("INSERT INTO files (user_id, folder_id, original_filename, stored_filename, file_path, file_size, mime_type, file_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $folderId, $originalFilename, $storedFilename, $filePath, $fileSize, $mimeType, $fileHash, $expiresAt]);

            $fileId = $this->db->lastInsertId();

            // Update storage used
            $this->auth->updateStorageUsed($userId, $fileSize);

            // Log action
            AuditLog::log($userId, 'file_uploaded', 'file', $fileId, "Uploaded: $originalFilename");

            // Send notification if enabled
            Notification::send($userId, 'file_upload', "File uploaded: $originalFilename");

            return $fileId;
        } catch (Exception $e) {
            error_log("File upload failed: " . $e->getMessage());
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            throw $e;
        }
    }

    /**
     * Get file by ID
     */
    public function getFile($fileId, $userId = null) {
        $query = "SELECT * FROM files WHERE id = ?";
        $params = [$fileId];

        if ($userId !== null) {
            $query .= " AND user_id = ?";
            $params[] = $userId;
        }

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Get files for a user
     */
    public function getUserFiles($userId, $folderId = null, $limit = 100, $offset = 0) {
        // If admin, show all files
        $user = $this->auth->getUserById($userId);
        $isAdmin = $user && $user['role'] === 'admin';
        $sql = "SELECT f.*, 
                       s.id as share_id, 
                       s.share_token as share_token, 
                       s.expires_at as share_expires_at, 
                       s.max_downloads as share_max_downloads, 
                       s.current_downloads as share_download_count, 
                       s.is_active as share_is_active,
                       s.requires_password as share_has_password,
                       u.username as owner_username
                FROM files f
                LEFT JOIN public_shares s ON f.id = s.file_id AND s.is_active = 1
                LEFT JOIN users u ON f.user_id = u.id
                WHERE 1=1";
        $params = [];
        if (!$isAdmin) {
            $sql .= " AND f.user_id = ?";
            $params[] = $userId;
        }
        if ($folderId === null) {
            $sql .= " AND f.folder_id IS NULL";
        } else {
            $sql .= " AND f.folder_id = ?";
            $params[] = $folderId;
        }
        $sql .= " ORDER BY f.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Download file
     */
    public function download($fileId, $userId = null) {
        $file = $this->getFile($fileId, $userId);
        
        if (!$file) {
            throw new Exception("File not found");
        }

        if (!file_exists($file['file_path'])) {
            throw new Exception("File does not exist on disk");
        }

        // Update download count
        $stmt = $this->db->prepare("UPDATE files SET download_count = download_count + 1 WHERE id = ?");
        $stmt->execute([$fileId]);

        // Log download
        AuditLog::log($userId ?? $file['user_id'], 'file_downloaded', 'file', $fileId, "Downloaded: {$file['original_filename']}");

        // Send file
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: attachment; filename="' . $file['original_filename'] . '"');
        header('Content-Length: ' . $file['file_size']);
        readfile($file['file_path']);
        exit;
    }

    /**
     * Delete file
     */
    public function delete($fileId, $userId) {
        $file = $this->getFile($fileId, $userId);
        
        if (!$file) {
            throw new Exception("File not found");
        }

        // Delete physical file
        if (file_exists($file['file_path'])) {
            unlink($file['file_path']);
        }

        // Delete database record (will cascade delete shares)
        $stmt = $this->db->prepare("DELETE FROM files WHERE id = ?");
        $stmt->execute([$fileId]);

        // Update storage used
        $this->auth->updateStorageUsed($userId, -$file['file_size']);

        // Log action
        AuditLog::log($userId, 'file_deleted', 'file', $fileId, "Deleted: {$file['original_filename']}");

        return true;
    }

    /**
     * Clean up expired files
     */
    public static function cleanupExpiredFiles() {
        $db = Database::getInstance()->getConnection();
        
        // Get expired files
        $stmt = $db->prepare("SELECT * FROM files WHERE expires_at IS NOT NULL AND expires_at < NOW()");
        $stmt->execute();
        $expiredFiles = $stmt->fetchAll();

        foreach ($expiredFiles as $file) {
            // Delete physical file
            if (file_exists($file['file_path'])) {
                unlink($file['file_path']);
            }

            // Update storage used
            $auth = new Auth();
            $auth->updateStorageUsed($file['user_id'], -$file['file_size']);

            // Delete database record
            $deleteStmt = $db->prepare("DELETE FROM files WHERE id = ?");
            $deleteStmt->execute([$file['id']]);

            // Log action
            AuditLog::log(null, 'file_expired', 'file', $file['id'], "Expired: {$file['original_filename']}");
        }

        return count($expiredFiles);
    }
}
