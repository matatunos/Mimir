/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.8.3-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: mimir
-- ------------------------------------------------------
-- Server version	11.8.3-MariaDB-0+deb13u1 from Debian

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `2fa_attempts`
--

DROP TABLE IF EXISTS `2fa_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `2fa_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `method` enum('totp','duo','backup') NOT NULL,
  `success` tinyint(1) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `error_message` varchar(255) DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`,`attempted_at`),
  KEY `idx_success` (`success`),
  CONSTRAINT `2fa_attempts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Table to track ownership history of files
DROP TABLE IF EXISTS `file_ownership_history`;
CREATE TABLE `file_ownership_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_id` INT NOT NULL,
  `old_user_id` INT DEFAULT NULL,
  `new_user_id` INT DEFAULT NULL,
  `changed_by_user_id` INT DEFAULT NULL,
  `reason` VARCHAR(100) DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `file_id` (`file_id`),
  KEY `old_user_id` (`old_user_id`),
  KEY `new_user_id` (`new_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trigger to capture changes to files.user_id (uses @current_actor_id if set by application)
DROP TRIGGER IF EXISTS `files_owner_change`;
DELIMITER $$
CREATE TRIGGER `files_owner_change`
AFTER UPDATE ON `files`
FOR EACH ROW
BEGIN
  IF NOT (OLD.user_id <=> NEW.user_id) THEN
    INSERT INTO file_ownership_history (file_id, old_user_id, new_user_id, changed_by_user_id, reason, note)
    VALUES (
      OLD.id,
      OLD.user_id,
      NEW.user_id,
      NULLIF(@current_actor_id, 0),
      CASE
        WHEN OLD.user_id IS NULL AND NEW.user_id IS NOT NULL THEN 'assigned'
        WHEN OLD.user_id IS NOT NULL AND NEW.user_id IS NULL THEN 'orphaned'
        ELSE 'reassign'
      END,
      CONCAT('triggered_by_variable=', COALESCE(CAST(@current_actor_id AS CHAR), 'NULL'), '; note=', COALESCE(@current_actor_note, ''))
    );
  END IF;
END$$
DELIMITER ;

--
-- Table structure for table `activity_log`
--

DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL COMMENT 'file, share, user, config, etc.',
  `entity_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `metadata` text DEFAULT NULL COMMENT 'JSON encoded additional data',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_entity_type` (`entity_type`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `config`
--

DROP TABLE IF EXISTS `config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) NOT NULL,
  `config_value` text DEFAULT NULL,
  `config_type` enum('string','number','boolean','json') NOT NULL DEFAULT 'string',
  `description` varchar(255) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'System configs cannot be deleted',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`)
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `download_log`
--

DROP TABLE IF EXISTS `download_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `download_log` (
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
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `isp` varchar(200) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `bytes_transferred` bigint(20) DEFAULT NULL,
  `download_started_at` timestamp NOT NULL,
  `download_completed_at` timestamp NULL DEFAULT NULL,
  `download_duration` int(11) DEFAULT NULL COMMENT 'Duration in seconds',
  `http_status_code` int(11) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `checksum_verified` tinyint(1) DEFAULT NULL,
  `session_id` varchar(128) DEFAULT NULL,
  `metadata` text DEFAULT NULL COMMENT 'Additional JSON data',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `files`
--

DROP TABLE IF EXISTS `files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `files` (
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_expired` tinyint(1) NOT NULL DEFAULT 0,
  `never_expire` tinyint(1) NOT NULL DEFAULT 0,
  `expired_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_is_shared` (`is_shared`),
  KEY `idx_file_hash` (`file_hash`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_is_expired` (`is_expired`),
  KEY `idx_never_expire` (`never_expire`),
  KEY `idx_expired_at` (`expired_at`),
  CONSTRAINT `files_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `security_events`
--

DROP TABLE IF EXISTS `security_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `security_events` (
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_severity` (`severity`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_resolved` (`resolved`),
  KEY `idx_created_at` (`created_at`),
  KEY `security_events_ibfk_2` (`resolved_by`),
  CONSTRAINT `security_events_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `security_events_ibfk_2` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(128) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `data` text DEFAULT NULL,
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_last_activity` (`last_activity`),
  CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `share_access_log`
--

DROP TABLE IF EXISTS `share_access_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `share_access_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `share_id` int(11) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `action` enum('view','download') NOT NULL,
  `accessed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `share_id` (`share_id`),
  KEY `idx_accessed_at` (`accessed_at`),
  CONSTRAINT `share_access_log_ibfk_1` FOREIGN KEY (`share_id`) REFERENCES `shares` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `shares`
--

DROP TABLE IF EXISTS `shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `shares` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) NOT NULL,
  `share_token` varchar(64) NOT NULL,
  `share_name` varchar(255) DEFAULT NULL,
  `recipient_email` varchar(255) DEFAULT NULL,
  `recipient_message` text DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL COMMENT 'Optional password protection',
  `max_downloads` int(11) DEFAULT NULL COMMENT 'Maximum number of downloads, NULL = unlimited',
  `download_count` int(11) NOT NULL DEFAULT 0,
  `expires_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_2fa`
--

DROP TABLE IF EXISTS `user_2fa`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_2fa` (
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_enabled` (`is_enabled`),
  KEY `idx_method` (`method`),
  CONSTRAINT `user_2fa_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
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
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Require user to change password on next login',
  `trusted_devices` text DEFAULT NULL COMMENT 'JSON array of trusted device hashes',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_role` (`role`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_require_2fa` (`require_2fa`),
  KEY `idx_force_password_change` (`force_password_change`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping routines for database 'mimir'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2025-12-15 17:36:51
