<?php
/**
 * Mimir File Management System
 * User Management Class
 */

class User {
    private $db;
    private $logger;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->logger = new Logger();
    }
    
    /**
     * Create a new user
     */
    public function create($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO users 
                (username, email, password, full_name, role, is_active, is_ldap, storage_quota) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $password = !empty($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null;
            $role = $data['role'] ?? 'user';
            $isActive = $data['is_active'] ?? 1;
            $isLdap = $data['is_ldap'] ?? 0;
            $storageQuota = $data['storage_quota'] ?? null;
            
            $stmt->execute([
                $data['username'],
                $data['email'],
                $password,
                $data['full_name'] ?? null,
                $role,
                $isActive,
                $isLdap,
                $storageQuota
            ]);
            
            $userId = $this->db->lastInsertId();
            
            $this->logger->log(
                $_SESSION['user_id'] ?? null,
                'user_created',
                'user',
                $userId,
                "User created: {$data['username']}"
            );
            
            return $userId;
        } catch (Exception $e) {
            error_log("User create error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get user by ID
     */
    public function getById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("User getById error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get user by username
     */
    public function getByUsername($username) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("User getByUsername error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all users with filters
     */
    public function getAll($filters = [], $limit = 100, $offset = 0) {
        try {
            $where = [];
            $params = [];
            
            if (!empty($filters['role'])) {
                $where[] = "role = ?";
                $params[] = $filters['role'];
            }
            
            if (isset($filters['is_active'])) {
                $where[] = "is_active = ?";
                $params[] = $filters['is_active'];
            }
            
            if (isset($filters['is_ldap'])) {
                $where[] = "is_ldap = ?";
                $params[] = $filters['is_ldap'];
            }
            
            if (!empty($filters['search'])) {
                $where[] = "(username LIKE ? OR email LIKE ? OR full_name LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $sql = "
                SELECT 
                    users.*,
                    (SELECT COUNT(*) FROM files WHERE user_id = users.id) as file_count,
                    user_2fa.method as twofa_method,
                    user_2fa.is_enabled as twofa_enabled
                FROM users
                LEFT JOIN user_2fa ON users.id = user_2fa.user_id
                $whereClause
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($sql);
            try {
                $stmt->execute($params);
            } catch (PDOException $e) {
                // Duplicate entry or constraint violation
                if ($e->getCode() === '23000') {
                    // Provide a friendly message for unique constraint failures
                    throw new Exception('El nombre de usuario o el email ya están en uso.');
                }
                throw $e;
            }
            
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("User getAll error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get total user count
     */
    public function getCount($filters = []) {
        try {
            $where = [];
            $params = [];
            
            if (!empty($filters['role'])) {
                $where[] = "role = ?";
                $params[] = $filters['role'];
            }
            
            if (isset($filters['is_active'])) {
                $where[] = "is_active = ?";
                $params[] = $filters['is_active'];
            }
            
            if (isset($filters['is_ldap'])) {
                $where[] = "is_ldap = ?";
                $params[] = $filters['is_ldap'];
            }
            
            if (!empty($filters['search'])) {
                $where[] = "(username LIKE ? OR email LIKE ? OR full_name LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM users $whereClause");
            $stmt->execute($params);
            
            $result = $stmt->fetch();
            return $result['total'];
        } catch (Exception $e) {
            error_log("User getCount error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Update user
     */
    public function update($id, $data) {
        try {
            $fields = [];
            $params = [];
            
            if (isset($data['username'])) {
                $fields[] = "username = ?";
                $params[] = $data['username'];
            }
            
            if (isset($data['email'])) {
                $fields[] = "email = ?";
                $params[] = $data['email'];
            }
            
            if (!empty($data['password'])) {
                $fields[] = "password = ?";
                $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            
            if (isset($data['full_name'])) {
                $fields[] = "full_name = ?";
                $params[] = $data['full_name'];
            }
            
            if (isset($data['role'])) {
                $fields[] = "role = ?";
                $params[] = $data['role'];
            }
            
            if (isset($data['is_active'])) {
                $fields[] = "is_active = ?";
                $params[] = $data['is_active'];
            }
            
            if (isset($data['storage_quota'])) {
                $fields[] = "storage_quota = ?";
                $params[] = $data['storage_quota'];
            }
            
            if (empty($fields)) {
                return false;
            }
            
            $params[] = $id;
            
            $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $this->logger->log(
                $_SESSION['user_id'] ?? null,
                'user_updated',
                'user',
                $id,
                "User updated: ID $id"
            );
            
            return true;
        } catch (Exception $e) {
            error_log("User update error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete user
     */
    public function delete($id) {
        try {
            // Don't allow deleting yourself
            if ($id == ($_SESSION['user_id'] ?? 0)) {
                return ['success' => false, 'message' => 'No puedes eliminar tu propio usuario'];
            }
            
            $user = $this->getById($id);
            if (!$user) {
                return false;
            }
            
            // Count files that will become orphans
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM files WHERE user_id = ?");
            $stmt->execute([$id]);
            $fileCount = $stmt->fetch()['count'] ?? 0;

            // Inform DB trigger who performed this action (if available)
            try {
                $s = $this->db->prepare("SET @current_actor_id = ?");
                $s->execute([ $_SESSION['user_id'] ?? null ]);
            } catch (Exception $e) {
                // ignore
            }

            // Delete user (files will become orphans with user_id = NULL due to ON DELETE SET NULL)
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);

            // clear actor variable
            try { $this->db->query("SET @current_actor_id = NULL"); } catch (Exception $e) {}

            $message = "User deleted: {$user['username']}";
            if ($fileCount > 0) {
                $message .= " ($fileCount files now orphaned)";
            }

            $this->logger->log(
                $_SESSION['user_id'] ?? null,
                'user_deleted',
                'user',
                $id,
                $message
            );

            return ['success' => true, 'orphaned_files' => intval($fileCount), 'message' => $message];
        } catch (Exception $e) {
            error_log("User delete error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error interno al eliminar usuario'];
        }
    }
    
    /**
     * Delete multiple users
     */
    public function deleteMultiple($ids) {
        try {
            $this->db->beginTransaction();
            
            $results = ['deleted' => 0, 'errors' => 0, 'details' => []];
            foreach ($ids as $id) {
                // Skip current user
                if ($id == ($_SESSION['user_id'] ?? 0)) {
                    $results['errors']++;
                    $results['details'][] = ['id' => $id, 'success' => false, 'message' => 'No puedes eliminar tu propio usuario'];
                    continue;
                }

                $res = $this->delete($id);
                if (is_array($res) && !empty($res['success'])) {
                    $results['deleted']++;
                    $results['details'][] = ['id' => $id, 'success' => true, 'orphaned_files' => $res['orphaned_files'] ?? 0];
                } else {
                    $results['errors']++;
                    $results['details'][] = ['id' => $id, 'success' => false, 'message' => $res['message'] ?? 'Error al eliminar'];
                }
            }

            $this->db->commit();
            return $results;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("User deleteMultiple error: " . $e->getMessage());
            return ['deleted' => 0, 'errors' => 1, 'message' => 'Error interno al eliminar múltiples usuarios'];
        }
    }
    
    /**
     * Update user storage used
     */
    public function updateStorageUsed($userId, $bytes) {
        try {
            $stmt = $this->db->prepare("UPDATE users SET storage_used = storage_used + ? WHERE id = ?");
            $stmt->execute([$bytes, $userId]);
            return true;
        } catch (Exception $e) {
            error_log("Update storage used error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user has storage available
     */
    public function hasStorageAvailable($userId, $requiredBytes) {
        try {
            $user = $this->getById($userId);
            if (!$user) {
                return false;
            }
            
            // No quota means unlimited
            if ($user['storage_quota'] === null) {
                return true;
            }
            
            return ($user['storage_used'] + $requiredBytes) <= $user['storage_quota'];
        } catch (Exception $e) {
            error_log("Check storage available error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get user statistics
     */
    public function getStatistics($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM files WHERE user_id = ?) as total_files,
                    (SELECT SUM(file_size) FROM files WHERE user_id = ?) as total_size,
                    (SELECT COUNT(DISTINCT file_id) FROM shares WHERE created_by = ? AND is_active = 1) as active_shares
            ");
            $stmt->execute([$userId, $userId, $userId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("User statistics error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get users with most inactivity (supports sorting)
     */
    public function getMostInactiveUsers($limit = 10, $sortBy = 'last_login', $sortDir = 'asc') {
        try {
            // Allow safe sorting by predefined fields
            $allowedSorts = ['last_login', 'days_inactive', 'username', 'file_count'];
            $sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'last_login';
            $sortDir = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';

            // Build ORDER BY clause safely
            switch ($sortBy) {
                case 'username':
                    $orderBy = "u.username $sortDir";
                    break;
                case 'file_count':
                    // use the same subquery for ordering
                    $orderBy = "(SELECT COUNT(*) FROM files WHERE user_id = u.id) $sortDir";
                    break;
                case 'days_inactive':
                    // Place users who never logged in (NULL last_login) first, then by days_inactive
                    $orderBy = "(u.last_login IS NULL) DESC, DATEDIFF(NOW(), u.last_login) $sortDir";
                    break;
                case 'last_login':
                default:
                    // For last_login, control null ordering so 'Nunca' users can appear first when sorting asc
                    if ($sortDir === 'ASC') {
                        $orderBy = "(u.last_login IS NULL) DESC, u.last_login ASC";
                    } else {
                        $orderBy = "(u.last_login IS NULL) ASC, u.last_login DESC";
                    }
                    break;
            }

            $stmt = $this->db->prepare("
                SELECT 
                    u.id,
                    u.username,
                    u.full_name,
                    u.last_login,
                    DATEDIFF(NOW(), u.last_login) as days_inactive,
                    (SELECT COUNT(*) FROM files WHERE user_id = u.id) as file_count
                FROM users u
                WHERE u.role = 'user' 
                    AND u.is_active = 1
                ORDER BY $orderBy
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Get inactive users error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Change password
     */
    public function changePassword($userId, $newPassword) {
        try {
            // Update password and clear any forced-password-change flag
            $stmt = $this->db->prepare("UPDATE users SET password = ?, force_password_change = 0 WHERE id = ? AND is_ldap = 0");
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt->execute([$hashedPassword, $userId]);
            
            $this->logger->log($userId, 'password_changed', 'user', $userId, "Password changed");
            
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("Change password error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Toggle user active status
     */
    public function toggleActive($id) {
        try {
            // Don't allow deactivating yourself
            if ($id == ($_SESSION['user_id'] ?? 0)) {
                return false;
            }
            
            $user = $this->getById($id);
            if (!$user) {
                return false;
            }
            
            $newStatus = !$user['is_active'];
            $stmt = $this->db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            
            $this->logger->log(
                $_SESSION['user_id'] ?? null,
                'user_status_changed',
                'user',
                $id,
                "User {$user['username']} " . ($newStatus ? 'activated' : 'deactivated')
            );
            
            return true;
        } catch (Exception $e) {
            error_log("Toggle active error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Reset user password (admin action)
     */
    public function resetPassword($id, $newPassword = null) {
        try {
            $user = $this->getById($id);
            if (!$user) {
                return false;
            }
            
            if ($user['is_ldap']) {
                return false; // LDAP users can't have password reset
            }
            
            // Generate random password if not provided
            if ($newPassword === null) {
                $newPassword = bin2hex(random_bytes(8));
            }
            
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $id]);
            
            $this->logger->log(
                $_SESSION['user_id'] ?? null,
                'password_reset',
                'user',
                $id,
                "Password reset for user {$user['username']}"
            );
            
            return $newPassword;
        } catch (Exception $e) {
            error_log("Reset password error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get daily uploads for the last 30 days
     */
    public function getDailyUploads($userId, $days = 30) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as count,
                    SUM(file_size) as total_size
                FROM files 
                WHERE user_id = ? 
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ");
            $stmt->execute([$userId, $days]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Daily uploads error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get top uploaded files by count
     */
    public function getTopFilesByCount($userId, $limit = 10) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    original_name,
                    COUNT(*) as upload_count,
                    SUM(file_size) as total_size,
                    MAX(created_at) as last_upload
                FROM files 
                WHERE user_id = ?
                GROUP BY original_name
                ORDER BY upload_count DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Top files by count error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get top files by size
     */
    public function getTopFilesBySize($userId, $limit = 10) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    original_name,
                    file_size,
                    created_at,
                    mime_type
                FROM files 
                WHERE user_id = ?
                ORDER BY file_size DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Top files by size error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get download statistics
     */
    public function getDownloadStats($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(DISTINCT f.id) as files_with_downloads,
                    COUNT(DISTINCT f.id) / (SELECT COUNT(*) FROM files WHERE user_id = ?) * 100 as download_rate,
                    SUM(s.download_count) as total_downloads
                FROM files f
                LEFT JOIN shares s ON f.id = s.file_id
                WHERE f.user_id = ? AND s.download_count > 0
            ");
            $stmt->execute([$userId, $userId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Download stats error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get file type distribution
     */
    public function getFileTypeDistribution($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    CASE 
                        WHEN mime_type LIKE 'image/%' THEN 'Imágenes'
                        WHEN mime_type LIKE 'video/%' THEN 'Vídeos'
                        WHEN mime_type LIKE 'audio/%' THEN 'Audio'
                        WHEN mime_type LIKE 'application/pdf' THEN 'PDF'
                        WHEN mime_type LIKE 'application/zip%' OR mime_type LIKE 'application/x-%' THEN 'Comprimidos'
                        WHEN mime_type LIKE 'text/%' THEN 'Texto'
                        ELSE 'Otros'
                    END as type,
                    COUNT(*) as count,
                    SUM(file_size) as total_size
                FROM files 
                WHERE user_id = ?
                GROUP BY type
                ORDER BY count DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("File type distribution error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get share statistics
     */
    public function getShareStats($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_shares,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_shares,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_shares,
                    SUM(download_count) as total_downloads,
                    AVG(download_count) as avg_downloads_per_share,
                    MAX(download_count) as max_downloads
                FROM shares 
                WHERE created_by = ?
            ");
            $stmt->execute([$userId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Share stats error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get recent activity
     */
    public function getRecentActivity($userId, $limit = 10) {
        try {
            $stmt = $this->db->prepare("
                SELECT action, resource_type, details, created_at
                FROM activity_log 
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Recent activity error: " . $e->getMessage());
            return [];
        }
    }
    
    /* ========================================
       ADMIN STATISTICS (SYSTEM-WIDE)
       ======================================== */
    
    /**
     * Get system-wide daily uploads
     */
    public function getSystemDailyUploads($days = 30) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as count,
                    SUM(file_size) as total_size,
                    COUNT(DISTINCT user_id) as unique_users
                FROM files 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ");
            $stmt->execute([$days]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("System daily uploads error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get system-wide uploads between two dates (inclusive)
     * @param string $from YYYY-MM-DD
     * @param string $to YYYY-MM-DD
     */
    public function getSystemUploadsBetween($from, $to) {
        try {
            $stmt = $this->db->prepare("\n                SELECT\n                    DATE(created_at) as date,\n                    COUNT(*) as count,\n                    SUM(file_size) as total_size,\n                    COUNT(DISTINCT user_id) as unique_users\n                FROM files\n                WHERE DATE(created_at) BETWEEN ? AND ?\n                GROUP BY DATE(created_at)\n                ORDER BY date ASC\n            ");
            $stmt->execute([$from, $to]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("System uploads between error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get system-wide file type distribution
     */
    public function getSystemFileTypeDistribution() {
        try {
            $stmt = $this->db->query("
                SELECT 
                    CASE 
                        WHEN mime_type LIKE 'image/%' THEN 'Imágenes'
                        WHEN mime_type LIKE 'video/%' THEN 'Vídeos'
                        WHEN mime_type LIKE 'audio/%' THEN 'Audio'
                        WHEN mime_type LIKE 'application/pdf' THEN 'PDF'
                        WHEN mime_type LIKE 'application/zip%' OR mime_type LIKE 'application/x-%' THEN 'Comprimidos'
                        WHEN mime_type LIKE 'text/%' THEN 'Texto'
                        ELSE 'Otros'
                    END as type,
                    COUNT(*) as count,
                    SUM(file_size) as total_size
                FROM files 
                GROUP BY type
                ORDER BY count DESC
            ");
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("System file type distribution error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get top users by uploads
     */
    public function getTopUsersByUploads($limit = 10) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    u.username,
                    u.full_name,
                    COUNT(f.id) as file_count,
                    SUM(f.file_size) as total_size
                FROM users u
                LEFT JOIN files f ON u.id = f.user_id
                WHERE u.role = 'user'
                GROUP BY u.id
                ORDER BY file_count DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Top users by uploads error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get system share statistics
     */
    public function getSystemShareStats() {
        try {
            $stmt = $this->db->query("
                SELECT 
                    COUNT(*) as total_shares,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_shares,
                    SUM(download_count) as total_downloads,
                    AVG(download_count) as avg_downloads_per_share,
                    MAX(download_count) as max_downloads,
                    COUNT(DISTINCT created_by) as users_sharing
                FROM shares
            ");
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("System share stats error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get most shared files
     */
    public function getMostSharedFiles($limit = 10) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    f.original_name,
                    f.file_size,
                    u.username,
                    COUNT(s.id) as share_count,
                    SUM(s.download_count) as total_downloads
                FROM files f
                JOIN shares s ON f.id = s.file_id
                JOIN users u ON f.user_id = u.id
                GROUP BY f.id
                ORDER BY share_count DESC, total_downloads DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Most shared files error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get storage usage by user (for quota monitoring)
     */
    public function getStorageUsageByUser() {
        try {
            $stmt = $this->db->query("
                SELECT 
                    u.username,
                    u.full_name,
                    u.storage_quota,
                    COALESCE(SUM(f.file_size), 0) as used_storage,
                    (COALESCE(SUM(f.file_size), 0) / u.storage_quota * 100) as usage_percent
                FROM users u
                LEFT JOIN files f ON u.id = f.user_id
                WHERE u.role = 'user'
                GROUP BY u.id
                ORDER BY usage_percent DESC
                LIMIT 10
            ");
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Storage usage by user error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get system-wide weekly uploads
     */
    public function getSystemWeeklyUploads($weeks = 12) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    YEARWEEK(created_at, 1) as year_week,
                    DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY)) as week_start,
                    COUNT(*) as count,
                    SUM(file_size) as total_size,
                    COUNT(DISTINCT user_id) as unique_users
                FROM files 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? WEEK)
                GROUP BY YEARWEEK(created_at, 1)
                ORDER BY year_week ASC
            ");
            $stmt->execute([$weeks]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("System weekly uploads error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get activity by day of week (0=Monday, 6=Sunday)
     */
    public function getActivityByDayOfWeek($days = 90) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    WEEKDAY(created_at) as day_of_week,
                    CASE WEEKDAY(created_at)
                        WHEN 0 THEN 'Lunes'
                        WHEN 1 THEN 'Martes'
                        WHEN 2 THEN 'Miércoles'
                        WHEN 3 THEN 'Jueves'
                        WHEN 4 THEN 'Viernes'
                        WHEN 5 THEN 'Sábado'
                        WHEN 6 THEN 'Domingo'
                    END as day_name,
                    COUNT(*) as total_files,
                    SUM(file_size) as total_size,
                    COUNT(DISTINCT user_id) as unique_users,
                    AVG(file_size) as avg_file_size
                FROM files 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY WEEKDAY(created_at)
                ORDER BY day_of_week ASC
            ");
            $stmt->execute([$days]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Activity by day of week error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get weekend vs weekday comparison
     */
    public function getWeekendVsWeekdayStats($days = 90) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    CASE 
                        WHEN WEEKDAY(created_at) IN (5, 6) THEN 'Fin de Semana'
                        ELSE 'Entre Semana'
                    END as period_type,
                    COUNT(*) as total_files,
                    SUM(file_size) as total_size,
                    COUNT(DISTINCT user_id) as unique_users,
                    COUNT(DISTINCT DATE(created_at)) as total_days,
                    COUNT(*) / COUNT(DISTINCT DATE(created_at)) as avg_files_per_day
                FROM files 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY CASE 
                    WHEN WEEKDAY(created_at) IN (5, 6) THEN 'Fin de Semana'
                    ELSE 'Entre Semana'
                END
            ");
            $stmt->execute([$days]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Weekend vs weekday stats error: " . $e->getMessage());
            return [];
        }
    }
}
