<?php
/**
 * Example configuration for Mimir (do NOT commit real credentials).
 *
 * Copy this file to `includes/config.php` and fill values for your environment.
 */

// Database configuration (example values)
define('DB_HOST', 'localhost');
define('DB_NAME', 'mimir');
define('DB_USER', 'mimir_user');
define('DB_PASS', 'changeme');
define('DB_CHARSET', 'utf8mb4');

// Paths
define('BASE_PATH', '/opt/Mimir');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('STORAGE_PATH', '/opt/Mimir/storage');
define('UPLOADS_PATH', '/opt/Mimir/storage/uploads');
define('TEMP_PATH', '/opt/Mimir/storage/temp');
define('LOGS_PATH', STORAGE_PATH . '/logs');

// URL configuration
define('BASE_URL', 'https://files.example.com');

// Security
define('SESSION_NAME', 'MIMIR_SESSION');
define('SESSION_LIFETIME', 3600); // 1 hour

// LDAP / Active Directory (example placeholders)
// These values are used by helper scripts and can also be stored in the DB-backed config.
// - Replace with your AD/LDAP host, port and domain
define('LDAP_HOST', 'ad.example.com');
define('LDAP_PORT', 389);
define('LDAP_USE_SSL', false); // set true for LDAPS (636)
define('LDAP_USE_TLS', false); // set true to STARTTLS
define('LDAP_DOMAIN', 'example.com');
define('LDAP_BASE_DN', 'DC=example,DC=com');

// Optional service account for searches (recommended)
define('LDAP_BIND_DN', 'CN=svc-mimir,OU=Service Accounts,DC=example,DC=com');
define('LDAP_BIND_PW', 'changeme');

// File upload defaults
define('MAX_FILE_SIZE', 512 * 1024 * 1024); // 512MB
define('ALLOWED_EXTENSIONS', 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png,gif,zip,rar,7z');

// Share defaults
define('DEFAULT_MAX_SHARE_DAYS', 30);
define('DEFAULT_MAX_DOWNLOADS', 100);

// Timezone
date_default_timezone_set('Europe/Madrid');

// Error reporting (disable in production)
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 1);

// END
