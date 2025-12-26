<?php
/**
 * Mimir File Management System
 * File Management Class
 */

require_once __DIR__ . '/User.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Config.php';

class File {
    private $db;
    private $logger;
    private $userClass;
    private $config;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->userClass = new User();
        $this->config = new Config();
    }
    
    /**
     * Upload a file
     */
    public function upload($fileData, $userId, $description = null, $parentFolderId = null, $allowDuplicates = false) {
        try {
            require_once __DIR__ . '/SecurityValidator.php';
            $security = SecurityValidator::getInstance();
            
            // Validate file
            $isHttpUpload = isset($fileData['tmp_name']) && is_uploaded_file($fileData['tmp_name']);
            $isCliTest = php_sapi_name() === 'cli' && getenv('ALLOW_CLI_UPLOAD') === '1' && isset($fileData['tmp_name']) && file_exists($fileData['tmp_name']);
            if (!$isHttpUpload && !$isCliTest) {
                throw new Exception("Invalid file upload");
            }
            
            $fileSize = $fileData['size'];
            $originalName = basename($fileData['name']);
            
            // Validate filename
            if (!$security->validateFilename($originalName)) {
                error_log("Invalid filename detected: " . $originalName);
                throw new Exception("Nombre de archivo no válido");
            }
            
            // Detect real MIME type (not from user input)
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $fileData['tmp_name']);
            finfo_close($finfo);
            
            // Check file size
            $maxFileSize = $this->config->get('max_file_size', MAX_FILE_SIZE);
            if ($fileSize > $maxFileSize) {
                    // Resolve username for logging if available
                    $usernameForLog = null;
                    if ($userId) {
                        $uStmt = $this->db->prepare("SELECT username FROM users WHERE id = ?");
                        $uStmt->execute([$userId]);
                        $usernameForLog = $uStmt->fetchColumn() ?: null;
                    }
                // Log security event for unusually large files
                    $stmt = $this->db->prepare("
                        INSERT INTO security_events 
                        (event_type, username, severity, ip_address, user_agent, description, details)
                        VALUES ('file_too_large', ?, 'low', ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $usernameForLog,
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        'Intento de subir archivo demasiado grande',
                        json_encode(['filename' => $originalName, 'size' => $fileSize, 'max' => $maxFileSize])
                    ]);
                
                throw new Exception("El archivo excede el tamaño máximo permitido");
            }
            
            // Check storage quota
            if (!$this->userClass->hasStorageAvailable($userId, $fileSize)) {
                throw new Exception("Cuota de almacenamiento excedida");
            }
            
            // Check file extension
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            
            // Get allowed extensions from config (database with fallback to constant)
            $allowedExtensionsStr = $this->config->get('allowed_extensions', ALLOWED_EXTENSIONS);
            $allowedExts = array_map('trim', explode(',', $allowedExtensionsStr));
            
            // Validate extension using SecurityValidator
            if (!$security->validateFileExtension($originalName, $allowedExts)) {
                // Log security event for blocked extension
                    $usernameForLog = null;
                    if ($userId) {
                        $uStmt = $this->db->prepare("SELECT username FROM users WHERE id = ?");
                        $uStmt->execute([$userId]);
                        $usernameForLog = $uStmt->fetchColumn() ?: null;
                    }

                    $stmt = $this->db->prepare("
                        INSERT INTO security_events 
                        (event_type, username, severity, user_id, ip_address, user_agent, description, details)
                        VALUES ('invalid_file_extension', ?, 'medium', ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $usernameForLog,
                        $userId,
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        'Intento de subir archivo con extensión no permitida',
                        json_encode(['filename' => $originalName, 'extension' => $ext])
                    ]);
                
                throw new Exception("Tipo de archivo no permitido");
            }
            
            // Validate MIME type matches extension
            $this->validateMimeType($mimeType, $ext);
            
            // Generate unique file name and hash
            $fileHash = hash_file('sha256', $fileData['tmp_name']);
            // Prevent duplicates in the same folder: if same hash exists in target folder, either block or allow saving a copy
            try {
                if ($parentFolderId === null) {
                    $stmt = $this->db->prepare("SELECT id FROM files WHERE file_hash = ? AND parent_folder_id IS NULL LIMIT 1");
                    $stmt->execute([$fileHash]);
                } else {
                    $stmt = $this->db->prepare("SELECT id FROM files WHERE file_hash = ? AND parent_folder_id = ? LIMIT 1");
                    $stmt->execute([$fileHash, $parentFolderId]);
                }
                $existing = $stmt->fetch();
                if ($existing) {
                    if (!$allowDuplicates) {
                        throw new Exception("Archivo duplicado en la carpeta (ID #" . $existing['id'] . ")");
                    }
                    // If duplicates are allowed, continue and save as a separate stored file (keep both)
                }
            } catch (Exception $e) {
                // If duplicate detected and not allowed, bubble up the exception to caller for reporting
                if (strpos($e->getMessage(), 'Archivo duplicado') === 0) {
                    throw $e;
                }
                // otherwise ignore DB lookup errors and continue
            }
            $storedName = uniqid() . '_' . time() . '.' . $ext;
            
            // Create user directory structure and ensure it's writable
            $userDir = UPLOADS_PATH . '/' . $userId;
            if (!is_dir($userDir)) {
                if (!@mkdir($userDir, 0770, true)) {
                    throw new Exception("Unable to create upload directory: $userDir");
                }
            }

            // Ensure the directory is writable by the PHP process
            if (!is_writable($userDir)) {
                @chmod($userDir, 0770);
                if (!is_writable($userDir)) {
                    $owner = null;
                    if (function_exists('posix_getpwuid')) {
                        $pw = @posix_getpwuid(@fileowner($userDir));
                        if ($pw && isset($pw['name'])) $owner = $pw['name'];
                    }
                    throw new Exception("Upload directory not writable: $userDir (owner: " . ($owner ?: 'unknown') . ")");
                }
            }

            $filePath = $userDir . '/' . $storedName;

            // Move uploaded file (provide richer error info on failure)
            $moved = false;
            // Prefer move_uploaded_file for actual HTTP uploads
            if (is_uploaded_file($fileData['tmp_name'])) {
                $moved = @move_uploaded_file($fileData['tmp_name'], $filePath);
            } else {
                // Allow CLI test mode when ALLOW_CLI_UPLOAD=1 and running via CLI: use rename() as a safe fallback
                if (php_sapi_name() === 'cli' && getenv('ALLOW_CLI_UPLOAD') === '1') {
                    $moved = @rename($fileData['tmp_name'], $filePath);
                    if (!$moved) {
                        // try copy as last resort
                        $moved = @copy($fileData['tmp_name'], $filePath);
                        if ($moved) @unlink($fileData['tmp_name']);
                    }
                } else {
                    // Not an uploaded file and not in CLI test mode
                    $moved = false;
                }
            }

            if (!$moved) {
                $err = error_get_last();
                $details = isset($err['message']) ? $err['message'] : 'unknown';
                throw new Exception("Failed to move uploaded file to $filePath: $details");
            }
            
            // Insert file record
            $stmt = $this->db->prepare(
                "INSERT INTO files 
                (user_id, original_name, stored_name, file_path, file_size, mime_type, file_hash, description, parent_folder_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $userId,
                $originalName,
                $storedName,
                $filePath,
                $fileSize,
                $mimeType,
                $fileHash,
                $description,
                $parentFolderId
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
    public function getByUser($userId, $filters = [], $limit = 50, $offset = 0, $includeExpired = false) {
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

            // By default hide expired files from user views unless explicitly requested
            if (!$includeExpired) {
                $where[] = "(f.is_expired = 0)";
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
     * Get folder contents (files and folders) for a user at a given folder level
     * Returns array of rows including `file_count` and `subfolder_count` for folders
     */
    public function getFolderContents($userId, $folderId = null, $includeExpired = false) {
        try {
            $params = [$userId];
            if ($folderId === null) {
                $parentClause = 'f.parent_folder_id IS NULL';
            } else {
                $parentClause = 'f.parent_folder_id = ?';
                $params[] = $folderId;
            }

            // By default hide expired files/folders from user views unless explicitly requested
            $fileCountSub = $includeExpired ? "(SELECT COUNT(*) FROM files WHERE parent_folder_id = f.id AND is_folder = 0)" : "(SELECT COUNT(*) FROM files WHERE parent_folder_id = f.id AND is_folder = 0 AND is_expired = 0)";
            $subfolderCountSub = $includeExpired ? "(SELECT COUNT(*) FROM files WHERE parent_folder_id = f.id AND is_folder = 1)" : "(SELECT COUNT(*) FROM files WHERE parent_folder_id = f.id AND is_folder = 1 AND is_expired = 0)";

            $sql = "
                SELECT
                    f.*,
                    $fileCountSub as file_count,
                    $subfolderCountSub as subfolder_count,
                    (SELECT COUNT(*) FROM shares WHERE file_id = f.id AND is_active = 1) as share_count
                FROM files f
                WHERE f.user_id = ? AND {$parentClause} " . ($includeExpired ? "" : "AND f.is_expired = 0") . "
                ORDER BY f.is_folder DESC, f.created_at DESC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('getFolderContents error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all files inside a folder recursively (returns file rows, excludes folder rows)
     */
    public function getFilesInFolderRecursive($userId, $folderId = null, $includeExpired = false) {
        try {
            $files = [];
            $items = $this->getFolderContents($userId, $folderId, $includeExpired);
            foreach ($items as $it) {
                if (!empty($it['is_folder'])) {
                    $children = $this->getFilesInFolderRecursive($userId, $it['id'], $includeExpired);
                    if (!empty($children)) {
                        foreach ($children as $c) $files[] = $c;
                    }
                } else {
                    $files[] = $it;
                }
            }
            return $files;
        } catch (Exception $e) {
            error_log('getFilesInFolderRecursive error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Build breadcrumb path for a folder (array of ['id'=>..., 'name'=>...])
     */
    public function getFolderPath($folderId) {
        try {
            $path = [];
            $current = $this->getById($folderId);
            while ($current) {
                $path[] = ['id' => $current['id'], 'name' => $current['original_name']];
                if (empty($current['parent_folder_id'])) break;
                $current = $this->getById($current['parent_folder_id']);
            }
            return array_reverse($path);
        } catch (Exception $e) {
            error_log('getFolderPath error: ' . $e->getMessage());
            return [];
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
            
            // Delete physical file (support absolute and relative stored paths)
            $fp = $file['file_path'] ?? '';
            if (!empty($fp)) {
                if (strpos($fp, '/') === 0) {
                    $fullPath = $fp;
                    // Use an allowed enum value for event_type to avoid SQL warnings
                    $stmt = $this->db->prepare("
                        INSERT INTO security_events 
                        (event_type, username, severity, ip_address, user_agent, description, details)
                        VALUES ('rate_limit', ?, 'low', ?, ?, ?, ?)
                    ");
                }
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
     * Create a folder record for a user
     */
    public function createFolder($userId, $name, $parentFolderId = null) {
        try {
            $stmt = $this->db->prepare("INSERT INTO files (user_id, original_name, stored_name, file_path, file_size, mime_type, file_hash, description, is_shared, is_folder, parent_folder_id, is_expired) VALUES (?, ?, '', '', 0, NULL, NULL, NULL, 0, 1, ?, 0)");
            $stmt->execute([$userId, $name, $parentFolderId]);
            $folderId = $this->db->lastInsertId();
            $this->logger->log($userId, 'folder_created', 'folder', $folderId, "Folder created: $name");
            return $folderId;
        } catch (Exception $e) {
            error_log('createFolder error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete a folder and its contents recursively
     */
    public function deleteFolder($folderId, $userId = null) {
        try {
            // Ensure folder exists and belongs to user if provided
            $folder = $this->getById($folderId, $userId);
            if (!$folder || !$folder['is_folder']) {
                throw new Exception('Carpeta no encontrada');
            }

            // Get child items
            $children = $this->getFolderContents($folder['user_id'], $folderId);

            foreach ($children as $child) {
                if ($child['is_folder']) {
                    $this->deleteFolder($child['id'], $userId);
                } else {
                    $this->delete($child['id'], $userId);
                }
            }

            // Delete the folder record itself
            $stmt = $this->db->prepare("DELETE FROM files WHERE id = ?");
            $stmt->execute([$folderId]);

            $this->logger->log($userId ?? $folder['user_id'], 'folder_deleted', 'folder', $folderId, "Folder deleted: {$folder['original_name']}");
            return true;
        } catch (Exception $e) {
            error_log('deleteFolder error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Download file
     */
    public function download($id, $userId = null) {
        try {
            require_once __DIR__ . '/SecurityValidator.php';
            require_once __DIR__ . '/SecurityHeaders.php';
            
            $security = SecurityValidator::getInstance();
            
            $file = $this->getById($id, $userId);
            if (!$file) {
                return false;
            }
            
            // Verify file exists and is within allowed directory using the stored file_path
            $realPath = realpath($file['file_path']);
            $realUploadsPath = realpath(UPLOADS_PATH);

            if ($realPath === false || strpos($realPath, $realUploadsPath) !== 0) {
                error_log("Path traversal attempt blocked: " . $file['file_path']);
                
                // Log security event
                    $usernameForLog = $file['username'] ?? null;

                    $stmt = $this->db->prepare("
                        INSERT INTO security_events 
                        (event_type, username, severity, ip_address, user_agent, description, details)
                        VALUES ('unauthorized_access', ?, 'critical', ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $usernameForLog,
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        'Intento de path traversal en descarga',
                        json_encode(['file_id' => $id, 'path' => $file['file_path']])
                    ]);
                
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
            
            // Prevent indexing of authenticated downloads
            header('X-Robots-Tag: noindex, nofollow');
            // Set secure download headers
            SecurityHeaders::setDownloadHeaders($file['original_name'], $file['mime_type'], true);
            header('Content-Length: ' . $file['file_size']);
            
            // Send file
            readfile($realPath);
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
            $where = ["f.user_id IS NULL"];
            $params = [];

            if (!empty($filters['search'])) {
                // search original name or last assignment description
                $where[] = "(f.original_name LIKE ? OR (SELECT al.description FROM activity_log al WHERE al.entity_type = 'file' AND al.entity_id = f.id AND al.action IN ('file_reassigned','orphan_assigned') ORDER BY al.created_at DESC LIMIT 1) LIKE ? )";
                $params[] = '%' . $filters['search'] . '%';
                $params[] = '%' . $filters['search'] . '%';
            }

            $sql = "SELECT f.*, u.username, u.full_name as owner_name, 
                       (SELECT al.description FROM activity_log al WHERE al.entity_type = 'file' AND al.entity_id = f.id AND al.action IN ('file_reassigned','orphan_assigned') ORDER BY al.created_at DESC LIMIT 1) as last_assignment_description
                    FROM files f
                    LEFT JOIN users u ON f.user_id = u.id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY f.created_at DESC LIMIT ? OFFSET ?";

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
            $where = ["f.user_id IS NULL"];
            $params = [];

            if (!empty($filters['search'])) {
                $where[] = "(f.original_name LIKE ? OR (SELECT al.description FROM activity_log al WHERE al.entity_type = 'file' AND al.entity_id = f.id AND al.action IN ('file_reassigned','orphan_assigned') ORDER BY al.created_at DESC LIMIT 1) LIKE ? )";
                $params[] = '%' . $filters['search'] . '%';
                $params[] = '%' . $filters['search'] . '%';
            }

            $sql = "SELECT COUNT(*) as count FROM files f WHERE " . implode(' AND ', $where);

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

            // Perform update and set DB actor variable so trigger can record who changed ownership
            $manageTx = false;
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $manageTx = true;
            }
            try {
                // set actor for trigger (use session user if available)
                $actor = $_SESSION['user_id'] ?? null;
                try {
                    $s = $this->db->prepare("SET @current_actor_id = ?");
                    $s->execute([$actor]);
                } catch (Exception $e) {
                    // ignore if cannot set variable
                }

                // Update the files table owner
                $upd = $this->db->prepare("UPDATE files SET user_id = ? WHERE id = ?");
                $upd->execute([$newUserId, $fileId]);

                // Update storage used for new user
                $this->userClass->updateStorageUsed($newUserId, $file['file_size']);

                // Update storage used for old user if exists
                if ($oldUserId) {
                    $this->userClass->updateStorageUsed($oldUserId, -$file['file_size']);
                }

                // Clear actor variable
                try { $this->db->query("SET @current_actor_id = NULL"); } catch (Exception $e) {}

                if ($manageTx) $this->db->commit();
            } catch (Exception $e) {
                if ($manageTx) $this->db->rollBack();
                throw $e;
            }

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
    
    /**
     * Validate MIME type matches file extension
     */
    private function validateMimeType($mimeType, $extension) {
        // Map of allowed MIME types for common extensions
        $mimeMap = [
            // Documents
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'odt' => ['application/vnd.oasis.opendocument.text'],
            'ods' => ['application/vnd.oasis.opendocument.spreadsheet'],
            'odp' => ['application/vnd.oasis.opendocument.presentation'],
            'rtf' => ['application/rtf', 'text/rtf'],
            'txt' => ['text/plain'],
            'csv' => ['text/csv', 'text/plain'],
            'json' => ['application/json', 'text/plain'],
            'xml' => ['application/xml', 'text/xml'],
            // Images
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'bmp' => ['image/bmp', 'image/x-ms-bmp'],
            'svg' => ['image/svg+xml'],
            'webp' => ['image/webp'],
            'ico' => ['image/x-icon', 'image/vnd.microsoft.icon'],
            // Video
            'mp4' => ['video/mp4'],
            'avi' => ['video/x-msvideo'],
            'mkv' => ['video/x-matroska'],
            'mov' => ['video/quicktime'],
            'wmv' => ['video/x-ms-wmv'],
            'flv' => ['video/x-flv'],
            'webm' => ['video/webm'],
            // Audio
            'mp3' => ['audio/mpeg'],
            'wav' => ['audio/wav', 'audio/x-wav'],
            'ogg' => ['audio/ogg'],
            'flac' => ['audio/flac'],
            'm4a' => ['audio/mp4'],
            // Archives
            'zip' => ['application/zip', 'application/x-zip-compressed'],
            'rar' => ['application/x-rar-compressed', 'application/vnd.rar'],
            '7z' => ['application/x-7z-compressed'],
            'tar' => ['application/x-tar'],
            'gz' => ['application/gzip', 'application/x-gzip'],
            // Code/Web
            'html' => ['text/html'],
            'css' => ['text/css'],
            'js' => ['application/javascript', 'text/javascript'],
        ];
        
        // If extension has defined MIME types, validate
        if (isset($mimeMap[$extension])) {
            if (!in_array($mimeType, $mimeMap[$extension])) {
                error_log("MIME type mismatch: extension=$extension, mime=$mimeType, expected=" . implode('|', $mimeMap[$extension]));
                throw new Exception("File content does not match extension");
            }
        }
        
        // Additional security: block dangerous MIME types regardless of extension
        $dangerousMimes = [
            'application/x-php',
            'application/x-httpd-php',
            'application/x-sh',
            'application/x-perl',
            'application/x-python',
            'application/x-executable',
            'application/x-msdownload',
        ];
        
        if (in_array($mimeType, $dangerousMimes)) {
            throw new Exception("Dangerous file type detected");
        }
    }
}
