-- Mimir File Storage System Database Schema
-- MySQL/MariaDB

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE,
    password_hash VARCHAR(255),
    role ENUM('user', 'admin') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    storage_quota BIGINT DEFAULT 1073741824, -- 1GB in bytes
    storage_used BIGINT DEFAULT 0,
    ldap_enabled BOOLEAN DEFAULT FALSE,
    ldap_dn VARCHAR(255),
    twofa_enabled BOOLEAN DEFAULT FALSE,
    twofa_secret VARCHAR(64),
    duo_enabled BOOLEAN DEFAULT FALSE,
    duo_user_id VARCHAR(128),
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    parent_id INT NULL,
    name VARCHAR(255) NOT NULL,
    path TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_parent_id (parent_id),
    INDEX idx_path (path(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    folder_id INT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_path TEXT NOT NULL,
    file_size BIGINT NOT NULL,
    mime_type VARCHAR(100),
    file_hash VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    download_count INT DEFAULT 0,
    is_shared BOOLEAN DEFAULT FALSE,
    shared_link VARCHAR(255),
    author_id INT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_folder_id (folder_id),
    INDEX idx_expires_at (expires_at),
    INDEX idx_file_hash (file_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS public_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    user_id INT NOT NULL,
    share_token VARCHAR(64) UNIQUE NOT NULL,
    password_hash VARCHAR(255),
    share_type ENUM('time', 'downloads') NOT NULL,
    expires_at TIMESTAMP NULL,
    max_downloads INT NULL,
    current_downloads INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    requires_password BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_share_token (share_token),
    INDEX idx_file_id (file_id),
    INDEX idx_expires_at (expires_at),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_entity_type (entity_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS file_downloads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    user_id INT,
    remote_ip VARCHAR(45),
    is_remote BOOLEAN DEFAULT 0,
    downloaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);


CREATE TABLE IF NOT EXISTS system_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) UNIQUE NOT NULL,
    config_value TEXT,
    config_type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_config_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

TRUNCATE TABLE system_config;
INSERT INTO system_config (config_key, config_value, config_type, description) VALUES
('max_file_size', '104857600', 'integer', 'Maximum file size in bytes (100MB default)'),
('default_user_quota', '1073741824', 'integer', 'Default storage quota per user in bytes (1GB)'),
('max_share_time_days', '30', 'integer', 'Maximum days for time-based shares'),
('file_lifetime_days', '0', 'integer', 'Default file lifetime in days (0 = no limit)'),
('enable_email_notifications', 'true', 'boolean', 'Enable email notifications'),
('smtp_host', '', 'string', 'SMTP server host'),
('smtp_port', '587', 'integer', 'SMTP server port'),
('smtp_username', '', 'string', 'SMTP username'),
('smtp_password', '', 'string', 'SMTP password'),
('smtp_from_email', 'noreply@mimir.local', 'string', 'From email address'),
('smtp_from_name', 'Mimir Storage', 'string', 'From name'),
('site_name', 'Mimir', 'string', 'Site name'),
('site_logo', '', 'string', 'Site logo path'),
('footer_links', '[]', 'json', 'Footer links (JSON array)'),
('allow_registration', 'true', 'boolean', 'Allow user registration'),
('ldap_enabled', 'false', 'boolean', 'Enable LDAP/Active Directory authentication'),
('ldap_host', '', 'string', 'LDAP/AD server host'),
('ldap_port', '389', 'integer', 'LDAP/AD server port'),
('ldap_base_dn', '', 'string', 'LDAP/AD base DN'),
('ldap_admin_dn', '', 'string', 'LDAP/AD admin DN'),
('ldap_admin_password', '', 'string', 'LDAP/AD admin password'),
('ldap_user_filter', '', 'string', 'LDAP/AD user filter'),
('twofa_enabled', 'false', 'boolean', 'Enable 2FA globally'),
('duo_enabled', 'false', 'boolean', 'Enable DUO 2FA globally'),
('duo_ikey', '', 'string', 'DUO integration key'),
('duo_skey', '', 'string', 'DUO secret key'),
('duo_host', '', 'string', 'DUO API host'),
('duo_app_key', '', 'string', 'DUO application key');

ALTER TABLE public_shares ADD COLUMN requires_password BOOLEAN DEFAULT FALSE AFTER is_active;

