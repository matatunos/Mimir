<?php
/**
 * Mimir File Management System
 * Security Validator - Centralized security validation and sanitization
 * 
 * Protects against:
 * - SQL Injection (via PDO prepared statements)
 * - XSS (Cross-Site Scripting)
 * - CSRF (Cross-Site Request Forgery)
 * - Path Traversal
 * - Command Injection
 * - File Upload attacks
 * - Rate Limiting bypass
 */

class SecurityValidator {
    
    private static $instance = null;
    private $db;
    
    // Rate limiting cache
    private $rateLimitCache = [];
    
    private function __construct() {
        require_once __DIR__ . '/../includes/database.php';
        $this->db = Database::getInstance()->getConnection();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Sanitize string input
     * Removes dangerous characters and scripts
     */
    public function sanitizeString($input, $allowHTML = false) {
        if ($input === null) {
            return '';
        }
        
        $input = trim($input);
        
        if (!$allowHTML) {
            // Strip all HTML tags
            $input = strip_tags($input);
        } else {
            // Allow only safe HTML tags
            $allowedTags = '<p><br><b><i><u><strong><em><a><ul><ol><li><h1><h2><h3><h4><h5><h6>';
            $input = strip_tags($input, $allowedTags);
            
            // Remove dangerous attributes
            $input = preg_replace('/<([^>]+)(on\w+)=["\']?[^"\']*["\']?([^>]*)>/i', '<$1$3>', $input);
            $input = preg_replace('/<([^>]+)style=["\']?[^"\']*["\']?([^>]*)>/i', '<$1$2>', $input);
            $input = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $input);
        }
        
        return $input;
    }
    
    /**
     * Escape output for HTML display
     */
    public function escapeHTML($input) {
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Validate email address
     */
    public function validateEmail($email) {
        $email = trim($email);
        
        if (empty($email)) {
            return false;
        }
        
        // Basic format check
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        // Check for common patterns used in attacks
        if (preg_match('/[<>"\']/', $email)) {
            return false;
        }
        
        // Check length
        if (strlen($email) > 254) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate URL
     */
    public function validateURL($url, $allowedSchemes = ['http', 'https']) {
        $url = trim($url);
        
        if (empty($url)) {
            return false;
        }
        
        // Basic format check
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Parse URL
        $parsed = parse_url($url);
        
        if ($parsed === false) {
            return false;
        }
        
        // Check scheme
        if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), $allowedSchemes)) {
            return false;
        }
        
        // Block localhost/internal IPs in production
        if (isset($parsed['host'])) {
            $host = strtolower($parsed['host']);
            $blocked = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
            
            if (in_array($host, $blocked)) {
                return false;
            }
            
            // Block private IP ranges
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Validate integer within range
     */
    public function validateInt($input, $min = null, $max = null) {
        if (!is_numeric($input)) {
            return false;
        }
        
        $value = intval($input);
        
        if ($min !== null && $value < $min) {
            return false;
        }
        
        if ($max !== null && $value > $max) {
            return false;
        }
        
        return $value;
    }
    
    /**
     * Validate file path - prevent path traversal
     */
    public function validateFilePath($path, $baseDir) {
        // Normalize paths
        $path = str_replace('\\', '/', $path);
        $baseDir = str_replace('\\', '/', $baseDir);
        
        // Remove multiple slashes
        $path = preg_replace('#/+#', '/', $path);
        $baseDir = preg_replace('#/+#', '/', $baseDir);
        
        // Resolve realpath
        $realPath = realpath($baseDir . '/' . $path);
        $realBase = realpath($baseDir);
        
        if ($realPath === false || $realBase === false) {
            return false;
        }
        
        // Check if path is within base directory
        if (strpos($realPath, $realBase) !== 0) {
            $this->logSecurityEvent('path_traversal_attempt', [
                'path' => $path,
                'baseDir' => $baseDir,
                'realPath' => $realPath
            ]);
            return false;
        }
        
        // Check for dangerous patterns
        $dangerous = ['../', '..\\', '%2e%2e', 'etc/passwd', 'etc/shadow'];
        $lowerPath = strtolower($path);
        
        foreach ($dangerous as $pattern) {
            if (strpos($lowerPath, $pattern) !== false) {
                $this->logSecurityEvent('path_traversal_attempt', [
                    'path' => $path,
                    'pattern' => $pattern
                ]);
                return false;
            }
        }
        
        return $realPath;
    }
    
    /**
     * Validate filename - prevent malicious filenames
     */
    public function validateFilename($filename) {
        // Remove path components
        $filename = basename($filename);
        
        // Check for null bytes
        if (strpos($filename, "\0") !== false) {
            return false;
        }
        
        // Check for dangerous patterns
        $dangerous = ['..', '/', '\\', "\0", '<', '>', ':', '"', '|', '?', '*'];
        
        foreach ($dangerous as $char) {
            if (strpos($filename, $char) !== false) {
                return false;
            }
        }
        
        // Check length
        if (strlen($filename) > 255) {
            return false;
        }
        
        // Must have at least one character before extension
        if (preg_match('/^\./', $filename)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate file extension
     */
    public function validateFileExtension($filename, $allowedExtensions) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (empty($extension)) {
            return false;
        }
        
        // Convert allowed extensions to array
        if (is_string($allowedExtensions)) {
            $allowedExtensions = array_map('trim', explode(',', $allowedExtensions));
        }
        
        // Check for wildcard
        if (in_array('*', $allowedExtensions)) {
            // Check against blocked extensions
            $blocked = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht', 
                       'exe', 'bat', 'cmd', 'com', 'sh', 'bash', 'cgi', 'pl', 'py',
                       'js', 'vbs', 'jar', 'app', 'msi', 'scr'];
            
            if (in_array($extension, $blocked)) {
                return false;
            }
            
            return true;
        }
        
        // Make sure allowed extensions are lowercase
        $allowedExtensions = array_map('strtolower', $allowedExtensions);
        
        return in_array($extension, $allowedExtensions);
    }
    
    /**
     * Validate username
     */
    public function validateUsername($username) {
        $username = trim($username);
        
        // Check length
        if (strlen($username) < 3 || strlen($username) > 50) {
            return false;
        }
        
        // Only allow alphanumeric, underscore, hyphen, dot
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            return false;
        }
        
        // Can't start with dot or hyphen
        if (preg_match('/^[.-]/', $username)) {
            return false;
        }
        
        // Reserved usernames
        $reserved = ['admin', 'root', 'system', 'administrator', 'test', 'guest', 
                     'user', 'public', 'private', 'api', 'www', 'ftp', 'mail'];
        
        if (in_array(strtolower($username), $reserved)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate password strength
     */
    public function validatePassword($password, $minLength = 8) {
        if (strlen($password) < $minLength) {
            return [
                'valid' => false,
                'message' => "La contraseña debe tener al menos {$minLength} caracteres"
            ];
        }
        
        // Check for at least one number
        if (!preg_match('/[0-9]/', $password)) {
            return [
                'valid' => false,
                'message' => 'La contraseña debe contener al menos un número'
            ];
        }
        
        // Check for at least one letter
        if (!preg_match('/[a-zA-Z]/', $password)) {
            return [
                'valid' => false,
                'message' => 'La contraseña debe contener al menos una letra'
            ];
        }
        
        // Check for common weak passwords
        $weakPasswords = ['password', '12345678', 'qwerty', 'abc123', 'letmein', 
                         'welcome', 'monkey', '1234567890', 'password123'];
        
        if (in_array(strtolower($password), $weakPasswords)) {
            return [
                'valid' => false,
                'message' => 'Esta contraseña es demasiado común y fácil de adivinar'
            ];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Rate limiting - check if action is allowed
     */
    public function checkRateLimit($identifier, $maxAttempts = 5, $windowSeconds = 300) {
        $key = $identifier . '_' . $maxAttempts . '_' . $windowSeconds;
        $now = time();
        
        // Clean old entries
        if (isset($this->rateLimitCache[$key])) {
            $this->rateLimitCache[$key] = array_filter(
                $this->rateLimitCache[$key],
                function($timestamp) use ($now, $windowSeconds) {
                    return ($now - $timestamp) < $windowSeconds;
                }
            );
        } else {
            $this->rateLimitCache[$key] = [];
        }
        
        // Check count
        if (count($this->rateLimitCache[$key]) >= $maxAttempts) {
            $this->logSecurityEvent('rate_limit_exceeded', [
                'identifier' => $identifier,
                'attempts' => count($this->rateLimitCache[$key]),
                'max' => $maxAttempts,
                'window' => $windowSeconds
            ]);
            return false;
        }
        
        // Add current attempt
        $this->rateLimitCache[$key][] = $now;
        
        return true;
    }
    
    /**
     * Check if IP is rate limited in database
     */
    public function checkIPRateLimit($ip, $action, $maxAttempts = 5, $windowMinutes = 15) {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as attempts
                FROM security_events
                WHERE ip_address = ?
                AND event_type = ?
                AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            
            $stmt->execute([$ip, $action, $windowMinutes]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['attempts'] >= $maxAttempts) {
                $this->logSecurityEvent('rate_limit', [
                    'ip' => $ip,
                    'action' => $action,
                    'attempts' => $result['attempts']
                ], 'high');
                return false;
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Rate limit check failed: " . $e->getMessage());
            return true; // Fail open to not block legitimate users
        }
    }
    
    /**
     * Log security event
     */
    private function logSecurityEvent($eventType, $details = [], $severity = 'medium') {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO security_events 
                (event_type, severity, ip_address, user_agent, description, details)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $description = $this->getEventDescription($eventType);
            $detailsJson = json_encode($details);
            
            $stmt->execute([
                $eventType,
                $severity,
                $ip,
                $userAgent,
                $description,
                $detailsJson
            ]);
        } catch (Exception $e) {
            error_log("Failed to log security event: " . $e->getMessage());
        }
    }
    
    /**
     * Get human-readable description for event type
     */
    private function getEventDescription($eventType) {
        $descriptions = [
            'path_traversal_attempt' => 'Intento de path traversal detectado',
            'rate_limit_exceeded' => 'Límite de intentos excedido',
            'rate_limit' => 'Rate limit aplicado',
            'failed_login' => 'Intento de login fallido',
            'brute_force' => 'Ataque de fuerza bruta detectado',
            'suspicious_download' => 'Descarga sospechosa detectada',
            'unauthorized_access' => 'Intento de acceso no autorizado',
            'data_breach_attempt' => 'Intento de fuga de datos',
            'malware_upload' => 'Intento de subida de malware',
            'invalid_file_extension' => 'Extensión de archivo no válida',
            'invalid_mime_type' => 'Tipo MIME no válido',
            'file_too_large' => 'Archivo demasiado grande',
            'sql_injection_attempt' => 'Posible intento de SQL injection',
            'xss_attempt' => 'Posible intento de XSS'
        ];
        
        return $descriptions[$eventType] ?? 'Evento de seguridad: ' . $eventType;
    }
    
    /**
     * Validate and sanitize array input
     */
    public function sanitizeArray($input, $expectedKeys = null) {
        if (!is_array($input)) {
            return [];
        }
        
        $sanitized = [];
        
        foreach ($input as $key => $value) {
            // Sanitize key
            $key = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
            
            // Check if key is expected
            if ($expectedKeys !== null && !in_array($key, $expectedKeys)) {
                continue;
            }
            
            // Sanitize value
            if (is_string($value)) {
                $sanitized[$key] = $this->sanitizeString($value);
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value);
            } elseif (is_numeric($value)) {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Generate secure random token
     */
    public function generateToken($length = 32) {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Check if request has valid CSRF token (delegates to Auth)
     */
    public function validateCSRFToken($token) {
        require_once __DIR__ . '/../includes/auth.php';
        $auth = new Auth();
        return $auth->validateCsrfToken($token);
    }
    
    /**
     * Sanitize SQL ORDER BY clause
     */
    public function sanitizeOrderBy($column, $allowedColumns) {
        $column = trim($column);
        
        // Remove any SQL keywords
        $column = preg_replace('/\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|WHERE|FROM)\b/i', '', $column);
        
        // Check if column is in allowed list
        if (!in_array($column, $allowedColumns)) {
            return $allowedColumns[0]; // Return first allowed column as default
        }
        
        return $column;
    }
    
    /**
     * Sanitize SQL direction (ASC/DESC)
     */
    public function sanitizeDirection($direction) {
        $direction = strtoupper(trim($direction));
        return in_array($direction, ['ASC', 'DESC']) ? $direction : 'DESC';
    }
    
    /**
     * Validate IP address
     */
    public function validateIP($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    
    /**
     * Detect potential SQL injection
     */
    public function detectSQLInjection($input) {
        $patterns = [
            '/(\s|^)(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|UNION|EXEC|EXECUTE)\s/i',
            '/--/',
            '/;/',
            '/\/\*.*\*\//',
            '/\bOR\b.*=.*=/i',
            '/\bAND\b.*=.*=/i',
            '/\'.*OR.*\'/i',
            '/\".*OR.*\"/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                $this->logSecurityEvent('sql_injection_attempt', [
                    'input' => substr($input, 0, 100),
                    'pattern' => $pattern
                ], 'critical');
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detect potential XSS
     */
    public function detectXSS($input) {
        $patterns = [
            '/<script[\s>]/i',
            '/javascript:/i',
            '/on\w+\s*=/i', // onclick, onload, etc.
            '/<iframe[\s>]/i',
            '/<object[\s>]/i',
            '/<embed[\s>]/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                $this->logSecurityEvent('xss_attempt', [
                    'input' => substr($input, 0, 100),
                    'pattern' => $pattern
                ], 'high');
                return true;
            }
        }
        
        return false;
    }
}
