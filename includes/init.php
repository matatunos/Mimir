<?php
/**
 * Initialization file - include this in all PHP pages
 */

// Include configuration first
require_once __DIR__ . '/../config/config.php';

// Start session - provide sane defaults if config not loaded
if (!defined('SESSION_NAME')) {
    define('SESSION_NAME', 'MIMIR_SESSION');
}
if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 7200);
}
if (!defined('CSRF_TOKEN_NAME')) {
    define('CSRF_TOKEN_NAME', 'csrf_token');
}

session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path' => '/',
    'domain' => (defined('BASE_URL') ? parse_url(BASE_URL, PHP_URL_HOST) : 'localhost'),
    'secure' => false, // true si usas https
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// Include all classes
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/SystemConfig.php';
require_once __DIR__ . '/FileManager.php';
require_once __DIR__ . '/FolderManager.php';
require_once __DIR__ . '/ShareManager.php';
require_once __DIR__ . '/Notification.php';
require_once __DIR__ . '/LdapAuth.php';

/**
 * CSRF Token functions
 * NOTE: CSRF protection is available but not currently enforced in forms.
 * To enable: Add generateCsrfToken() to forms and verify in POST handlers.
 * Example in form: <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
 * Example in handler: if (!verifyCsrfToken($_POST['csrf_token'])) { die('CSRF validation failed'); }
 */
function generateCsrfToken() {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function verifyCsrfToken($token) {
    return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/**
 * Utility functions
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function timeAgo($timestamp) {
    $time = strtotime($timestamp);
    $diff = time() - $time;
    
    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hours ago';
    } elseif ($diff < 2592000) {
        return floor($diff / 86400) . ' days ago';
    } else {
        return date('Y-m-d', $time);
    }
}

function escapeHtml($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
