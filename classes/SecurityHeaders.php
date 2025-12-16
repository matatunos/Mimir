<?php
/**
 * Mimir File Management System
 * Security Headers Manager
 * 
 * Implements security-related HTTP headers to protect against common attacks
 */

class SecurityHeaders {
    
    /**
     * Apply all security headers
     */
    public static function applyAll($options = []) {
        self::setContentSecurityPolicy($options['csp'] ?? []);
        self::setXFrameOptions($options['frame'] ?? 'SAMEORIGIN');
        self::setXContentTypeOptions();
        self::setReferrerPolicy($options['referrer'] ?? 'strict-origin-when-cross-origin');
        self::setPermissionsPolicy($options['permissions'] ?? []);
        self::setStrictTransportSecurity($options['hsts'] ?? true);
        self::setXXSSProtection();
    }
    
    /**
     * Content Security Policy
     * Prevents XSS, clickjacking, and other code injection attacks
     */
    public static function setContentSecurityPolicy($options = []) {
        $defaults = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'unsafe-inline'", "https://cdn.jsdelivr.net", "https://cdnjs.cloudflare.com", "https://cdn.tiny.cloud"],
            'style-src' => ["'self'", "'unsafe-inline'", "https://cdn.jsdelivr.net", "https://cdnjs.cloudflare.com", "https://fonts.googleapis.com"],
            'font-src' => ["'self'", "https://fonts.gstatic.com", "https://cdnjs.cloudflare.com"],
            'img-src' => ["'self'", "data:", "https:", "blob:"],
            'connect-src' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'object-src' => ["'none'"],
            'upgrade-insecure-requests' => []
        ];
        
        $config = array_merge($defaults, $options);
        
        $directives = [];
        foreach ($config as $directive => $sources) {
            if (empty($sources)) {
                $directives[] = $directive;
            } else {
                $directives[] = $directive . ' ' . implode(' ', $sources);
            }
        }
        
        header('Content-Security-Policy: ' . implode('; ', $directives));
    }
    
    /**
     * X-Frame-Options
     * Prevents clickjacking attacks
     */
    public static function setXFrameOptions($option = 'SAMEORIGIN') {
        $allowed = ['DENY', 'SAMEORIGIN'];
        
        if (in_array($option, $allowed)) {
            header('X-Frame-Options: ' . $option);
        } else {
            header('X-Frame-Options: SAMEORIGIN');
        }
    }
    
    /**
     * X-Content-Type-Options
     * Prevents MIME-sniffing attacks
     */
    public static function setXContentTypeOptions() {
        header('X-Content-Type-Options: nosniff');
    }
    
    /**
     * Referrer-Policy
     * Controls how much referrer information is shared
     */
    public static function setReferrerPolicy($policy = 'strict-origin-when-cross-origin') {
        $allowed = [
            'no-referrer',
            'no-referrer-when-downgrade',
            'origin',
            'origin-when-cross-origin',
            'same-origin',
            'strict-origin',
            'strict-origin-when-cross-origin',
            'unsafe-url'
        ];
        
        if (in_array($policy, $allowed)) {
            header('Referrer-Policy: ' . $policy);
        }
    }
    
    /**
     * Permissions-Policy (formerly Feature-Policy)
     * Controls browser features and APIs
     */
    public static function setPermissionsPolicy($options = []) {
        $defaults = [
            'geolocation' => ['self'],
            'microphone' => [],
            'camera' => [],
            'payment' => ['self'],
            'usb' => [],
            'magnetometer' => [],
            'accelerometer' => [],
            'gyroscope' => []
        ];
        
        $config = array_merge($defaults, $options);
        
        $directives = [];
        foreach ($config as $feature => $origins) {
            if (empty($origins)) {
                $directives[] = $feature . '=()';
            } else {
                $directives[] = $feature . '=(' . implode(' ', $origins) . ')';
            }
        }
        
        header('Permissions-Policy: ' . implode(', ', $directives));
    }
    
    /**
     * Strict-Transport-Security (HSTS)
     * Forces HTTPS connections
     */
    public static function setStrictTransportSecurity($enabled = true, $maxAge = 31536000) {
        // Only set HSTS if we're on HTTPS
        if ($enabled && isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=' . $maxAge . '; includeSubDomains; preload');
        }
    }
    
    /**
     * X-XSS-Protection
     * Legacy XSS protection (mostly replaced by CSP)
     */
    public static function setXXSSProtection() {
        header('X-XSS-Protection: 1; mode=block');
    }
    
    /**
     * Remove sensitive server information
     */
    public static function removeSensitiveHeaders() {
        header_remove('X-Powered-By');
        header_remove('Server');
    }
    
    /**
     * Set secure download headers
     */
    public static function setDownloadHeaders($filename, $mimeType, $attachment = true) {
        // Prevent MIME sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Set content type
        header('Content-Type: ' . $mimeType);
        
        // Set disposition
        $disposition = $attachment ? 'attachment' : 'inline';
        $safeFilename = self::sanitizeFilenameForHeader($filename);
        header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '"');
        
        // Prevent caching of sensitive files
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    
    /**
     * Sanitize filename for use in Content-Disposition header
     */
    private static function sanitizeFilenameForHeader($filename) {
        // Remove any path components
        $filename = basename($filename);
        
        // Remove quotes and backslashes
        $filename = str_replace(['"', '\\'], '', $filename);
        
        // Encode non-ASCII characters
        if (preg_match('/[^\x20-\x7E]/', $filename)) {
            $filename = rawurlencode($filename);
        }
        
        return $filename;
    }
    
    /**
     * Set CORS headers (if needed)
     */
    public static function setCORSHeaders($allowedOrigins = [], $allowedMethods = ['GET', 'POST']) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (!empty($allowedOrigins) && in_array($origin, $allowedOrigins)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        } else {
            // Don't set CORS by default for security
            // header('Access-Control-Allow-Origin: *');
        }
        
        header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 3600');
    }
    
    /**
     * Set JSON response headers
     */
    public static function setJSONHeaders() {
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
}
