<?php
/**
 * Mimir File Management System
 * Share Management Class
 */

require_once __DIR__ . '/File.php';
require_once __DIR__ . '/Logger.php';

class Share {
    private $db;
    private $logger;
    private $fileClass;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->fileClass = new File();
    }
    
    /**
     * Create a new share
     */
    public function create($fileId, $userId, $options = []) {
        try {
            // Verify file ownership
            $file = $this->fileClass->getById($fileId, $userId);
            if (!$file) {
                throw new Exception("File not found or access denied");
            }
            
            // Generate unique share token
            $shareToken = $this->generateToken();
            
            // Set defaults from config or options
            $config = new Config();
            $maxDays = $options['max_days'] ?? $config->get('default_max_share_days', 30);
            $maxDownloads = $options['max_downloads'] ?? $config->get('default_max_downloads', 100);
            
            $expiresAt = null;
            if ($maxDays > 0) {
                $expiresAt = date('Y-m-d H:i:s', strtotime("+$maxDays days"));
            }
            
            $password = null;
            if (!empty($options['password'])) {
                $password = password_hash($options['password'], PASSWORD_DEFAULT);
            }
            
            // Insert share record
            $stmt = $this->db->prepare("
                INSERT INTO shares 
                (file_id, share_token, share_name, password, max_downloads, expires_at, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $fileId,
                $shareToken,
                $options['name'] ?? $file['original_name'],
                $password,
                $maxDownloads > 0 ? $maxDownloads : null,
                $expiresAt,
                $userId
            ]);
            
            $shareId = $this->db->lastInsertId();
            
            // Update file shared status
            $this->fileClass->updateSharedStatus($fileId);
            
            // Log action
            $this->logger->log($userId, 'share_created', 'share', $shareId, "Share created for file: {$file['original_name']}");
            
            return [
                'id' => $shareId,
                'token' => $shareToken,
                'url' => BASE_URL . '/share.php?token=' . $shareToken
            ];
        } catch (Exception $e) {
            error_log("Share create error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get share by token
     */
    public function getByToken($token) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    s.*,
                    f.id as file_id,
                    f.original_name,
                    f.file_size,
                    f.mime_type,
                    f.file_path,
                    u.username as owner_username,
                    u.full_name as owner_name
                FROM shares s
                JOIN files f ON s.file_id = f.id
                JOIN users u ON s.created_by = u.id
                WHERE s.share_token = ?
            ");
            $stmt->execute([$token]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Share getByToken error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get share by ID
     */
    public function getById($id, $userId = null) {
        try {
            $sql = "
                SELECT 
                    s.*,
                    s.created_by as user_id,
                    s.share_token as token,
                    s.password as password_hash,
                    f.original_name,
                    f.file_size,
                    u.username as owner_username
                FROM shares s
                JOIN files f ON s.file_id = f.id
                JOIN users u ON s.created_by = u.id
                WHERE s.id = ?
            ";
            
            $params = [$id];
            
            if ($userId !== null) {
                $sql .= " AND s.created_by = ?";
                $params[] = $userId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Share getById error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get shares by file
     */
    public function getByFile($fileId, $userId = null) {
        try {
            $sql = "
                SELECT 
                    s.*,
                    (SELECT COUNT(*) FROM share_access_log WHERE share_id = s.id) as total_accesses
                FROM shares s
                WHERE s.file_id = ?
            ";
            
            $params = [$fileId];
            
            if ($userId !== null) {
                $sql .= " AND s.created_by = ?";
                $params[] = $userId;
            }
            
            $sql .= " ORDER BY s.created_at DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Share getByFile error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get shares by user
     */
    public function getByUser($userId, $filters = [], $limit = 50, $offset = 0) {
        try {
            $where = ["s.created_by = ?"];
            $params = [$userId];
            
            if (!empty($filters['search'])) {
                $where[] = "(s.share_name LIKE ? OR f.original_name LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if (isset($filters['is_active'])) {
                $where[] = "s.is_active = ?";
                $params[] = $filters['is_active'];
            }
            
            $whereClause = implode(' AND ', $where);
            
            $sql = "
                SELECT 
                    s.*,
                    s.share_token as token,
                    s.password as password_hash,
                    f.original_name,
                    f.file_size,
                    (SELECT COUNT(*) FROM share_access_log WHERE share_id = s.id) as total_accesses
                FROM shares s
                JOIN files f ON s.file_id = f.id
                WHERE $whereClause
                ORDER BY s.created_at DESC
                LIMIT ? OFFSET ?
            ";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Share getByUser error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all shares (admin)
     */
    public function getAll($filters = [], $limit = 50, $offset = 0) {
        try {
            $where = [];
            $params = [];
            
            if (!empty($filters['user_id'])) {
                $where[] = "s.created_by = ?";
                $params[] = $filters['user_id'];
            }
            
            if (!empty($filters['search'])) {
                $where[] = "(s.share_name LIKE ? OR f.original_name LIKE ? OR u.username LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if (isset($filters['is_active'])) {
                $where[] = "s.is_active = ?";
                $params[] = $filters['is_active'];
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $sql = "
                SELECT 
                    s.*,
                    s.share_token as token,
                    s.password as password_hash,
                    f.original_name,
                    f.file_size,
                    u.username,
                    u.username as owner_username,
                    u.full_name as owner_name,
                    (SELECT COUNT(*) FROM share_access_log WHERE share_id = s.id) as total_accesses
                FROM shares s
                JOIN files f ON s.file_id = f.id
                JOIN users u ON s.created_by = u.id
                $whereClause
                ORDER BY s.created_at DESC
                LIMIT ? OFFSET ?
            ";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Share getAll error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get share count
     */
    public function getCount($userId = null, $filters = []) {
        try {
            $where = [];
            $params = [];
            
            if ($userId !== null) {
                $where[] = "s.created_by = ?";
                $params[] = $userId;
            } elseif (!empty($filters['user_id'])) {
                $where[] = "s.created_by = ?";
                $params[] = $filters['user_id'];
            }
            
            if (!empty($filters['search'])) {
                $where[] = "(s.share_name LIKE ? OR f.original_name LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if (isset($filters['is_active'])) {
                $where[] = "s.is_active = ?";
                $params[] = $filters['is_active'];
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total 
                FROM shares s
                JOIN files f ON s.file_id = f.id
                $whereClause
            ");
            $stmt->execute($params);
            
            $result = $stmt->fetch();
            return $result['total'];
        } catch (Exception $e) {
            error_log("Share getCount error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Update share
     */
    public function update($id, $userId, $data) {
        try {
            $fields = [];
            $params = [];
            
            if (isset($data['share_name'])) {
                $fields[] = "share_name = ?";
                $params[] = $data['share_name'];
            }
            
            if (isset($data['max_downloads'])) {
                $fields[] = "max_downloads = ?";
                $params[] = $data['max_downloads'] > 0 ? $data['max_downloads'] : null;
            }
            
            if (isset($data['expires_at'])) {
                $fields[] = "expires_at = ?";
                $params[] = $data['expires_at'];
            }
            
            if (isset($data['is_active'])) {
                $fields[] = "is_active = ?";
                $params[] = $data['is_active'];
            }
            
            if (!empty($data['password'])) {
                $fields[] = "password = ?";
                $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            
            if (empty($fields)) {
                return false;
            }
            
            $params[] = $id;
            $params[] = $userId;
            
            $sql = "UPDATE shares SET " . implode(', ', $fields) . " WHERE id = ? AND created_by = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $this->logger->log($userId, 'share_updated', 'share', $id, "Share updated: ID $id");
            
            return true;
        } catch (Exception $e) {
            error_log("Share update error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete share
     */
    public function delete($id, $userId = null) {
        try {
            $share = $this->getById($id, $userId);
            if (!$share) {
                return false;
            }
            
            $stmt = $this->db->prepare("DELETE FROM shares WHERE id = ?");
            $stmt->execute([$id]);
            
            // Update file shared status
            $this->fileClass->updateSharedStatus($share['file_id']);
            
            $this->logger->log(
                $userId ?? $share['created_by'],
                'share_deleted',
                'share',
                $id,
                "Share deleted: {$share['share_name']}"
            );
            
            return true;
        } catch (Exception $e) {
            error_log("Share delete error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Deactivate a share
     */
    public function deactivate($id, $userId = null) {
        try {
            $share = $this->getById($id, $userId);
            
            if (!$share) {
                throw new Exception("Share not found");
            }
            
            $stmt = $this->db->prepare("
                UPDATE shares 
                SET is_active = 0
                WHERE id = ?
            ");
            
            $stmt->execute([$id]);
            
            $this->logger->log(
                $userId,
                'share_deactivate',
                'share',
                $id,
                "Share deactivated: {$share['share_name']}"
            );
            
            return true;
        } catch (Exception $e) {
            error_log("Share deactivate error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete multiple shares
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
            error_log("Share deleteMultiple error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Validate share access
     */
    public function validateAccess($token, $password = null) {
        try {
            $share = $this->getByToken($token);
            
            if (!$share) {
                return ['valid' => false, 'error' => 'Share not found'];
            }
            
            if (!$share['is_active']) {
                return ['valid' => false, 'error' => 'Share is no longer active'];
            }
            
            // Check expiration
            if ($share['expires_at'] && strtotime($share['expires_at']) < time()) {
                return ['valid' => false, 'error' => 'Share has expired'];
            }
            
            // Check download limit
            if ($share['max_downloads'] && $share['download_count'] >= $share['max_downloads']) {
                return ['valid' => false, 'error' => 'Download limit reached'];
            }
            
            // Check password
            if ($share['password']) {
                if (!$password) {
                    return ['valid' => false, 'error' => 'Password required', 'requires_password' => true];
                }
                if (!password_verify($password, $share['password'])) {
                    return ['valid' => false, 'error' => 'Incorrect password', 'requires_password' => true];
                }
            }
            
            return ['valid' => true, 'share' => $share];
        } catch (Exception $e) {
            error_log("Share validateAccess error: " . $e->getMessage());
            return ['valid' => false, 'error' => 'An error occurred'];
        }
    }
    
    /**
     * Download shared file
     */
    public function download($token, $password = null) {
        try {
            $validation = $this->validateAccess($token, $password);
            
            if (!$validation['valid']) {
                return $validation;
            }
            
            $share = $validation['share'];
            
            // Increment download count
            $stmt = $this->db->prepare("UPDATE shares SET download_count = download_count + 1, last_accessed = NOW() WHERE id = ?");
            $stmt->execute([$share['id']]);
            
            // Log access
            $this->logger->logShareAccess($share['id'], 'download');
            
            // Check if limit reached after increment
            if ($share['max_downloads'] && ($share['download_count'] + 1) >= $share['max_downloads']) {
                // Deactivate share
                $stmt = $this->db->prepare("UPDATE shares SET is_active = 0 WHERE id = ?");
                $stmt->execute([$share['id']]);
                
                // Update file shared status
                $this->fileClass->updateSharedStatus($share['file_id']);
            }
            
            // Send file
            if (file_exists($share['file_path'])) {
                header('Content-Type: ' . $share['mime_type']);
                header('Content-Disposition: attachment; filename="' . $share['original_name'] . '"');
                header('Content-Length: ' . $share['file_size']);
                header('Cache-Control: no-cache');
                
                readfile($share['file_path']);
                exit;
            }
            
            return ['valid' => false, 'error' => 'File not found'];
        } catch (Exception $e) {
            error_log("Share download error: " . $e->getMessage());
            return ['valid' => false, 'error' => 'An error occurred'];
        }
    }
    
    /**
     * Generate unique share token
     */
    private function generateToken() {
        do {
            $token = bin2hex(random_bytes(16));
            $stmt = $this->db->prepare("SELECT id FROM shares WHERE share_token = ?");
            $stmt->execute([$token]);
        } while ($stmt->fetch());
        
        return $token;
    }
    
    /**
     * Deactivate expired shares
     */
    public function deactivateExpired() {
        try {
            $stmt = $this->db->prepare("
                UPDATE shares 
                SET is_active = 0 
                WHERE expires_at IS NOT NULL 
                AND expires_at < NOW() 
                AND is_active = 1
            ");
            $stmt->execute();
            
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log("Deactivate expired shares error: " . $e->getMessage());
            return 0;
        }
    }
}
