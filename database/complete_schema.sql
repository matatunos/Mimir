-- Mimir File Management System - Complete Database Schema
-- Includes all tables, indices, and initial data
-- MySQL Database Schema

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_ldap` tinyint(1) NOT NULL DEFAULT 0,
  `storage_quota` bigint(20) DEFAULT NULL COMMENT 'Storage quota in bytes, NULL = unlimited',
  `storage_used` bigint(20) NOT NULL DEFAULT 0,
  `require_2fa` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Admin can force 2FA for this user',
  `trusted_devices` text DEFAULT NULL COMMENT 'JSON array of trusted device hashes',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_require_2fa` (`require_2fa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `user_2fa`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `user_2fa` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `method` enum('none','totp','duo') NOT NULL DEFAULT 'none',
  `totp_secret` varchar(255) DEFAULT NULL COMMENT 'Encrypted TOTP secret',
  `duo_username` varchar(100) DEFAULT NULL,
  `backup_codes` text DEFAULT NULL COMMENT 'JSON array of hashed backup codes',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `enabled_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `grace_period_until` datetime DEFAULT NULL COMMENT 'Allow disable without code until this time',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_enabled` (`is_enabled`),
  KEY `idx_method` (`method`),
  CONSTRAINT `user_2fa_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `2fa_attempts`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `2fa_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `method` enum('totp','duo','backup') NOT NULL,
  `success` tinyint(1) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `error_message` varchar(255) DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`,`attempted_at`),
  KEY `idx_success` (`success`),
  CONSTRAINT `2fa_attempts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `files`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL COMMENT 'NULL for orphaned files',
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint(20) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_hash` varchar(64) DEFAULT NULL COMMENT 'SHA256 hash for deduplication',
  `description` text DEFAULT NULL,
  `is_shared` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_is_shared` (`is_shared`),
  KEY `idx_file_hash` (`file_hash`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `files_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `shares`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `shares` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) NOT NULL,
  `share_token` varchar(64) NOT NULL,
  `share_name` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL COMMENT 'Optional password protection',
  `max_downloads` int(11) DEFAULT NULL COMMENT 'Maximum number of downloads, NULL = unlimited',
  `download_count` int(11) NOT NULL DEFAULT 0,
  `expires_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_accessed` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `share_token` (`share_token`),
  KEY `file_id` (`file_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `shares_ibfk_1` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shares_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `share_access_log`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `share_access_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `share_id` int(11) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `action` enum('view','download') NOT NULL,
  `accessed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `share_id` (`share_id`),
  KEY `idx_accessed_at` (`accessed_at`),
  CONSTRAINT `share_access_log_ibfk_1` FOREIGN KEY (`share_id`) REFERENCES `shares` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `activity_log`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL COMMENT 'file, share, user, config, etc.',
  `entity_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `metadata` text DEFAULT NULL COMMENT 'JSON encoded additional data',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_entity_type` (`entity_type`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `sessions`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` varchar(128) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `data` text DEFAULT NULL,
  `last_activity` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_last_activity` (`last_activity`),
  CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `config`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) NOT NULL,
  `config_value` text DEFAULT NULL,
  `config_type` enum('string','number','boolean','json') NOT NULL DEFAULT 'string',
  `description` varchar(255) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'System configs cannot be deleted',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Insert default configuration values
-- --------------------------------------------------------

INSERT INTO `config` (`config_key`, `config_value`, `config_type`, `description`, `is_system`) VALUES
('site_name', 'Mimir', 'string', 'Site name displayed in header', 1),
('site_logo', '', 'string', 'Path to site logo', 0),
('max_file_size', '536870912', 'number', 'Maximum file size in bytes (512MB)', 1),
('allowed_extensions', 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png,gif,zip,rar,7z', 'string', 'Comma-separated allowed file extensions', 1),
('default_max_share_days', '30', 'number', 'Default maximum days for sharing', 1),
('default_max_downloads', '100', 'number', 'Default maximum downloads per share', 1),
('default_storage_quota', '10737418240', 'number', 'Default storage quota per user in bytes (10GB)', 1),
('enable_registration', '0', 'boolean', 'Allow user self-registration', 1),
('footer_links', '[]', 'json', 'Footer links as JSON array', 0),
-- Email settings
('enable_email', '0', 'boolean', 'Enable email notifications', 1),
('smtp_host', '', 'string', 'SMTP server host', 0),
('smtp_port', '587', 'number', 'SMTP server port', 0),
('smtp_username', '', 'string', 'SMTP username', 0),
('smtp_password', '', 'string', 'SMTP password', 0),
('smtp_encryption', 'tls', 'string', 'SMTP encryption (tls/ssl)', 0),
('email_from_address', '', 'string', 'From email address', 0),
('email_from_name', 'Mimir', 'string', 'From name', 0),
-- LDAP settings (OpenLDAP)
('enable_ldap', '0', 'boolean', 'Enable LDAP authentication', 1),
('ldap_host', '', 'string', 'LDAP server host', 0),
('ldap_port', '389', 'number', 'LDAP server port', 0),
('ldap_base_dn', '', 'string', 'LDAP base DN', 0),
('ldap_bind_dn', '', 'string', 'LDAP bind DN', 0),
('ldap_bind_password', '', 'string', 'LDAP bind password', 0),
('ldap_search_filter', '(&(objectClass=inetOrgPerson)(uid=%s))', 'string', 'LDAP search filter', 0),
('ldap_username_attribute', 'uid', 'string', 'LDAP username attribute', 0),
('ldap_email_attribute', 'mail', 'string', 'LDAP email attribute', 0),
('ldap_display_name_attribute', 'cn', 'string', 'LDAP display name attribute', 0),
-- Active Directory settings
('enable_ad', '0', 'boolean', 'Enable Active Directory authentication', 0),
('ad_host', '', 'string', 'Active Directory server host', 0),
('ad_port', '389', 'string', 'Active Directory port', 0),
('ad_base_dn', '', 'string', 'Active Directory base DN', 0),
('ad_bind_dn', '', 'string', 'Active Directory bind DN', 0),
('ad_bind_password', '', 'string', 'Active Directory bind password', 0),
('ad_search_filter', '(&(objectClass=user)(sAMAccountName=%s))', 'string', 'Active Directory search filter', 0),
('ad_username_attribute', 'sAMAccountName', 'string', 'Active Directory username attribute', 0),
('ad_email_attribute', 'mail', 'string', 'Active Directory email attribute', 0),
('ad_display_name_attribute', 'displayName', 'string', 'Active Directory display name attribute', 0),
('ad_require_group', '', 'string', 'Active Directory required group DN', 0),
('ad_group_filter', '(&(objectClass=group)(member=%s))', 'string', 'Active Directory group filter', 0),
('ad_use_ssl', '0', 'boolean', 'Use LDAPS (SSL/TLS)', 0),
('ad_use_tls', '0', 'boolean', 'Use StartTLS', 0),
-- 2FA settings
('enable_duo', '0', 'boolean', 'Enable Duo Security 2FA', 1),
('duo_client_id', '', 'string', 'Duo Client ID', 0),
('duo_client_secret', '', 'string', 'Duo Client Secret', 0),
('duo_api_hostname', '', 'string', 'Duo API Hostname', 0),
('duo_redirect_uri', '', 'string', 'Duo Redirect URI', 0),
('2fa_max_attempts', '3', 'number', 'Maximum 2FA attempts before lockout', 1),
('2fa_lockout_minutes', '15', 'number', 'Lockout duration in minutes', 1),
('2fa_grace_period_hours', '24', 'number', 'Grace period for new 2FA setup', 1),
('2fa_device_trust_days', '30', 'number', 'Device trust duration in days', 1),
-- Branding
('brand_primary_color', '#667eea', 'string', 'Primary brand color', 0),
('brand_secondary_color', '#764ba2', 'string', 'Secondary brand color', 0),
('brand_accent_color', '#667eea', 'string', 'Accent brand color', 0);

-- --------------------------------------------------------
-- Insert default admin user
-- Username: admin
-- Password: admin123 (CHANGE THIS AFTER FIRST LOGIN!)
-- --------------------------------------------------------

INSERT INTO `users` (`username`, `email`, `password`, `full_name`, `role`, `is_active`, `is_ldap`) VALUES
('admin', 'admin@mimir.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin', 1, 0);

-- Note: Default password is 'admin123' - Change it after first login!

-- --------------------------------------------------------
-- Table structure for table `download_log`
-- Comprehensive forensic tracking for all downloads
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `download_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) NOT NULL,
  `share_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'User if authenticated',
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `referer` varchar(500) DEFAULT NULL,
  `accept_language` varchar(100) DEFAULT NULL,
  `request_method` varchar(10) DEFAULT NULL,
  `request_uri` varchar(500) DEFAULT NULL,
  `browser` varchar(100) DEFAULT NULL,
  `browser_version` varchar(50) DEFAULT NULL,
  `os` varchar(100) DEFAULT NULL,
  `os_version` varchar(50) DEFAULT NULL,
  `device_type` enum('desktop','mobile','tablet','bot','unknown') DEFAULT 'unknown',
  `device_brand` varchar(100) DEFAULT NULL,
  `device_model` varchar(100) DEFAULT NULL,
  `is_bot` tinyint(1) DEFAULT 0,
  `bot_name` varchar(100) DEFAULT NULL,
  `country_code` varchar(2) DEFAULT NULL,
  `country_name` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `latitude` decimal(10, 8) DEFAULT NULL,
  `longitude` decimal(11, 8) DEFAULT NULL,
  `isp` varchar(200) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `bytes_transferred` bigint(20) DEFAULT NULL,
  `download_started_at` timestamp NOT NULL,
  `download_completed_at` timestamp NULL DEFAULT NULL,
  `download_duration` int DEFAULT NULL COMMENT 'Duration in seconds',
  `http_status_code` int DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `checksum_verified` tinyint(1) DEFAULT NULL,
  `session_id` varchar(128) DEFAULT NULL,
  `metadata` text DEFAULT NULL COMMENT 'Additional JSON data',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `file_id` (`file_id`),
  KEY `share_id` (`share_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_country_code` (`country_code`),
  KEY `idx_device_type` (`device_type`),
  KEY `idx_is_bot` (`is_bot`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_download_started` (`download_started_at`),
  CONSTRAINT `download_log_ibfk_1` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `download_log_ibfk_2` FOREIGN KEY (`share_id`) REFERENCES `shares` (`id`) ON DELETE SET NULL,
  CONSTRAINT `download_log_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `security_events`
-- Track security incidents and suspicious activity
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `security_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `event_type` enum('failed_login','brute_force','suspicious_download','rate_limit','unauthorized_access','data_breach_attempt','malware_upload') NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `description` text NOT NULL,
  `details` text DEFAULT NULL COMMENT 'JSON encoded details',
  `action_taken` varchar(255) DEFAULT NULL,
  `resolved` tinyint(1) DEFAULT 0,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_severity` (`severity`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_resolved` (`resolved`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `security_events_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `security_events_ibfk_2` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
