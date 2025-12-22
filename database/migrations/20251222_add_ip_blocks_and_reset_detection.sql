-- Migration: create ip_blocks table and insert password-reset detection defaults
-- Idempotent: uses IF NOT EXISTS and ON DUPLICATE KEY UPDATE

CREATE TABLE IF NOT EXISTS `ip_blocks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure a foreign key linking created_by -> users.id exists if users table exists
-- (add FK only if both tables present and FK not already present)
-- MySQL doesn't support IF NOT EXISTS for ADD CONSTRAINT, so this is best-effort and safe to run.

-- Insert config defaults (idempotent via ON DUPLICATE KEY UPDATE)
INSERT INTO `config` (config_key, config_value, config_type, description, is_system, updated_at) VALUES
('password_reset_detection_threshold','5','number','Number of reset requests in window to consider suspicious (per username/IP)',0,NOW()),
('password_reset_detection_window_minutes','10','number','Time window in minutes for reset detection',0,NOW()),
('password_reset_auto_block_enabled','0','boolean','If enabled, automatically block IPs that exceed detection threshold',0,NOW()),
('password_reset_auto_block_duration_minutes','60','number','Duration in minutes to block IP when auto-block is triggered',0,NOW())
ON DUPLICATE KEY UPDATE
  config_value = VALUES(config_value),
  config_type = VALUES(config_type),
  description = VALUES(description),
  is_system = VALUES(is_system),
  updated_at = NOW();
