<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Config.php';

use OTPHP\TOTP;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use ParagonIE\ConstantTime\Base32;

class TwoFactor {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Generate a new TOTP secret for a user
     * @return string Base32 encoded secret
     */
    public function generateSecret() {
        return trim(Base32::encodeUpper(random_bytes(20)), '=');
    }
    
    /**
     * Generate TOTP instance for a user
     * @param string $username
     * @param string $secret
     * @return TOTP
     */
    public function getTOTP($username, $secret) {
        $issuer = $this->getIssuerName();
        // Secret is Base32 encoded by generateSecret(); let the library
        // interpret it as Base32 (default) so provisioning URI and verification
        // are consistent with authenticator apps.
        $totp = TOTP::create($secret, 30, 'sha1', 6);
        // Set account label (username) and issuer explicitly so authenticator apps show "ISSUER (account)"
        $totp->setLabel($username);
        $totp->setIssuer($issuer);
        return $totp;
    }
    
    /**
     * Generate QR code for TOTP setup
     * @param string $username
     * @param string $secret
     * @return string Base64 encoded PNG image
     */
    public function generateQRCode($username, $secret) {
        $totp = $this->getTOTP($username, $secret);
        $qrCode = QrCode::create($totp->getProvisioningUri());
        $qrCode->setSize(300);
        $qrCode->setMargin(10);
        
        // Set higher error correction for logo overlay
        $qrCode->setErrorCorrectionLevel(new \Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh());
        
        $writer = new PngWriter();
        
        // Try to read configured site logo via Config; only overlay if the file actually exists
        $cfg = new Config();
        $logoPath = $cfg->get('site_logo', '');
        $result = null;

        if (!empty($logoPath)) {
            // If the configured logo is a URL, skip overlay (logo must be a local file under public)
            if (preg_match('#^https?://#i', $logoPath)) {
                $result = $writer->write($qrCode);
            } else {
                $fullLogoPath = rtrim(BASE_PATH, '/') . '/public/' . ltrim($logoPath, '/');
                if (file_exists($fullLogoPath) && @getimagesize($fullLogoPath)) {
                    try {
                        $logo = \Endroid\QrCode\Logo\Logo::create($fullLogoPath)
                            ->setResizeToWidth(60)
                            ->setPunchoutBackground(true);
                        $result = $writer->write($qrCode, $logo);
                    } catch (Exception $e) {
                        // If logo fails, generate without it
                        $result = $writer->write($qrCode);
                    }
                } else {
                    // Logo path configured but file missing — do not overlay a broken logo
                    $result = $writer->write($qrCode);
                }
            }
        } else {
            $result = $writer->write($qrCode);
        }
        
        return 'data:image/png;base64,' . base64_encode($result->getString());
    }
    
    /**
     * Verify TOTP code
     * @param string $secret
     * @param string $code
     * @param int $window Allowable time drift in 30-second windows
     * @return bool
     */
    public function verifyTOTP($secret, $code, $window = 1) {
        $totp = TOTP::create($secret);
        return $totp->verify($code, null, $window);
    }
    
    /**
     * Generate backup codes
     * @param int $count Number of codes to generate
     * @return array
     */
    public function generateBackupCodes($count = 10) {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = bin2hex(random_bytes(4));
        }
        return $codes;
    }
    
    /**
     * Verify backup code and mark as used
     * @param int $userId
     * @param string $code
     * @return bool
     */
    public function verifyBackupCode($userId, $code) {
        $config = $this->getUserConfig($userId);
        if (!$config || !isset($config['backup_codes'])) {
            return false;
        }
        
        $backupCodes = $config['backup_codes'];
        $codeIndex = array_search($code, $backupCodes);
        
        if ($codeIndex !== false) {
            // Remove used code
            unset($backupCodes[$codeIndex]);
            $backupCodes = array_values($backupCodes); // Re-index
            
            // Update database
            $stmt = $this->db->prepare("UPDATE user_2fa SET backup_codes = ? WHERE user_id = ?");
            $stmt->execute([json_encode($backupCodes), $userId]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Enable 2FA for a user
     * @param int $userId
     * @param string $method 'totp' or 'duo'
     * @param array $config Method-specific configuration
     * @return bool
     */
    public function enable($userId, $method, $config) {
        // Check if user already has 2FA
        $stmt = $this->db->prepare("SELECT id FROM user_2fa WHERE user_id = ?");
        $stmt->execute([$userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update existing
            if ($method === 'totp') {
                $stmt = $this->db->prepare("
                    UPDATE user_2fa 
                    SET method = ?, totp_secret = ?, backup_codes = ?, is_enabled = 1, updated_at = NOW()
                    WHERE user_id = ?
                ");
                $stmt->execute([$method, $config['secret'], json_encode($config['backup_codes']), $userId]);
            } else if ($method === 'duo') {
                $stmt = $this->db->prepare("
                    UPDATE user_2fa 
                    SET method = ?, duo_username = ?, is_enabled = 1, updated_at = NOW()
                    WHERE user_id = ?
                ");
                $stmt->execute([$method, $config['duo_username'], $userId]);
            }
        } else {
            // Insert new
            if ($method === 'totp') {
                $stmt = $this->db->prepare("
                    INSERT INTO user_2fa (user_id, method, totp_secret, backup_codes, is_enabled)
                    VALUES (?, ?, ?, ?, 1)
                ");
                $stmt->execute([$userId, $method, $config['secret'], json_encode($config['backup_codes'])]);
            } else if ($method === 'duo') {
                $stmt = $this->db->prepare("
                    INSERT INTO user_2fa (user_id, method, duo_username, is_enabled)
                    VALUES (?, ?, ?, 1)
                ");
                $stmt->execute([$userId, $method, $config['duo_username']]);
            }
        }
        
        return true;
    }
    
    /**
     * Disable 2FA for a user
     * @param int $userId
     * @return bool
     */
    public function disable($userId) {
        $stmt = $this->db->prepare("UPDATE user_2fa SET is_enabled = 0, updated_at = NOW() WHERE user_id = ?");
        return $stmt->execute([$userId]);
    }
    
    /**
     * Get 2FA configuration for a user
     * @param int $userId
     * @return array|null
     */
    public function getUserConfig($userId) {
        $stmt = $this->db->prepare("
            SELECT id, method, totp_secret, duo_username, backup_codes, is_enabled, created_at, updated_at
            FROM user_2fa
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['backup_codes']) {
            $result['backup_codes'] = json_decode($result['backup_codes'], true);
        }
        
        return $result;
    }

    /**
     * Get Duo username for a user (helper)
     * @param int $userId
     * @return string|null
     */
    public function getDuoUsername($userId) {
        $config = $this->getUserConfig($userId);
        if (!$config) {
            return null;
        }
        return !empty($config['duo_username']) ? $config['duo_username'] : null;
    }
    
    /**
     * Check if user has 2FA enabled
     * @param int $userId
     * @return bool
     */
    public function isEnabled($userId) {
        $stmt = $this->db->prepare("SELECT is_enabled FROM user_2fa WHERE user_id = ? AND is_enabled = 1");
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Check if user is required to use 2FA
     * @param int $userId
     * @return bool
     */
    public function isRequired($userId) {
        $stmt = $this->db->prepare("SELECT require_2fa FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['require_2fa'];
    }
    
    /**
     * Log a 2FA attempt
     * @param int $userId
     * @param string $method
     * @param bool $success
     * @param string $ip
     * @param string $userAgent
     */
    public function logAttempt($userId, $method, $success, $ip, $userAgent) {
        $stmt = $this->db->prepare("
            INSERT INTO 2fa_attempts (user_id, method, success, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $method, $success ? 1 : 0, $ip, $userAgent]);
    }
    
    /**
     * Check if user is locked out due to too many failed attempts
     * @param int $userId
     * @return bool
     */
    public function isLockedOut($userId) {
        $config = $this->getConfig();
        $maxAttempts = $config['max_attempts'];
        $lockoutMinutes = $config['lockout_minutes'];
        
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as failed_count
            FROM 2fa_attempts
            WHERE user_id = ? 
            AND success = 0 
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$userId, $lockoutMinutes]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['failed_count'] >= $maxAttempts;
    }
    
    /**
     * Get 2FA configuration from system settings
     * @return array
     */
    public function getConfig() {
        $stmt = $this->db->query("SELECT config_key, config_value FROM config WHERE config_key LIKE '2fa_%'");
        $config = [
            'totp_issuer' => 'Mimir',
            'grace_period_hours' => 24,
            'device_trust_days' => 30,
            'max_attempts' => 5,
            'lockout_minutes' => 15
        ];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = str_replace('2fa_', '', $row['config_key']);
            $config[$key] = $row['config_value'];
        }
        
        return $config;
    }
    
    /**
     * Get issuer name for TOTP
     * @return string
     */
    private function getIssuerName() {
        // Prefer configuration values via Config class (reads DB/cache). Fallback to 'Mimir'.
        $cfg = new Config();
        $issuer = $cfg->get('totp_issuer_name');
        if (!empty($issuer)) return $issuer;
        $siteName = $cfg->get('site_name');
        if (!empty($siteName)) return $siteName;
        return 'Mimir';
    }
    
    /**
     * Check if device is trusted
     * @param int $userId
     * @param string $deviceHash
     * @return bool
     */
    public function isDeviceTrusted($userId, $deviceHash) {
        $stmt = $this->db->prepare("SELECT trusted_devices FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || !$result['trusted_devices']) {
            return false;
        }
        
        $devices = json_decode($result['trusted_devices'], true);
        if (!is_array($devices)) {
            return false;
        }
        
        $config = $this->getConfig();
        $trustDays = intval($config['device_trust_days']);
        $expirationTime = time() - ($trustDays * 24 * 60 * 60);
        
        foreach ($devices as $device) {
            if ($device['hash'] === $deviceHash && $device['timestamp'] > $expirationTime) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Add a trusted device
     * @param int $userId
     * @param string $deviceHash
     * @return bool
     */
    public function addTrustedDevice($userId, $deviceHash) {
        $stmt = $this->db->prepare("SELECT trusted_devices FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $devices = [];
        if ($result && $result['trusted_devices']) {
            $devices = json_decode($result['trusted_devices'], true);
            if (!is_array($devices)) {
                $devices = [];
            }
        }
        
        // Add new device
        $devices[] = [
            'hash' => $deviceHash,
            'timestamp' => time()
        ];
        
        // Clean expired devices
        $config = $this->getConfig();
        $trustDays = intval($config['device_trust_days']);
        $expirationTime = time() - ($trustDays * 24 * 60 * 60);
        
        $devices = array_filter($devices, function($device) use ($expirationTime) {
            return $device['timestamp'] > $expirationTime;
        });
        $devices = array_values($devices); // Re-index
        
        // Update database
        $stmt = $this->db->prepare("UPDATE users SET trusted_devices = ? WHERE id = ?");
        return $stmt->execute([json_encode($devices), $userId]);
    }
    
    /**
     * Get device hash from request
     * @return string
     */
    public function getDeviceHash() {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return hash('sha256', $userAgent . $ip);
    }
    
    /**
     * Clear lockout for a user (admin function)
     * @param int $userId
     * @return bool
     */
    public function clearLockout($userId) {
        $stmt = $this->db->prepare("DELETE FROM 2fa_attempts WHERE user_id = ? AND success = 0");
        return $stmt->execute([$userId]);
    }
    
    /**
     * Reset 2FA for a user (admin function)
     * @param int $userId
     * @return bool
     */
    public function reset($userId) {
        $stmt = $this->db->prepare("DELETE FROM user_2fa WHERE user_id = ?");
        return $stmt->execute([$userId]);
    }
}
