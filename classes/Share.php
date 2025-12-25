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
            // Some installations may not have the optional notification columns yet (migration not applied).
            // Detect columns present in the `shares` table and build the INSERT dynamically to be tolerant.
            $recipientEmail = !empty($options['recipient_email']) ? $options['recipient_email'] : null;
            $recipientMessage = !empty($options['recipient_message']) ? $options['recipient_message'] : null;

            $neededCols = ['file_id', 'share_token', 'share_name'];
            $params = [$fileId, $shareToken, $options['name'] ?? $file['original_name']];

            // Check which optional columns exist
            $checkStmt = $this->db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shares' AND COLUMN_NAME IN ('recipient_email','recipient_message')");
            $checkStmt->execute();
            $existingCols = $checkStmt->fetchAll(PDO::FETCH_COLUMN, 0);

            if (in_array('recipient_email', $existingCols)) {
                $neededCols[] = 'recipient_email';
                $params[] = $recipientEmail;
            }
            if (in_array('recipient_message', $existingCols)) {
                $neededCols[] = 'recipient_message';
                $params[] = $recipientMessage;
            }

            // Add remaining standard columns
            $neededCols = array_merge($neededCols, ['password', 'max_downloads', 'expires_at', 'created_by']);
            $params[] = $password;
            $params[] = $maxDownloads > 0 ? $maxDownloads : null;
            $params[] = $expiresAt;
            $params[] = $userId;

            $placeholders = array_fill(0, count($neededCols), '?');
            $sql = 'INSERT INTO shares (' . implode(', ', $neededCols) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $shareId = $this->db->lastInsertId();
            
            // Update file shared status
            $this->fileClass->updateSharedStatus($fileId);

            // Try to create a public hardlink copy under public/sfiles/ so the file can be referenced directly
            try {
                if (!empty($file['file_path'])) {
                    $destDir = rtrim(constant('BASE_PATH'), '/') . '/public/sfiles';
                    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
                    $absPath = $file['file_path'];
                    if (!preg_match('#^(\/|[A-Za-z]:\\\\)#', $absPath)) {
                        if (defined('UPLOADS_PATH') && UPLOADS_PATH) {
                            $absPath = rtrim(UPLOADS_PATH, '/') . '/' . ltrim($absPath, '/');
                        } else {
                            $absPath = rtrim(constant('BASE_PATH'), '/') . '/' . ltrim($absPath, '/');
                        }
                    }
                    if (file_exists($absPath) && is_readable($absPath) && is_dir($destDir) && is_writable($destDir)) {
                        $ext = pathinfo($absPath, PATHINFO_EXTENSION);
                        $publicName = $shareToken . ($ext ? '.' . $ext : '');
                        $publicPath = $destDir . '/' . $publicName;
                        // Create hard link where possible; fallback to copy if link fails
                        if (!file_exists($publicPath)) {
                            if (!@link($absPath, $publicPath)) {
                                // try copy
                                @copy($absPath, $publicPath);
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // ignore failures creating public link
                error_log('Failed to create public share hardlink: ' . $e->getMessage());
            }
            
            // Log action
            $this->logger->log($userId, 'share_created', 'share', $shareId, "Share created for file: {$file['original_name']}");

            // If a recipient email was provided, send the share link by email
            if (!empty($recipientEmail)) {
                    try {
                    require_once __DIR__ . '/Notification.php';
                    $email = new Notification();
                    $shareUrl = BASE_URL . '/s/' . $shareToken;

                    // Fetch owner info
                    $ownerName = '';
                    $ownerEmail = '';
                    try {
                        $suo = $this->db->prepare('SELECT username, full_name, email FROM users WHERE id = ? LIMIT 1');
                        $suo->execute([$userId]);
                        $ou = $suo->fetch();
                        if ($ou) {
                            $ownerName = $ou['full_name'] ?: $ou['username'];
                            $ownerEmail = $ou['email'];
                        }
                    } catch (Exception $e) {
                        // ignore
                    }

                    // Include site branding and colors if available
                    require_once __DIR__ . '/Config.php';
                    $cfg = new Config();
                    $siteName = $cfg->get('site_name', '');
                    $siteLogo = $cfg->get('site_logo', '');
                    $brandPrimary = $cfg->get('brand_primary_color', '#667eea');
                    $brandAccent = $cfg->get('brand_accent_color', $brandPrimary);
                    $btnColor = $brandAccent;
                    $subject = trim($siteName) ? 'Se ha compartido un archivo — ' . $siteName : 'Se ha compartido un archivo';
                    $siteLogoUrl = '';
                    if (!empty($siteLogo)) {
                        if (preg_match('#^https?://#i', $siteLogo)) {
                            $siteLogoUrl = $siteLogo;
                        } elseif (strpos($siteLogo, '/') === 0) {
                            $siteLogoUrl = $siteLogo;
                        } else {
                            $siteLogoUrl = '/' . ltrim($siteLogo, '/');
                        }
                    }

                    $body = '<div style="font-family: Arial, sans-serif; max-width:600px; margin:0 auto; background:#ffffff; padding:18px; border-radius:8px; box-shadow:0 6px 18px rgba(0,0,0,0.06);">';
                    if ($siteLogoUrl || $siteName) {
                                $body .= '<div style="display:flex; align-items:center; justify-content:center; gap:12px; margin-bottom:12px;">';
                                if ($siteLogoUrl) $body .= '<img src="' . $siteLogoUrl . '" alt="' . htmlspecialchars($siteName ?: 'Site') . '" style="max-height:48px; margin-bottom:0;">';
                                if ($siteName) $body .= '<div style="font-size:14px;color:#333;font-weight:700;">' . htmlspecialchars($siteName) . '</div>';
                                $body .= '</div>';
                    }
                    $body .= '<div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">';
                    $body .= '<div style="width:48px;height:48px;border-radius:24px;background:' . $btnColor . ';display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:18px;">' . strtoupper(substr($ownerName ?: 'S',0,1)) . '</div>';
                    $body .= '<div>';
                    $body .= '<div style="font-size:16px;font-weight:700;color:#222;">' . htmlspecialchars($ownerName ?: 'Un usuario') . '</div>';
                    if (!empty($ownerEmail)) $body .= '<div style="font-size:12px;color:#666;">' . htmlspecialchars($ownerEmail) . '</div>';
                    $body .= '</div></div>';

                    $body .= '<h3 style="margin:0 0 8px 0;color:#222;font-size:18px;">Se ha compartido: ' . htmlspecialchars($file['original_name']) . '</h3>';
                    if (!empty($recipientMessage)) {
                        $body .= '<div style="margin:8px 0 12px 0;color:#444;">' . nl2br(htmlspecialchars($recipientMessage)) . '</div>';
                    }
                    $body .= '<div style="text-align:center;margin:18px 0;">';
                    $body .= '<a href="' . $shareUrl . '" target="_blank" style="display:inline-block;padding:12px 22px;background:' . $btnColor . ';color:#000;text-decoration:none;border-radius:6px;font-weight:700;">Abrir enlace de descarga</a>';
                    $body .= '</div>';
                    $body .= '<div style="font-size:12px;color:#666;word-break:break-all;">Enlace directo: <a href="' . $shareUrl . '" target="_blank">' . $shareUrl . '</a></div>';
                    $body .= '<div style="margin-top:18px;font-size:12px;color:#999;">Si no ha solicitado este correo, ignórelo.</div>';
                    // Append configured email signature if present
                    try {
                        $cfg = new Config();
                        $sig = $cfg->get('email_signature', '');
                        if (!empty($sig)) {
                            $body .= '<div style="margin-top:14px;color:#444;">' . $sig . '</div>';
                        }
                    } catch (Exception $e) {
                        // ignore
                    }
                    $body .= '</div>';

                    // Attempt send; ensure From uses site-configured sender
                    $fromEmailCfg = $cfg->get('email_from_address', '');
                    $fromNameCfg = $cfg->get('email_from_name', '');
                    $email->send($recipientEmail, $subject, $body, ['from_email' => $fromEmailCfg, 'from_name' => $fromNameCfg]);
                    $this->logger->log($userId, 'share_notification_sent', 'share', $shareId, "Share notification sent to {$recipientEmail}");
                } catch (Exception $e) {
                    error_log('Error sending share notification email: ' . $e->getMessage());
                    $this->logger->log($userId, 'share_notification_failed', 'share', $shareId, 'Failed to send share notification: ' . $e->getMessage());
                }
            }

            return [
                'id' => $shareId,
                'token' => $shareToken,
                'url' => BASE_URL . '/s/' . $shareToken
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
            $stmt = $this->db->prepare("\n                SELECT \n                    s.*,\n                    s.id as share_id,\n                    f.id as file_id,\n                    f.original_name,\n                    f.file_size,\n                    f.mime_type,\n                    f.file_path,\n                    u.username as owner_username,\n                    u.full_name as owner_name,\n                    u.email as owner_email\n                FROM shares s\n                JOIN files f ON s.file_id = f.id\n                JOIN users u ON s.created_by = u.id\n                WHERE s.share_token = ?\n            ");
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
    public function getAll($filters = [], $limit = 50, $offset = 0, $sortBy = 'created_at', $sortOrder = 'DESC') {
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
            
            // Whitelist sortable columns and map to SQL expressions
            $allowedSort = [
                'created_at' => 's.created_at',
                'download_count' => 's.download_count',
                'owner_username' => 'u.username',
                'original_name' => 'f.original_name',
                'is_active' => 's.is_active'
            ];

            $sortCol = $allowedSort['created_at'];
            if (!empty($sortBy) && isset($allowedSort[$sortBy])) {
                $sortCol = $allowedSort[$sortBy];
            }
            $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

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
                ORDER BY $sortCol $sortOrder
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

            // Remove public hardlink/copy if present
            try {
                $destDir = rtrim(constant('BASE_PATH'), '/') . '/public/sfiles';
                $ext = pathinfo($share['file_path'] ?? '', PATHINFO_EXTENSION);
                $publicName = $share['share_token'] . ($ext ? '.' . $ext : '');
                $publicPath = $destDir . '/' . $publicName;
                if (file_exists($publicPath)) {
                    @unlink($publicPath);
                }
            } catch (Exception $e) {
                // ignore
            }
            
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
    public function download($token, $password = null, $downloadLogId = null) {
        try {
            $validation = $this->validateAccess($token, $password);
            
            if (!$validation['valid']) {
                return $validation;
            }
            
            $share = $validation['share'];
            
            // Normalize file path: support relative paths stored in DB (e.g., 'uploads/...')
            $filePath = $share['file_path'];
            if (!empty($filePath) && !preg_match('#^(\/|[A-Za-z]:\\\\)#', $filePath)) {
                // prepend UPLOADS_PATH if defined
                if (defined('UPLOADS_PATH') && UPLOADS_PATH) {
                    $filePath = rtrim(UPLOADS_PATH, '/') . '/' . ltrim($filePath, '/');
                } else {
                    // fallback to BASE_PATH/public
                    $filePath = rtrim(constant('BASE_PATH'), '/') . '/' . ltrim($filePath, '/');
                }
            }

            // Ensure file exists before incrementing counters
            if (file_exists($filePath)) {
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
            
                // Send file with chunked streaming so we can track bytes transferred
                
                $fileSize = filesize($filePath);

                // Headers
                // Prevent indexing of this served file by crawlers
                header('X-Robots-Tag: noindex, nofollow');
                header('Content-Type: ' . ($share['mime_type'] ?: 'application/octet-stream'));
                header('Content-Disposition: attachment; filename="' . basename($share['original_name']) . '"');
                header('Content-Length: ' . $fileSize);
                header('Cache-Control: no-cache');

                // Avoid PHP aborting the script when client disconnects
                ignore_user_abort(true);
                set_time_limit(0);

                $bytesSent = 0;
                $chunkSize = 8192;
                $handle = fopen($filePath, 'rb');
                if ($handle === false) {
                    return ['valid' => false, 'error' => 'Unable to open file'];
                }

                while (!feof($handle)) {
                    $buffer = fread($handle, $chunkSize);
                    echo $buffer;
                    flush();
                    $bytesSent += strlen($buffer);
                }
                fclose($handle);

                // Attempt to mark forensic download complete if we have a log id
                if (!empty($downloadLogId)) {
                    try {
                        $forensic = new ForensicLogger();
                        $forensic->completeDownload($downloadLogId, $bytesSent, 200);
                    } catch (Exception $e) {
                        error_log('Error completing forensic download: ' . $e->getMessage());
                    }
                }

                // Send notification to owner if recipient email was set on the share
                if (!empty($share['recipient_email']) && !empty($share['owner_email'])) {
                    require_once __DIR__ . '/Notification.php';
                    require_once __DIR__ . '/Config.php';
                    $cfgNotif = new Config();
                    $siteNameNotif = $cfgNotif->get('site_name', '');
                    $siteLogoNotif = $cfgNotif->get('site_logo', '');
                    $brandPrimaryNotif = $cfgNotif->get('brand_primary_color', '#667eea');
                    $ownerEmail = $share['owner_email'];
                    $recipient = htmlspecialchars($share['recipient_email']);
                    $subject = trim($siteNameNotif) ? 'Notificación: archivo descargado desde su enlace compartido — ' . $siteNameNotif : 'Notificación: archivo descargado desde su enlace compartido';

                    $bodyHtml = '<div style="font-family: Arial, sans-serif; max-width:600px; margin:0 auto;">';
                    // header with logo
                    $siteLogoUrlNotif = '';
                    if (!empty($siteLogoNotif)) {
                        if (preg_match('#^https?://#i', $siteLogoNotif)) { $siteLogoUrlNotif = $siteLogoNotif; }
                        elseif (strpos($siteLogoNotif, '/') === 0) { $siteLogoUrlNotif = $siteLogoNotif; }
                        else { $siteLogoUrlNotif = '/' . ltrim($siteLogoNotif, '/'); }
                    }
                    if ($siteLogoUrlNotif || $siteNameNotif) {
                        $bodyHtml .= '<div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">';
                        if ($siteLogoUrlNotif) $bodyHtml .= '<img src="' . $siteLogoUrlNotif . '" alt="' . htmlspecialchars($siteNameNotif ?: 'Site') . '" style="max-height:40px;">';
                        if ($siteNameNotif) $bodyHtml .= '<div style="font-size:16px;color:' . htmlspecialchars($brandPrimaryNotif) . ';font-weight:700;">' . htmlspecialchars($siteNameNotif) . '</div>';
                        $bodyHtml .= '</div>';
                    }

                    $bodyHtml .= '<h2 style="color:' . htmlspecialchars($brandPrimaryNotif) . ';">Notificación de descarga</h2>';
                    $bodyHtml .= '<p>Estimado/a ' . htmlspecialchars($share['owner_name'] ?? $share['owner_username'] ?? 'Usuario') . ',</p>';
                    $bodyHtml .= '<p>Su archivo <strong>' . htmlspecialchars($share['original_name']) . '</strong> ha sido descargado desde el enlace compartido.</p>';
                    $bodyHtml .= '<h4>Detalles</h4><ul>';
                    $bodyHtml .= '<li><strong>Destinatario previsto:</strong> ' . $recipient . '</li>';
                    if (!empty($share['recipient_message'])) {
                        $bodyHtml .= '<li><strong>Mensaje del remitente:</strong> ' . htmlspecialchars(substr($share['recipient_message'], 0, 500)) . '</li>';
                    }
                    $bodyHtml .= '<li><strong>Tamaño de archivo:</strong> ' . number_format($fileSize) . ' bytes</li>';
                    $bodyHtml .= '<li><strong>Bytes transferidos:</strong> ' . number_format($bytesSent) . ' bytes</li>';
                    $bodyHtml .= '<li><strong>IP del descargador:</strong> ' . htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '</li>';
                    $bodyHtml .= '<li><strong>User-Agent:</strong> ' . htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? '') . '</li>';
                    $bodyHtml .= '</ul>';
                    $bodyHtml .= '<p>Si no esperaba esta descarga, revise su panel.</p>';
                    $bodyHtml .= '<p>Atentamente,<br>' . ($siteNameNotif ? htmlspecialchars($siteNameNotif) : 'El sistema') . '</p>';
                    $bodyHtml .= '</div>';

                    try {
                        $email = new Notification();
                        // Use configured sender for owner notifications as well
                        $cfgNotif = new Config();
                        $fromEmailNotif = $cfgNotif->get('email_from_address', '');
                        $fromNameNotif = $cfgNotif->get('email_from_name', '');
                        $email->send($ownerEmail, $subject, $bodyHtml, ['from_email' => $fromEmailNotif, 'from_name' => $fromNameNotif]);
                    } catch (Exception $e) {
                        error_log('Error sending share-download notification: ' . $e->getMessage());
                    }
                }

                exit;
            }
            
            return ['valid' => false, 'error' => 'File not found'];
        } catch (Exception $e) {
            error_log("Share download error: " . $e->getMessage());
            return ['valid' => false, 'error' => 'An error occurred'];
        }
    }

    /**
     * Stream shared file inline (for embedding images)
     * Similar to download() but uses Content-Disposition: inline and is intended for image previews/embed.
     */
    public function streamInline($token, $password = null) {
        try {
            $validation = $this->validateAccess($token, $password);

            if (!$validation['valid']) {
                // If password required, indicate so to caller
                if (!empty($validation['requires_password'])) {
                    header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
                    echo 'Password required';
                    exit;
                }
                header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
                echo 'Access denied';
                exit;
            }

            $share = $validation['share'];

            $filePath = $share['file_path'];
            if (!empty($filePath) && !preg_match('#^(\/|[A-Za-z]:\\\\)#', $filePath)) {
                if (defined('UPLOADS_PATH') && UPLOADS_PATH) {
                    $filePath = rtrim(UPLOADS_PATH, '/') . '/' . ltrim($filePath, '/');
                } else {
                    $filePath = rtrim(constant('BASE_PATH'), '/') . '/' . ltrim($filePath, '/');
                }
            }

            if (file_exists($filePath)) {
                // Increment access counters (safe for unlimited shares because max_downloads is null)
                $stmt = $this->db->prepare("UPDATE shares SET download_count = download_count + 1, last_accessed = NOW() WHERE id = ?");
                $stmt->execute([$share['id']]);

                $this->logger->logShareAccess($share['id'], 'inline_view');

                // Serve inline with proper mime type
                $fileSize = filesize($filePath);
                header('Content-Type: ' . ($share['mime_type'] ?: 'application/octet-stream'));
                header('Content-Disposition: inline; filename="' . basename($share['original_name']) . '"');
                header('Content-Length: ' . $fileSize);
                header('Cache-Control: public, max-age=604800');

                ignore_user_abort(true);
                set_time_limit(0);

                $chunkSize = 8192;
                $handle = fopen($filePath, 'rb');
                if ($handle === false) {
                    header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
                    echo 'Unable to open file';
                    exit;
                }

                while (!feof($handle)) {
                    echo fread($handle, $chunkSize);
                    flush();
                }
                fclose($handle);
                exit;
            }

            header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
            echo 'File not found';
            exit;
        } catch (Exception $e) {
            error_log("Share streamInline error: " . $e->getMessage());
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
            echo 'An error occurred';
            exit;
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

    /**
     * Resend notification email for a share if recipient_email is set
     * @param int $shareId
     * @param string|null $overrideRecipient optionally override recipient
     * @return bool
     */
    public function resendNotification($shareId, $overrideRecipient = null) {
        try {
            require_once __DIR__ . '/Config.php';
            $stmt = $this->db->prepare("SELECT s.*, f.original_name, u.full_name as owner_name, u.email as owner_email FROM shares s JOIN files f ON s.file_id = f.id JOIN users u ON s.created_by = u.id WHERE s.id = ? LIMIT 1");
            $stmt->execute([$shareId]);
            $share = $stmt->fetch();
            if (!$share) return false;

            $recipient = $overrideRecipient ?: ($share['recipient_email'] ?? null);
            if (empty($recipient)) return false;

            require_once __DIR__ . '/Notification.php';
            $email = new Notification();
            $shareUrl = BASE_URL . '/s/' . ($share['share_token'] ?? '');

            // Branding and colors
            require_once __DIR__ . '/Config.php';
            $cfg = new Config();
            $siteName = $cfg->get('site_name', '');
            $siteLogo = $cfg->get('site_logo', '');
            $brandPrimary = $cfg->get('brand_primary_color', '#667eea');
            $brandAccent = $cfg->get('brand_accent_color', $brandPrimary);
            $btnColor = $brandAccent;
            $subject = trim($siteName) ? 'Se ha compartido un archivo — ' . $siteName : 'Se ha compartido un archivo';

            $ownerName = $share['owner_name'] ?? '';
            $ownerEmail = $share['owner_email'] ?? '';

            $body = '<div style="font-family: Arial, sans-serif; max-width:600px; margin:0 auto; background:#ffffff; padding:18px; border-radius:8px; box-shadow:0 6px 18px rgba(0,0,0,0.06);">';
            // header with logo + site name
            $siteLogoUrl = '';
            if (!empty($siteLogo)) {
                if (preg_match('#^https?://#i', $siteLogo)) { $siteLogoUrl = $siteLogo; }
                elseif (strpos($siteLogo, '/') === 0) { $siteLogoUrl = $siteLogo; }
                else { $siteLogoUrl = '/' . ltrim($siteLogo, '/'); }
            }
            if ($siteLogoUrl || $siteName) {
                $body .= '<div style="display:flex; align-items:center; gap:12px; margin-bottom:12px; justify-content:center;">';
                if ($siteLogoUrl) $body .= '<img src="' . $siteLogoUrl . '" alt="' . htmlspecialchars($siteName ?: 'Site') . '" style="max-height:48px; margin-bottom:0;">';
                if ($siteName) $body .= '<div style="font-size:14px;color:#333;font-weight:700;">' . htmlspecialchars($siteName) . '</div>';
                $body .= '</div>';
            }

            $body .= '<div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">';
            $body .= '<div style="width:48px;height:48px;border-radius:24px;background:' . $btnColor . ';display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:18px;">' . strtoupper(substr($ownerName ?: 'S',0,1)) . '</div>';
            $body .= '<div>';
            $body .= '<div style="font-size:16px;font-weight:700;color:#222;">' . htmlspecialchars($ownerName ?: 'Un usuario') . '</div>';
            if (!empty($ownerEmail)) $body .= '<div style="font-size:12px;color:#666;">' . htmlspecialchars($ownerEmail) . '</div>';
            $body .= '</div></div>';

            $body .= '<h3 style="margin:0 0 8px 0;color:#222;font-size:18px;">Se ha compartido: ' . htmlspecialchars($share['original_name']) . '</h3>';
            if (!empty($share['recipient_message'])) {
                $body .= '<div style="margin:8px 0 12px 0;color:#444;">' . nl2br(htmlspecialchars($share['recipient_message'])) . '</div>';
            }
            $body .= '<div style="text-align:center;margin:18px 0;">';
            $body .= '<a href="' . $shareUrl . '" target="_blank" style="display:inline-block;padding:12px 22px;background:' . $btnColor . ';color:#000;text-decoration:none;border-radius:6px;font-weight:700;">Abrir enlace de descarga</a>';
            $body .= '</div>';
            $body .= '<div style="font-size:12px;color:#666;word-break:break-all;">Enlace directo: <a href="' . $shareUrl . '" target="_blank">' . $shareUrl . '</a></div>';
            $body .= '<div style="margin-top:18px;font-size:12px;color:#999;">Si no ha solicitado este correo, ignórelo.</div>';
            // Append configured email signature if present
            try {
                $cfg = new Config();
                $sig = $cfg->get('email_signature', '');
                if (!empty($sig)) {
                    $body .= '<div style="margin-top:14px;color:#444;">' . $sig . '</div>';
                }
            } catch (Exception $e) {
                // ignore
            }
            $body .= '</div>';

            // Ensure resend emails come from the configured sender address
            $fromEmailCfg = $cfg->get('email_from_address', '');
            $fromNameCfg = $cfg->get('email_from_name', '');
            $ok = $email->send($recipient, $subject, $body, ['from_email' => $fromEmailCfg, 'from_name' => $fromNameCfg]);
            if ($ok) {
                $this->logger->log($share['created_by'], 'share_notification_sent', 'share', $shareId, "Share notification sent to {$recipient}");
                return true;
            } else {
                $this->logger->log($share['created_by'], 'share_notification_failed', 'share', $shareId, "Failed to send share notification to {$recipient}");
                return false;
            }
        } catch (Exception $e) {
            error_log('Resend notification error: ' . $e->getMessage());
            return false;
        }
    }
}
