<?php
/**
 * Mimir File Storage System - Configuration File Example
 * Copy this file to config.php and update with your settings
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'mimir');
define('DB_USER', 'mimir_user');
define('DB_PASS', 'your_secure_password_here');
define('DB_CHARSET', 'utf8mb4');

// Application Settings
define('APP_NAME', 'Mimir');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'http://localhost'); // Change to your domain

// File Storage Settings
define('UPLOAD_DIR', __DIR__ . '/../uploads/files/');
define('TEMP_DIR', __DIR__ . '/../uploads/temp/');
define('LOG_DIR', __DIR__ . '/../logs/');

// Security Settings
define('SESSION_NAME', 'MIMIR_SESSION');
define('SESSION_LIFETIME', 7200); // 2 hours in seconds
define('CSRF_TOKEN_NAME', 'csrf_token');

// File Settings
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', '7z', 'mp3', 'mp4', 'avi', 'mov']);
define('MAX_FILE_SIZE_DEFAULT', 104857600); // 100MB in bytes

// Share Settings
define('MAX_SHARE_TIME_DAYS_DEFAULT', 30);
define('SHARE_TOKEN_LENGTH', 32);

// Timezone
date_default_timezone_set('UTC');

// Error Reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create required directories if they don't exist
$dirs = [UPLOAD_DIR, TEMP_DIR, LOG_DIR];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            error_log("Failed to create directory: $dir");
            die("Error: Could not create required directory: $dir. Please create it manually and ensure proper permissions.");
        }
    }
}
