<?php
/**
 * Public Share Management Class
 */
class ShareManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Create a public share link
     */
    public function createShare($fileId, $userId, $shareType, $value) {
        try {
            // Validate share type
            if (!in_array($shareType, ['time', 'downloads'])) {
                throw new Exception("Invalid share type");
            }

            // Get file
            $fileManager = new FileManager();
            $file = $fileManager->getFile($fileId, $userId);
            if (!$file) {
                throw new Exception("File not found");
            }

            // Generate unique token
            $token = bin2hex(random_bytes(SHARE_TOKEN_LENGTH / 2));

            // Set expiration or max downloads
            $expiresAt = null;
            $maxDownloads = null;

            if ($shareType === 'time') {
                // Validate time limit (max 30 days)
                $maxDays = SystemConfig::get('max_share_time_days', MAX_SHARE_TIME_DAYS_DEFAULT);
                $days = min((int)$value, $maxDays);
                $expiresAt = date('Y-m-d H:i:s', strtotime("+$days days"));
            } else if ($shareType === 'downloads') {
                $maxDownloads = (int)$value;
            }

            // Insert share
            $stmt = $this->db->prepare("INSERT INTO public_shares (file_id, user_id, share_token, share_type, expires_at, max_downloads) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$fileId, $userId, $token, $shareType, $expiresAt, $maxDownloads]);

            $shareId = $this->db->lastInsertId();

            // Log action
            AuditLog::log($userId, 'share_created', 'share', $shareId, "Created $shareType share for file: {$file['original_filename']}");

            // Send notification
            Notification::send($userId, 'share_created', "Share link created for: {$file['original_filename']}");

            return [
                'id' => $shareId,
                'token' => $token,
                'url' => BASE_URL . '/share.php?token=' . $token
            ];
        } catch (Exception $e) {
            error_log("Share creation failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get share by token
     */
    public function getShareByToken($token) {
        $stmt = $this->db->prepare("SELECT s.*, f.* FROM public_shares s 
                                     JOIN files f ON s.file_id = f.id 
                                     WHERE s.share_token = ? AND s.is_active = 1");
        $stmt->execute([$token]);
        return $stmt->fetch();
    }

    /**
     * Check if share is valid
     */
    public function isShareValid($share) {
        if (!$share || !$share['is_active']) {
            return false;
        }

        // Check time expiration
        if ($share['share_type'] === 'time' && $share['expires_at']) {
            if (strtotime($share['expires_at']) < time()) {
                // Deactivate expired share
                $this->deactivateShare($share['id']);
                return false;
            }
        }

        // Check download limit
        if ($share['share_type'] === 'downloads' && $share['max_downloads']) {
            if ($share['current_downloads'] >= $share['max_downloads']) {
                // Deactivate share that reached download limit
                $this->deactivateShare($share['id']);
                return false;
            }
        }

        return true;
    }

    /**
     * Increment share download count
     */
    public function incrementDownloadCount($shareId) {
        $stmt = $this->db->prepare("UPDATE public_shares SET current_downloads = current_downloads + 1 WHERE id = ?");
        return $stmt->execute([$shareId]);
    }

    /**
     * Get user shares
     */
    public function getUserShares($userId) {
        $stmt = $this->db->prepare("SELECT s.*, f.original_filename, f.file_size 
                                     FROM public_shares s 
                                     JOIN files f ON s.file_id = f.id 
                                     WHERE s.user_id = ? 
                                     ORDER BY s.created_at DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Deactivate share
     */
    public function deactivateShare($shareId, $userId = null) {
        if ($userId !== null) {
            $stmt = $this->db->prepare("UPDATE public_shares SET is_active = 0 WHERE id = ? AND user_id = ?");
            $stmt->execute([$shareId, $userId]);
        } else {
            $stmt = $this->db->prepare("UPDATE public_shares SET is_active = 0 WHERE id = ?");
            $stmt->execute([$shareId]);
        }

        // Log action
        AuditLog::log($userId, 'share_deactivated', 'share', $shareId, "Deactivated share");

        return true;
    }

    /**
     * Delete share
     */
    public function deleteShare($shareId, $userId) {
        $stmt = $this->db->prepare("DELETE FROM public_shares WHERE id = ? AND user_id = ?");
        $stmt->execute([$shareId, $userId]);

        // Log action
        AuditLog::log($userId, 'share_deleted', 'share', $shareId, "Deleted share");

        return true;
    }

    /**
     * Clean up expired shares
     */
    public static function cleanupExpiredShares() {
        $db = Database::getInstance()->getConnection();
        
        // Deactivate expired time-based shares
        $stmt = $db->prepare("UPDATE public_shares SET is_active = 0 
                             WHERE share_type = 'time' 
                             AND expires_at IS NOT NULL 
                             AND expires_at < NOW() 
                             AND is_active = 1");
        $stmt->execute();
        $timeExpired = $stmt->rowCount();

        // Deactivate download-limit-reached shares
        $stmt = $db->prepare("UPDATE public_shares SET is_active = 0 
                             WHERE share_type = 'downloads' 
                             AND max_downloads IS NOT NULL 
                             AND current_downloads >= max_downloads 
                             AND is_active = 1");
        $stmt->execute();
        $downloadExpired = $stmt->rowCount();

        // Log cleanup
        if ($timeExpired > 0 || $downloadExpired > 0) {
            AuditLog::log(null, 'shares_cleanup', 'share', null, "Cleaned up $timeExpired time-expired and $downloadExpired download-expired shares");
        }

        return $timeExpired + $downloadExpired;
    }
}
