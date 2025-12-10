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

/*
 * Email / SMTP configuration examples
 *
 * The application reads SMTP settings from the DB-backed `config` table
 * (keys: smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption,
 *  email_from_address, email_from_name). You can also define them here
 * as constants if you prefer filesystem config.
 *
 * Example: Exchange Online (Microsoft 365) using SMTP AUTH (port 587, STARTTLS)
 * Note: Microsoft recommends using Graph API or OAuth2 for modern auth. If
 * SMTP AUTH is disabled in your tenant, enable it or use an app/password/OAuth.
 *
 * define('SMTP_HOST', 'smtp.office365.com');
 * define('SMTP_PORT', 587);
 * define('SMTP_USERNAME', 'mimir@yourtenant.onmicrosoft.com');
 * define('SMTP_PASSWORD', 'your-app-password-or-credential');
 * define('SMTP_ENCRYPTION', 'tls'); // 'tls' or 'ssl' or empty for none
 * define('EMAIL_FROM_ADDRESS', 'noreply@yourdomain.com');
 * define('EMAIL_FROM_NAME', 'Mimir Files');
 *
 * Example: Exchange On-Premises (authenticated relay) over TLS
 *
 * define('SMTP_HOST', 'exchange.corp.example.com');
 * define('SMTP_PORT', 587);
 * define('SMTP_USERNAME', 'svc-mimir@corp.example.com');
 * define('SMTP_PASSWORD', 'changeme');
 * define('SMTP_ENCRYPTION', 'tls');
 * define('EMAIL_FROM_ADDRESS', 'noreply@corp.example.com');
 * define('EMAIL_FROM_NAME', 'Mimir Files');
 *
 * Example: Exchange On-Premises unauthenticated relay (IP-based)
 * (no username/password required; restrict by IP on the Exchange side)
 *
 * define('SMTP_HOST', 'exchange-relay.corp.example.com');
 * define('SMTP_PORT', 25);
 * define('SMTP_USERNAME', '');
 * define('SMTP_PASSWORD', '');
 * define('SMTP_ENCRYPTION', '');
 * define('EMAIL_FROM_ADDRESS', 'noreply@corp.example.com');
 *
 * Security notes:
 * - `smtp_password` and other secrets are stored in plaintext in the DB by
 *   default; consider encrypting the column or using an external secret store.
 * - Exchange Online may require enabling SMTP AUTH or using OAuth2 / Graph.
 */

// END
