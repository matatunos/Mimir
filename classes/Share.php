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

            // Try to create a public anonymized copy under public/sfiles/ so the file can be referenced directly
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
                        if (!file_exists($publicPath)) {
                            // If image, create a sanitized copy (strip EXIF/metadata) by re-encoding
                            $mime = strtolower($file['mime_type'] ?? '');
                            $isImage = strpos($mime, 'image/') === 0 || in_array(strtolower($ext), ['jpg','jpeg','png','gif']);
                            if ($isImage) {
                                // attempt to sanitize via GD
                                try {
                                    switch ($mime) {
                                        case 'image/jpeg':
                                        case 'image/jpg':
                                            $img = @imagecreatefromjpeg($absPath);
                                            if ($img !== false) {
                                                imagejpeg($img, $publicPath, 85);
                                                imagedestroy($img);
                                            } else {
                                                @copy($absPath, $publicPath);
                                            }
                                            break;
                                        case 'image/png':
                                            $img = @imagecreatefrompng($absPath);
                                            if ($img !== false) {
                                                imagealphablending($img, false);
                                                imagesavealpha($img, true);
                                                // PNG compression level 6
                                                imagepng($img, $publicPath, 6);
                                                imagedestroy($img);
                                            } else {
                                                @copy($absPath, $publicPath);
                                            }
                                            break;
                                        case 'image/gif':
                                            $img = @imagecreatefromgif($absPath);
                                            if ($img !== false) {
                                                imagegif($img, $publicPath);
                                                imagedestroy($img);
                                            } else {
                                                @copy($absPath, $publicPath);
                                            }
                                            break;
                                        default:
                                            // Unknown image mime; fallback to copy
                                            @copy($absPath, $publicPath);
                                            break;
                                    }
                                    // ensure permissions and update mtime to now
                                    @chmod($publicPath, 0644);
                                    @touch($publicPath, time());
                                } catch (Exception $e) {
                                    // fallback to simple copy
                                    @copy($absPath, $publicPath);
                                }
                            } else {
                                // Non-image: try hard link for space efficiency, fallback to copy
                                if (!@link($absPath, $publicPath)) {
                                    @copy($absPath, $publicPath);
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // ignore failures creating public copy
                error_log('Failed to create public share file: ' . $e->getMessage());
            }
            // If the shared item is a folder, attempt to pre-generate a public ZIP in public/sfiles/<token>.zip
            $zip_creation_failed = false;
            try {
                if (!empty($file['is_folder'])) {
                    $destDir = rtrim(constant('BASE_PATH'), '/') . '/public/sfiles';
                    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
                    $zipName = $shareToken . '.zip';
                    $publicZipPath = $destDir . '/' . $zipName;
                    // Only create if not already present
                    if (!file_exists($publicZipPath)) {
                        // use createZipFromFolder to write to public path
                        $created = $this->createZipFromFolder($fileId, $userId, $publicZipPath);
                        if ($created && file_exists($publicZipPath)) {
                            @chmod($publicZipPath, 0644);
                        } else {
                            error_log('Failed to pre-create ZIP for folder share id=' . $shareId);
                            $zip_creation_failed = true;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('Error creating public ZIP for folder share: ' . $e->getMessage());
                $zip_creation_failed = true;
            }
            
            // Determine share type for logging (gallery vs download)
            $shareType = 'download';
            $mime = strtolower($file['mime_type'] ?? '');
            $noExpiry = empty($expiresAt);
            $unlimited = ($maxDownloads === null || $maxDownloads === 0);
            if ($noExpiry && $unlimited && strpos($mime, 'image/') === 0) {
                $shareType = 'gallery';
            }

            // Log action with differentiated event
            if ($shareType === 'gallery') {
                $this->logger->log($userId, 'gallery_published', 'share', $shareId, "Gallery published for file: {$file['original_name']}");
            } else {
                $this->logger->log($userId, 'share_created', 'share', $shareId, "Share created for file: {$file['original_name']}");
            }

            // If a recipient email was provided, send the share link by email
            // For folder shares, only send the email if the ZIP was successfully pre-created
            if (!empty($recipientEmail) && (empty($file['is_folder']) || (!$zip_creation_failed))) {
                    try {
                    require_once __DIR__ . '/Notification.php';
                    $email = new Notification();
                    $shareUrl = BASE_URL . '/s/' . $shareToken;
                    $shareUrlDownload = $shareUrl . '?download=1';

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

                    $body .= "<h3 style=\"margin:0 0 8px 0;color:#222;font-size:18px;\">Se ha compartido: " . htmlspecialchars($file['original_name']) . "</h3>";
                    if (!empty($recipientMessage)) {
                        $body .= '<div style="margin:8px 0 12px 0;color:#444;">' . nl2br(htmlspecialchars($recipientMessage)) . '</div>';
                    }
                    // If folder share and public zip exists, include download size
                    if (!empty($file['is_folder']) && !empty($publicZipPath) && file_exists($publicZipPath)) {
                        $sizeMb = number_format(filesize($publicZipPath) / 1024 / 1024, 2);
                        $body .= '<div style="margin:6px 0 12px 0;color:#555;font-size:13px;">Tamaño aproximado de la descarga: ' . $sizeMb . ' MB</div>';
                    }
                    $body .= '<div style="text-align:center;margin:18px 0;">';
                    $body .= '<a href="' . $shareUrl . '" target="_blank" style="display:inline-block;padding:12px 22px;background:' . $btnColor . ';color:#000;text-decoration:none;border-radius:6px;font-weight:700;">Abrir enlace de descarga</a>';
                    if (!empty($file['is_folder'])) {
                        $body .= '&nbsp;&nbsp;';
                        $body .= '<a href="' . $shareUrlDownload . '" target="_blank" style="display:inline-block;padding:12px 22px;background:#6b8b3b;color:#000;text-decoration:none;border-radius:6px;font-weight:700;">Descarga directa (ZIP)</a>';
                    }
                    $body .= '</div>';
                    $body .= '<div style="font-size:12px;color:#666;word-break:break-all;">Enlace directo: <a href="' . $shareUrl . '" target="_blank">' . $shareUrl . '</a>';
                    if (!empty($file['is_folder'])) {
                        $body .= ' — Descarga ZIP: <a href="' . $shareUrlDownload . '" target="_blank">' . $shareUrlDownload . '</a>';
                    }
                    $body .= '</div>';
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
     * Find an existing gallery-style share for a file created by the given user.
     * Gallery share criteria: image MIME, no expiry, unlimited downloads, active.
     */
    public function findGalleryShare($fileId, $userId) {
        try {
            $sql = "
                SELECT 
                    s.*, s.id as share_id, s.share_token as token,
                    f.original_name, f.file_path, f.mime_type
                FROM shares s
                JOIN files f ON s.file_id = f.id
                WHERE s.file_id = ? AND s.created_by = ? AND s.is_active = 1
                  AND (s.expires_at IS NULL OR s.expires_at = '')
                  AND (s.max_downloads IS NULL OR s.max_downloads = 0)
                  AND (f.mime_type LIKE 'image/%')
                ORDER BY s.created_at DESC
                LIMIT 1
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$fileId, $userId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('findGalleryShare error: ' . $e->getMessage());
            return null;
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
            // Soft-delete: mark as inactive instead of removing DB row so recovery is possible
            try {
                $stmt = $this->db->prepare("UPDATE shares SET is_active = 0 WHERE id = ?");
                $stmt->execute([$id]);
            } catch (Exception $e) {
                error_log('Share soft-delete failed: ' . $e->getMessage());
                return false;
            }

            // Update file shared status (keep public artifacts on disk for recovery)
            $this->fileClass->updateSharedStatus($share['file_id']);

            // Log a deactivation event rather than a hard delete
            $this->logger->log(
                $userId ?? $share['created_by'],
                'share_deactivate',
                'share',
                $id,
                "Share deactivated (soft-delete): {$share['share_name']}"
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
     * Permanently remove a share and associated public artifacts.
     * Use with caution; this will DELETE the DB row.
     */
    public function purge($id, $userId = null) {
        try {
            $share = $this->getById($id, $userId);
            if (!$share) {
                return false;
            }

            // Remove public artifacts if present
            try {
                $destDir = rtrim(constant('BASE_PATH'), '/') . '/public/sfiles';
                // remove any public copy named by token + extension
                $ext = pathinfo($share['file_path'] ?? '', PATHINFO_EXTENSION);
                $publicName = ($share['share_token'] ?? '') . ($ext ? '.' . $ext : '');
                $publicPath = $destDir . '/' . $publicName;
                if (file_exists($publicPath)) {
                    @unlink($publicPath);
                }
                // remove token.zip for folder shares
                $zipPath = $destDir . '/' . ($share['share_token'] ?? '') . '.zip';
                if (file_exists($zipPath)) {
                    @unlink($zipPath);
                }
            } catch (Exception $e) {
                // continue even if file cleanup fails
                error_log('Share purge: failed to remove public artifacts: ' . $e->getMessage());
            }

            // Permanently delete DB row
            $stmt = $this->db->prepare("DELETE FROM shares WHERE id = ?");
            $stmt->execute([$id]);

            // Update file shared status
            $this->fileClass->updateSharedStatus($share['file_id']);

            // Log permanent deletion
            $this->logger->log(
                $userId ?? $share['created_by'],
                'share_deleted',
                'share',
                $id,
                "Share permanently deleted: {$share['share_name']}"
            );

            return true;
        } catch (Exception $e) {
            error_log('Share purge error: ' . $e->getMessage());
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

            // If this share points to a folder, attempt to stream an existing public ZIP or create one on-demand
            if (!empty($share['is_folder']) && (int)$share['is_folder'] === 1) {
                $destDir = rtrim(constant('BASE_PATH'), '/') . '/public/sfiles';
                $token = $share['share_token'] ?? ($share['token'] ?? null);
                $publicZipPath = $token ? ($destDir . '/' . $token . '.zip') : null;

                $zipPath = null;
                $shouldCleanup = false;
                // prefer existing public ZIP
                if ($publicZipPath && file_exists($publicZipPath)) {
                    $zipPath = $publicZipPath;
                } else {
                    // build zip in temp file and stream
                    $zipPath = $this->createZipFromFolder($share['file_id'], $share['user_id'] ?? $share['created_by'] ?? null);
                    $shouldCleanup = true;
                }

                if ($zipPath && file_exists($zipPath)) {
                    // Increment download count and log access
                    $stmt = $this->db->prepare("UPDATE shares SET download_count = download_count + 1, last_accessed = NOW() WHERE id = ?");
                    $stmt->execute([$share['id']]);
                    $this->logger->logShareAccess($share['id'], 'download');
                    // Increment download count and log access
                    $stmt = $this->db->prepare("UPDATE shares SET download_count = download_count + 1, last_accessed = NOW() WHERE id = ?");
                    $stmt->execute([$share['id']]);
                    $this->logger->logShareAccess($share['id'], 'download');

                    $fileSize = filesize($zipPath);
                    header('X-Robots-Tag: noindex, nofollow');
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_\-\.]/','_', ($share['original_name'] ?: 'folder')) . '.zip"');
                    header('Content-Length: ' . $fileSize);
                    header('Cache-Control: no-cache');

                    ignore_user_abort(true);
                    set_time_limit(0);

                    $chunkSize = 8192;
                    $handle = fopen($zipPath, 'rb');
                    if ($handle !== false) {
                        while (!feof($handle)) {
                            echo fread($handle, $chunkSize);
                            flush();
                        }
                        fclose($handle);
                    }

                    // cleanup temporary zip if we created it on-demand
                    if ($shouldCleanup) @unlink($zipPath);

                    // complete forensic log if present
                    if (!empty($downloadLogId)) {
                        try {
                            $forensic = new ForensicLogger();
                            $forensic->completeDownload($downloadLogId, $fileSize, 200);
                        } catch (Exception $e) {
                            error_log('Error completing forensic download (folder zip): ' . $e->getMessage());
                        }
                    }

                    exit;
                }
                return ['valid' => false, 'error' => 'Unable to package folder'];
            }

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

    /**
     * Create a ZIP file from a folder (recursively) and return the temporary zip path.
     * Returns false on failure.
     */
    private function createZipFromFolder($folderId, $ownerId = null, $outPath = null) {
        try {
            $folder = $this->fileClass->getById($folderId);
            if (!$folder || empty($folder['is_folder'])) return false;
            // If ownerId was not provided, derive it from the folder record so on-demand zips
            // created by anonymous/public flows can locate the folder contents correctly.
            if (empty($ownerId) && !empty($folder['user_id'])) {
                $ownerId = $folder['user_id'];
            }

            $baseName = preg_replace('/[^A-Za-z0-9_\-]/', '_', ($folder['original_name'] ?: 'folder'));
            $tmp = null;
            if (empty($outPath)) {
                $tmp = tempnam(sys_get_temp_dir(), 'mimir_zip_');
                if ($tmp === false) return false;
                $zipPath = $tmp . '.zip';
            } else {
                $zipPath = $outPath;
                // ensure directory exists
                $dir = dirname($zipPath);
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
                // cleanup
                if ($tmp) @unlink($tmp);
                // Log failure to create zip for diagnostics
                try {
                    $this->logger->log(null, 'zip_creation_failed', 'folder', $folderId, 'Failed to open zip archive at ' . $zipPath);
                } catch (Throwable $e) { /* best-effort */ }
                try {
                    $forensic = new ForensicLogger();
                    $forensic->logSecurityEvent('zip_creation_failed', 'medium', 'ZIP creation failed for folder', ['folder_id' => $folderId, 'path' => $zipPath], $ownerId ?? null);
                } catch (Throwable $e) { /* best-effort */ }

                return false;
            }

            // recursive add
            $this->addFolderToZip($zip, $folderId, $ownerId, $baseName);

            $zip->close();

            // remove tempname placeholder if present
            if ($tmp) @unlink($tmp);
            return $zipPath;
        } catch (Exception $e) {
            error_log('createZipFromFolder error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Recursively add folder contents to a ZipArchive instance.
     */
    private function addFolderToZip($zip, $folderId, $ownerId, $prefix = '') {
        try {
            $items = $this->fileClass->getFolderContents($ownerId, $folderId, true);
            foreach ($items as $item) {
                $nameSafe = $item['original_name'] ?? ('item_' . $item['id']);
                $localPath = $prefix !== '' ? rtrim($prefix, '/') . '/' . $nameSafe : $nameSafe;
                if (!empty($item['is_folder'])) {
                    // add folder entry (zip folders should end with /)
                    $zip->addEmptyDir($localPath);
                    // recurse
                    $this->addFolderToZip($zip, $item['id'], $ownerId, $localPath);
                } else {
                    // resolve absolute path similar to download code
                    $filePath = $item['file_path'] ?? '';
                    if (!empty($filePath) && !preg_match('#^(\/|[A-Za-z]:\\\\)#', $filePath)) {
                        if (defined('UPLOADS_PATH') && UPLOADS_PATH) {
                            $filePath = rtrim(UPLOADS_PATH, '/') . '/' . ltrim($filePath, '/');
                        } else {
                            $filePath = rtrim(constant('BASE_PATH'), '/') . '/' . ltrim($filePath, '/');
                        }
                    }
                    if (!empty($filePath) && file_exists($filePath) && is_readable($filePath)) {
                        // add file under localPath
                        $zip->addFile($filePath, $localPath);
                    } else {
                        // skip missing files but log
                        $msg = "addFolderToZip: skipping missing file id={$item['id']} path={$filePath}";
                        error_log($msg);
                        try {
                            $this->logger->log(null, 'zip_missing_file', 'file', $item['id'], $msg);
                        } catch (Throwable $e) { /* best-effort */ }
                        try {
                            $forensic = new ForensicLogger();
                            $forensic->logSecurityEvent('zip_missing_file', 'low', 'File missing while creating ZIP', ['file_id' => $item['id'], 'path' => $filePath], $ownerId ?? null);
                        } catch (Throwable $e) { /* best-effort */ }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('addFolderToZip error: ' . $e->getMessage());
        }
    }
}
