<?php
/**
 * Mimir File Management System
 * Forensic Logging Class
 * 
 * Comprehensive logging system for security, auditing, and forensic analysis
 */

class ForensicLogger {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Parse user agent string to extract device/browser info
     */
    private function parseUserAgent($userAgent) {
        $result = [
            'browser' => 'Unknown',
            'browser_version' => null,
            'os' => 'Unknown',
            'os_version' => null,
            'device_type' => 'unknown',
            'device_brand' => null,
            'device_model' => null,
            'is_bot' => false,
            'bot_name' => null
        ];
        
        if (empty($userAgent)) {
            return $result;
        }
        
        // Detect bots
        $bots = [
            'Googlebot' => 'Googlebot',
            'bingbot' => 'Bing Bot',
            'Slurp' => 'Yahoo Bot',
            'DuckDuckBot' => 'DuckDuckGo Bot',
            'Baiduspider' => 'Baidu Spider',
            'YandexBot' => 'Yandex Bot',
            'facebot' => 'Facebook Bot',
            'ia_archiver' => 'Alexa Bot',
            'curl' => 'cURL',
            'wget' => 'Wget',
            'python-requests' => 'Python Requests',
            'bot' => 'Generic Bot',
            'spider' => 'Generic Spider',
            'crawler' => 'Generic Crawler'
        ];
        
        foreach ($bots as $pattern => $name) {
            if (stripos($userAgent, $pattern) !== false) {
                $result['is_bot'] = true;
                $result['bot_name'] = $name;
                $result['device_type'] = 'bot';
                return $result;
            }
        }
        
        // Detect mobile/tablet
        if (preg_match('/(android|iphone|ipad|mobile|tablet)/i', $userAgent)) {
            if (stripos($userAgent, 'ipad') !== false || stripos($userAgent, 'tablet') !== false) {
                $result['device_type'] = 'tablet';
            } else {
                $result['device_type'] = 'mobile';
            }
        } else {
            $result['device_type'] = 'desktop';
        }
        
        // Detect browser
        if (preg_match('/Edge\/([0-9.]+)/', $userAgent, $matches)) {
            $result['browser'] = 'Edge';
            $result['browser_version'] = $matches[1];
        } elseif (preg_match('/Edg\/([0-9.]+)/', $userAgent, $matches)) {
            $result['browser'] = 'Edge Chromium';
            $result['browser_version'] = $matches[1];
        } elseif (preg_match('/Chrome\/([0-9.]+)/', $userAgent, $matches)) {
            $result['browser'] = 'Chrome';
            $result['browser_version'] = $matches[1];
        } elseif (preg_match('/Safari\/([0-9.]+)/', $userAgent, $matches)) {
            if (stripos($userAgent, 'Chrome') === false) {
                $result['browser'] = 'Safari';
                $result['browser_version'] = $matches[1];
            }
        } elseif (preg_match('/Firefox\/([0-9.]+)/', $userAgent, $matches)) {
            $result['browser'] = 'Firefox';
            $result['browser_version'] = $matches[1];
        } elseif (preg_match('/MSIE ([0-9.]+)/', $userAgent, $matches)) {
            $result['browser'] = 'Internet Explorer';
            $result['browser_version'] = $matches[1];
        } elseif (preg_match('/Trident\/.*rv:([0-9.]+)/', $userAgent, $matches)) {
            $result['browser'] = 'Internet Explorer';
            $result['browser_version'] = $matches[1];
        } elseif (preg_match('/Opera\/([0-9.]+)/', $userAgent, $matches)) {
            $result['browser'] = 'Opera';
            $result['browser_version'] = $matches[1];
        }
        
        // Detect OS
        if (preg_match('/Windows NT ([0-9.]+)/', $userAgent, $matches)) {
            $result['os'] = 'Windows';
            $result['os_version'] = $this->getWindowsVersion($matches[1]);
        } elseif (preg_match('/Mac OS X ([0-9_]+)/', $userAgent, $matches)) {
            $result['os'] = 'macOS';
            $result['os_version'] = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/Android ([0-9.]+)/', $userAgent, $matches)) {
            $result['os'] = 'Android';
            $result['os_version'] = $matches[1];
        } elseif (preg_match('/iPhone OS ([0-9_]+)/', $userAgent, $matches)) {
            $result['os'] = 'iOS';
            $result['os_version'] = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/iPad.*OS ([0-9_]+)/', $userAgent, $matches)) {
            $result['os'] = 'iPadOS';
            $result['os_version'] = str_replace('_', '.', $matches[1]);
        } elseif (stripos($userAgent, 'Linux') !== false) {
            $result['os'] = 'Linux';
        } elseif (stripos($userAgent, 'Ubuntu') !== false) {
            $result['os'] = 'Ubuntu';
        }
        
        // Detect device brand/model
        if (preg_match('/(iPhone|iPad|iPod)/', $userAgent, $matches)) {
            $result['device_brand'] = 'Apple';
            $result['device_model'] = $matches[1];
        } elseif (preg_match('/Android.*;\s*([^)]+)\s*Build/', $userAgent, $matches)) {
            $result['device_model'] = trim($matches[1]);
            // Try to extract brand
            $parts = explode(' ', $result['device_model']);
            if (count($parts) > 0) {
                $result['device_brand'] = $parts[0];
            }
        }
        
        return $result;
    }
    
    /**
     * Get Windows version from NT version
     */
    private function getWindowsVersion($ntVersion) {
        $versions = [
            '10.0' => '10/11',
            '6.3' => '8.1',
            '6.2' => '8',
            '6.1' => '7',
            '6.0' => 'Vista',
            '5.1' => 'XP'
        ];
        return $versions[$ntVersion] ?? $ntVersion;
    }
    
    /**
     * Get client IP address (handles proxies)
     */
    private function getClientIP() {
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Take first IP if multiple
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
    
    /**
     * Log a download with comprehensive forensic data
     */
    public function logDownload($fileId, $shareId = null, $userId = null) {
        try {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $parsedUA = $this->parseUserAgent($userAgent);
            
            // Get file size
            $stmt = $this->db->prepare("SELECT file_size FROM files WHERE id = ?");
            $stmt->execute([$fileId]);
            $fileSize = $stmt->fetchColumn();
            
            $stmt = $this->db->prepare("
                INSERT INTO download_log (
                    file_id, share_id, user_id, ip_address, user_agent, referer,
                    accept_language, request_method, request_uri,
                    browser, browser_version, os, os_version,
                    device_type, device_brand, device_model,
                    is_bot, bot_name, file_size, download_started_at,
                    session_id, metadata
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, NOW(),
                    ?, ?
                )
            ");
            
            $metadata = json_encode([
                'server_protocol' => $_SERVER['SERVER_PROTOCOL'] ?? null,
                'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? null,
                'connection' => $_SERVER['HTTP_CONNECTION'] ?? null,
                'cache_control' => $_SERVER['HTTP_CACHE_CONTROL'] ?? null
            ]);
            
            $stmt->execute([
                $fileId,
                $shareId,
                $userId,
                $this->getClientIP(),
                $userAgent,
                $_SERVER['HTTP_REFERER'] ?? null,
                $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
                $_SERVER['REQUEST_METHOD'] ?? 'GET',
                $_SERVER['REQUEST_URI'] ?? null,
                $parsedUA['browser'],
                $parsedUA['browser_version'],
                $parsedUA['os'],
                $parsedUA['os_version'],
                $parsedUA['device_type'],
                $parsedUA['device_brand'],
                $parsedUA['device_model'],
                $parsedUA['is_bot'] ? 1 : 0,
                $parsedUA['bot_name'],
                $fileSize,
                session_id(),
                $metadata
            ]);
            
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log("Forensic download log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update download completion status
     */
    public function completeDownload($downloadLogId, $bytesTransferred, $httpStatusCode = 200, $error = null) {
        try {
            $stmt = $this->db->prepare("
                UPDATE download_log 
                SET download_completed_at = NOW(),
                    download_duration = TIMESTAMPDIFF(SECOND, download_started_at, NOW()),
                    bytes_transferred = ?,
                    http_status_code = ?,
                    error_message = ?
                WHERE id = ?
            ");
            $stmt->execute([$bytesTransferred, $httpStatusCode, $error, $downloadLogId]);
            return true;
        } catch (Exception $e) {
            error_log("Complete download log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log share access (view or download)
     */
    public function logShareAccess($shareId, $action = 'view', $fileSize = null) {
        try {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $parsedUA = $this->parseUserAgent($userAgent);
            
            $stmt = $this->db->prepare("
                INSERT INTO share_access_log (
                    share_id, ip_address, user_agent, referer, 
                    accept_language, device_type, browser, os, 
                    is_bot, file_size, action
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $shareId,
                $this->getClientIP(),
                $userAgent,
                $_SERVER['HTTP_REFERER'] ?? null,
                $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
                $parsedUA['device_type'],
                $parsedUA['browser'] . ($parsedUA['browser_version'] ? ' ' . $parsedUA['browser_version'] : ''),
                $parsedUA['os'] . ($parsedUA['os_version'] ? ' ' . $parsedUA['os_version'] : ''),
                $parsedUA['is_bot'] ? 1 : 0,
                $fileSize,
                $action
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("Share access log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log security event
     */
    public function logSecurityEvent($eventType, $severity, $description, $details = null, $userId = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO security_events (
                    event_type, severity, user_id, ip_address, user_agent,
                    description, details
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $eventType,
                $severity,
                $userId,
                $this->getClientIP(),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $description,
                is_array($details) ? json_encode($details) : $details
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("Security event log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get download statistics
     */
    public function getDownloadStats($fileId = null, $days = 30) {
        try {
            $where = "WHERE download_started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $params = [$days];
            
            if ($fileId) {
                $where .= " AND file_id = ?";
                $params[] = $fileId;
            }
            
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_downloads,
                    COUNT(DISTINCT ip_address) as unique_ips,
                    COUNT(DISTINCT user_id) as unique_users,
                    SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) as bot_downloads,
                    SUM(CASE WHEN device_type = 'mobile' THEN 1 ELSE 0 END) as mobile_downloads,
                    SUM(CASE WHEN device_type = 'desktop' THEN 1 ELSE 0 END) as desktop_downloads,
                    SUM(bytes_transferred) as total_bytes,
                    AVG(download_duration) as avg_duration,
                    COUNT(CASE WHEN http_status_code = 200 THEN 1 END) as successful_downloads,
                    COUNT(CASE WHEN error_message IS NOT NULL THEN 1 END) as failed_downloads
                FROM download_log
                $where
            ");
            
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Download stats error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get geographic distribution of downloads
     */
    public function getGeographicDistribution($days = 30) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    country_code,
                    country_name,
                    COUNT(*) as download_count,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM download_log
                WHERE download_started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND country_code IS NOT NULL
                GROUP BY country_code, country_name
                ORDER BY download_count DESC
            ");
            $stmt->execute([$days]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Geographic distribution error: " . $e->getMessage());
            return [];
        }
    }
}
