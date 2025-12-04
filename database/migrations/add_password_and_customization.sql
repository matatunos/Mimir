-- Migration: Add password protection to shares and customization options
-- Date: 2025-12-04

-- Add password_hash to public_shares for optional password protection
ALTER TABLE public_shares 
ADD COLUMN password_hash VARCHAR(255) NULL AFTER share_token,
ADD COLUMN requires_password BOOLEAN DEFAULT FALSE AFTER password_hash;

-- Add customization settings to system_config
INSERT INTO system_config (config_key, config_value, config_type, description) VALUES
-- LDAP/Active Directory settings
('ldap_enabled', 'false', 'boolean', 'Enable LDAP/Active Directory authentication'),
('ldap_host', '', 'string', 'LDAP server hostname or IP'),
('ldap_port', '389', 'integer', 'LDAP server port (389 for LDAP, 636 for LDAPS)'),
('ldap_use_tls', 'false', 'boolean', 'Use TLS/SSL for LDAP connection'),
('ldap_base_dn', '', 'string', 'Base DN for LDAP searches (e.g., DC=company,DC=com)'),
('ldap_bind_dn', '', 'string', 'Bind DN for LDAP connection (e.g., CN=admin,DC=company,DC=com)'),
('ldap_bind_password', '', 'string', 'Password for LDAP bind user'),
('ldap_user_filter', '(sAMAccountName=%s)', 'string', 'LDAP filter for user search (%s = username)'),
('ldap_username_attribute', 'sAMAccountName', 'string', 'LDAP attribute for username'),
('ldap_email_attribute', 'mail', 'string', 'LDAP attribute for email'),
('ldap_fullname_attribute', 'displayName', 'string', 'LDAP attribute for full name'),

-- Customization settings
('site_logo', '', 'string', 'URL or path to site logo'),
('site_logo_uploaded', '', 'string', 'Uploaded logo filename'),
('footer_links', '[]', 'json', 'Footer links array (format: [{"label":"Text","url":"https://..."}])'),
('custom_css', '', 'string', 'Custom CSS for branding'),
('enable_password_shares', 'true', 'boolean', 'Allow password protection for shares')
ON DUPLICATE KEY UPDATE config_key = config_key;

-- Create uploads directory for logos if not exists
-- This will be done via PHP in the setup
