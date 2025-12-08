<?php
/**
 * Mimir File Management System
 * File Management Class
 */

require_once __DIR__ . '/User.php';
require_once __DIR__ . '/Logger.php';

class File {
    private $db;
    private $logger;
    private $userClass;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->userClass = new User();
    }
    
    /**
     * Upload a file
     */
    public function upload($fileData, $userId, $description = null) {
        try {
            // Validate file
            if (!isset($fileData['tmp_name']) || !is_uploaded_file($fileData['tmp_name'])) {
                throw new Exception("Invalid file upload");
            }
            
            $fileSize = $fileData['size'];
            $originalName = basename($fileData['name']);
            $mimeType = mime_content_type($fileData['tmp_name']);
            
            // Check file size
            if ($fileSize > MAX_FILE_SIZE) {
                throw new Exception("File size exceeds maximum allowed");
            }
            
            // Check storage quota
            if (!$this->userClass->hasStorageAvailable($userId, $fileSize)) {
                throw new Exception("Storage quota exceeded");
            }
            
            // Check file extension
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExts = explode(',', ALLOWED_EXTENSIONS);
            if (!in_array($ext, $allowedExts)) {
                throw new Exception("File type not allowed");
            }
            
            // Generate unique file name and hash
            $fileHash = hash_file('sha256', $fileData['tmp_name']);
            $storedName = uniqid() . '_' . time() . '.' . $ext;
            
            // Create user directory structure
            $userDir = UPLOADS_PATH . '/' . $userId;
            if (!is_dir($userDir)) {
                mkdir($userDir, 0770, true);
            }
            
            $filePath = $userDir . '/' . $storedName;
            
            // Move uploaded file
            if (!move_uploaded_file($fileData['tmp_name'], $filePath)) {
                throw new Exception("Failed to move uploaded file");
            }
            
            // Insert file record
            $stmt = $this->db->prepare("
                INSERT INTO files 
                (user_id, original_name, stored_name, file_path, file_size, mime_type, file_hash, description) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                $originalName,
                $storedName,
                $filePath,
                $fileSize,
                $mimeType,
                $fileHash,
                $description
            ]);
            
            $fileId = $this->db->lastInsertId();
            
            // Update user storage
            $this->userClass->updateStorageUsed($userId, $fileSize);
            
            // Log action
            $this->logger->log($userId, 'file_uploaded', 'file', $fileId, "File uploaded: $originalName");
            
            return $fileId;
        } catch (Exception $e) {
            error_log("File upload error: " . $e->getMessage());
            
            // Clean up file if database insert failed
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            
            throw $e;
        }
    }
    
    /**
     * Get file by ID
     */
    public function getById($id, $userId = null) {
        try {
            $sql = "
                SELECT f.*, u.username, u.full_name as owner_name
                FROM files f
                LEFT JOIN users u ON f.user_id = u.id
                WHERE f.id = ?
            ";
            
            $params = [$id];
            
            // If userId provided, ensure user owns the file
            if ($userId !== null) {
                $sql .= " AND f.user_id = ?";
                $params[] = $userId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("File getById error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get files by user
     */
    public function getByUser($userId, $filters = [], $limit = 50, $offset = 0) {
        try {
            $where = ["user_id = ?"];
            $params = [$userId];
            
            if (!empty($filters['search'])) {
                $where[] = "(original_name LIKE ? OR description LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if (isset($filters['is_shared'])) {
                $where[] = "is_shared = ?";
                $params[] = $filters['is_shared'];
            }
            
            $whereClause = implode(' AND ', $where);
            
            $sql = "
                SELECT 
                    f.*,
                    (SELECT COUNT(*) FROM shares WHERE file_id = f.id AND is_active = 1) as share_count
                FROM files f
                WHERE $whereClause
                ORDER BY f.created_at DESC
                LIMIT ? OFFSET ?
            ";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("File getByUser error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all files (admin)
     */
    public function getAll($filters = [], $limit = 50, $offset = 0) {
        try {
            $where = [];
            $params = [];
            
            if (!empty($filters['user_id'])) {
                $where[] = "f.user_id = ?";
                $params[] = $filters['user_id'];
            }
            
            if (!empty($filters['search'])) {
                $where[] = "(f.original_name LIKE ? OR f.description LIKE ? OR u.username LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if (isset($filters['is_shared'])) {
                $where[] = "f.is_shared = ?";
                $params[] = $filters['is_shared'];
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $sql = "
                SELECT 
                    f.*,
                    u.username,
                    u.full_name as owner_name,
                    (SELECT COUNT(*) FROM shares WHERE file_id = f.id AND is_active = 1) as share_count
                FROM files f
                LEFT JOIN users u ON f.user_id = u.id
                $whereClause
                ORDER BY f.created_at DESC
                LIMIT ? OFFSET ?
            ";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("File getAll error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get file count
     */
    public function getCount($userId = null, $filters = []) {
        try {
            $where = [];
            $params = [];
            
            if ($userId !== null) {
                $where[] = "f.user_id = ?";
                $params[] = $userId;
            } elseif (!empty($filters['user_id'])) {
                $where[] = "f.user_id = ?";
                $params[] = $filters['user_id'];
            }
            
            if (!empty($filters['search'])) {
                $where[] = "(f.original_name LIKE ? OR f.description LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if (isset($filters['is_shared'])) {
                $where[] = "f.is_shared = ?";
                $params[] = $filters['is_shared'];
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM files f $whereClause");
            $stmt->execute($params);
            
            $result = $stmt->fetch();
            return $result['total'];
        } catch (Exception $e) {
            error_log("File getCount error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Update file
     */
    public function update($id, $userId, $data) {
        try {
            $fields = [];
            $params = [];
            
            if (isset($data['description'])) {
                $fields[] = "description = ?";
                $params[] = $data['description'];
            }
            
            if (isset($data['original_name'])) {
                $fields[] = "original_name = ?";
                $params[] = $data['original_name'];
            }
            
            if (empty($fields)) {
                return false;
            }
            
            $params[] = $id;
            $params[] = $userId;
            
            $sql = "UPDATE files SET " . implode(', ', $fields) . " WHERE id = ? AND user_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $this->logger->log($userId, 'file_updated', 'file', $id, "File updated: ID $id");
            
            return true;
        } catch (Exception $e) {
            error_log("File update error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete file
     */
    public function delete($id, $userId = null) {
        try {
            // Get file info
            $file = $this->getById($id, $userId);
            if (!$file) {
                return false;
            }
            
            // Delete physical file
            if (file_exists($file['file_path'])) {
                unlink($file['file_path']);
            }
            
            // Delete database record (cascades to shares)
            $stmt = $this->db->prepare("DELETE FROM files WHERE id = ?");
            $stmt->execute([$id]);
            
            // Update user storage
            $this->userClass->updateStorageUsed($file['user_id'], -$file['file_size']);
            
            // Log action
            $this->logger->log(
                $userId ?? $file['user_id'],
                'file_deleted',
                'file',
                $id,
                "File deleted: {$file['original_name']}"
            );
            
            return true;
        } catch (Exception $e) {
            error_log("File delete error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete multiple files
     */
    public function deleteMultiple($ids, $userId = null) {
        try {
            $this->db->beginTransaction();
            
            $deleted = 0;
            foreach ($ids as $id) {
                if ($this->delete($id, $userId)) {
                    $deleted++;
                }
            }
            
            $this->db->commit();
            return $deleted;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("File deleteMultiple error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Download file
     */
    public function download($id, $userId = null) {
        try {
            $file = $this->getById($id, $userId);
            if (!$file || !file_exists($file['file_path'])) {
                return false;
            }
            
            // Log download
            $this->logger->log(
                $userId ?? null,
                'file_downloaded',
                'file',
                $id,
                "File downloaded: {$file['original_name']}"
            );
            
            // Send file
            header('Content-Type: ' . $file['mime_type']);
            header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
            header('Content-Length: ' . $file['file_size']);
            header('Cache-Control: no-cache');
            
            readfile($file['file_path']);
            exit;
        } catch (Exception $e) {
            error_log("File download error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update file shared status
     */
    public function updateSharedStatus($fileId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE files 
                SET is_shared = (SELECT COUNT(*) > 0 FROM shares WHERE file_id = ? AND is_active = 1)
                WHERE id = ?
            ");
            $stmt->execute([$fileId, $fileId]);
            return true;
        } catch (Exception $e) {
            error_log("Update shared status error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get orphan files (files without owner)
     */
    public function getOrphans($filters = [], $limit = 50, $offset = 0) {
        try {
            $where = ["user_id IS NULL"];
            $params = [];
            
            if (!empty($filters['search'])) {
                $where[] = "original_name LIKE ?";
                $params[] = '%' . $filters['search'] . '%';
            }
            
            $sql = "SELECT * FROM files WHERE " . implode(' AND ', $where) . 
                   " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Get orphans error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Count orphan files
     */
    public function countOrphans($filters = []) {
        try {
            $where = ["user_id IS NULL"];
            $params = [];
            
            if (!empty($filters['search'])) {
                $where[] = "original_name LIKE ?";
                $params[] = '%' . $filters['search'] . '%';
            }
            
            $sql = "SELECT COUNT(*) as count FROM files WHERE " . implode(' AND ', $where);
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch()['count'] ?? 0;
        } catch (Exception $e) {
            error_log("Count orphans error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Reassign file to new owner
     */
    public function reassignOwner($fileId, $newUserId) {
        try {
            // Get file info
            $file = $this->getById($fileId);
            if (!$file) {
                throw new Exception("File not found");
            }
            
            // Get old and new user info
            $oldUserId = $file['user_id'];
            $newUser = $this->userClass->getById($newUserId);
            if (!$newUser) {
                throw new Exception("New user not found");
            }
            
            // Check if new user has storage available
            if (!$this->userClass->hasStorageAvailable($newUserId, $file['file_size'])) {
                throw new Exception("New user storage quota exceeded");
            }
            
            $this->db->beginTransaction();
            
            // Update file owner
            $stmt = $this->db->prepare("UPDATE files SET user_id = ? WHERE id = ?");
            $stmt->execute([$newUserId, $fileId]);
            
            // Update storage used for new user
            $this->userClass->updateStorageUsed($newUserId, $file['file_size']);
            
            // Update storage used for old user if exists
            if ($oldUserId) {
                $this->userClass->updateStorageUsed($oldUserId, -$file['file_size']);
            }
            
            $this->db->commit();
            
            $this->logger->log(
                $_SESSION['user_id'] ?? null,
                'file_reassigned',
                'file',
                $fileId,
                "File '{$file['original_name']}' reassigned to user {$newUser['username']}"
            );
            
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Reassign owner error: " . $e->getMessage());
            throw $e;
        }
    }
}
