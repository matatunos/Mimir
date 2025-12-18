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

--
-- Dumping data for table `2fa_attempts`
--

LOCK TABLES `2fa_attempts` WRITE;
/*!40000 ALTER TABLE `2fa_attempts` DISABLE KEYS */;
set autocommit=0;
/*!40000 ALTER TABLE `2fa_attempts` ENABLE KEYS */;
UNLOCK TABLES;
commit;

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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_log`
--

LOCK TABLES `activity_log` WRITE;
/*!40000 ALTER TABLE `activity_log` DISABLE KEYS */;
set autocommit=0;
INSERT INTO `activity_log` VALUES
(1,1,'password_changed','user',1,'Password changed','192.168.1.24','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36',NULL,'2025-12-17 20:57:27'),
(2,1,'login_success','user',1,'Successful login','192.168.1.24','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36',NULL,'2025-12-17 20:57:27'),
(3,1,'user_created','user',3,'User created: nacho','192.168.1.24','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36',NULL,'2025-12-17 21:09:25'),
(4,1,'user_create','user',3,'Usuario creado: nacho','192.168.1.24','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36',NULL,'2025-12-17 21:09:25');
/*!40000 ALTER TABLE `activity_log` ENABLE KEYS */;
UNLOCK TABLES;
commit;

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
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `config`
--

LOCK TABLES `config` WRITE;
/*!40000 ALTER TABLE `config` DISABLE KEYS */;
set autocommit=0;
INSERT INTO `config` VALUES
(1,'site_name','Mimir','string','Site name displayed in header',1,'2025-12-17 20:56:55'),
(2,'site_logo','','string','Path to site logo',0,'2025-12-17 20:56:55'),
(3,'max_file_size','536870912','number','Maximum file size in bytes (512MB)',1,'2025-12-17 20:56:55'),
(4,'allowed_extensions','pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png,gif,zip,rar,7z','string','Comma-separated allowed file extensions',1,'2025-12-17 20:56:55'),
(5,'default_max_share_days','30','number','Default maximum days for sharing',1,'2025-12-17 20:56:55'),
(6,'default_max_downloads','100','number','Default maximum downloads per share',1,'2025-12-17 20:56:55'),
(7,'default_storage_quota','10737418240','number','Default storage quota per user in bytes (10GB)',1,'2025-12-17 20:56:55'),
(8,'enable_registration','0','boolean','Allow user self-registration',1,'2025-12-17 20:56:55'),
(9,'footer_links','[]','json','Footer links as JSON array',0,'2025-12-17 20:56:55'),
(10,'enable_email','1','boolean','Enable email notifications',1,'2025-12-17 21:07:45'),
(11,'smtp_host','smtp.dondominio.com','string','SMTP server host',0,'2025-12-17 21:07:45'),
(12,'smtp_port','587','number','SMTP server port',0,'2025-12-17 20:56:55'),
(13,'smtp_username','noreply@favala.es','string','SMTP username',0,'2025-12-17 21:07:45'),
(14,'smtp_password','Satriani@69.','string','SMTP password',0,'2025-12-17 21:07:45'),
(15,'smtp_encryption','tls','string','SMTP encryption (tls/ssl)',0,'2025-12-17 20:56:55'),
(16,'email_from_address','noreply@favala.es','string','From email address',0,'2025-12-17 21:07:45'),
(17,'email_from_name','Admin Favala','string','From name',0,'2025-12-17 21:07:45'),
(18,'enable_ldap','0','boolean','Enable LDAP authentication',1,'2025-12-17 20:56:55'),
(19,'ldap_host','','string','LDAP server host',0,'2025-12-17 20:56:55'),
(28,'brand_primary_color','#667eea','string',NULL,0,'2025-12-17 21:08:10'),
(29,'brand_secondary_color','#764ba2','string',NULL,0,'2025-12-17 21:08:10'),
(30,'brand_accent_color','#667eea','string',NULL,0,'2025-12-17 21:08:10'),
(31,'storage_uploads_path','/opt/Mimir/storage/uploads','string','Ruta física absoluta en el servidor donde se almacenan los ficheros subidos por los usuarios. Útil para montar un disco diferente (ej.: /mnt/storage/uploads).',0,'2025-12-17 21:08:10'),
(32,'email_signature','','string',NULL,0,'2025-12-17 21:08:10'),
(33,'notify_user_creation_enabled','0','boolean','Si está activado, el sistema enviará notificaciones cuando se cree un usuario vía invitación.',0,'2025-12-17 21:08:10'),
(34,'notify_user_creation_emails','','string','Lista separada por comas de direcciones de correo que recibirán notificaciones cuando se cree un usuario (por ejemplo: ops@example.com, infra@example.com). Déjalo vacío para ningún correo adicional.',0,'2025-12-17 21:08:10'),
(35,'notify_user_creation_to_admins','1','boolean','Si está marcado, además se enviarán notificaciones a todos los usuarios con rol administrador que tengan email configurado.',0,'2025-12-17 21:08:10'),
(36,'notify_user_creation_retry_attempts','3','number','Número máximo de reintentos para enviar notificaciones de creación de usuario antes de registrar un evento forense.',0,'2025-12-17 21:08:10'),
(37,'notify_user_creation_retry_delay_seconds','2','number','Retraso inicial en segundos entre reintentos; se aplica backoff exponencial.',0,'2025-12-17 21:08:10'),
(38,'notify_user_creation_use_background_worker','0','boolean','Si está activado, las notificaciones se encolarán y un worker en background las procesará (recomendado para alta latencia de SMTP).',0,'2025-12-17 21:08:10'),
(39,'ldap_port','389','number',NULL,0,'2025-12-17 21:08:10'),
(40,'ldap_base_dn','','string',NULL,0,'2025-12-17 21:08:10'),
(41,'ldap_bind_dn','','string',NULL,0,'2025-12-17 21:08:10'),
(42,'ldap_bind_password','','string',NULL,0,'2025-12-17 21:08:10'),
(43,'ldap_search_filter','(&(objectClass=inetOrgPerson)(uid=%s))','string',NULL,0,'2025-12-17 21:08:10'),
(44,'ldap_username_attribute','uid','string',NULL,0,'2025-12-17 21:08:10'),
(45,'ldap_email_attribute','mail','string',NULL,0,'2025-12-17 21:08:10'),
(46,'ldap_display_name_attribute','cn','string',NULL,0,'2025-12-17 21:08:10'),
(47,'ldap_required_group_dn','','string',NULL,0,'2025-12-17 21:08:10'),
(48,'ldap_admin_group_dn','','string',NULL,0,'2025-12-17 21:08:10'),
(49,'enable_ad','0','boolean',NULL,0,'2025-12-17 21:08:10'),
(50,'ad_host','','string',NULL,0,'2025-12-17 21:08:10'),
(51,'ad_port','389','number',NULL,0,'2025-12-17 21:08:10'),
(52,'ad_base_dn','','string',NULL,0,'2025-12-17 21:08:10'),
(53,'ad_bind_dn','','string',NULL,0,'2025-12-17 21:08:10'),
(54,'ad_bind_password','','string',NULL,0,'2025-12-17 21:08:10'),
(55,'ad_search_filter','(&(objectClass=user)(sAMAccountName=%s))','string',NULL,0,'2025-12-17 21:08:10'),
(56,'ad_username_attribute','sAMAccountName','string',NULL,0,'2025-12-17 21:08:10'),
(57,'ad_email_attribute','mail','string',NULL,0,'2025-12-17 21:08:10'),
(58,'ad_display_name_attribute','displayName','string',NULL,0,'2025-12-17 21:08:10'),
(59,'ad_require_group','','string',NULL,0,'2025-12-17 21:08:10'),
(60,'ad_group_filter','(&(objectClass=group)(member=%s))','string',NULL,0,'2025-12-17 21:08:10'),
(61,'ad_required_group_dn','','string',NULL,0,'2025-12-17 21:08:10'),
(62,'ad_admin_group_dn','','string',NULL,0,'2025-12-17 21:08:10'),
(63,'enable_duo','0','boolean',NULL,0,'2025-12-17 21:08:10'),
(64,'duo_client_id','','string',NULL,0,'2025-12-17 21:08:10'),
(65,'duo_client_secret','','string',NULL,0,'2025-12-17 21:08:10'),
(66,'duo_api_hostname','','string',NULL,0,'2025-12-17 21:08:10'),
(67,'duo_redirect_uri','','string',NULL,0,'2025-12-17 21:08:10'),
(68,'2fa_max_attempts','3','number',NULL,0,'2025-12-17 21:08:10'),
(69,'2fa_lockout_minutes','15','number',NULL,0,'2025-12-17 21:08:10'),
(70,'2fa_grace_period_hours','24','number',NULL,0,'2025-12-17 21:08:10'),
(71,'2fa_device_trust_days','30','number',NULL,0,'2025-12-17 21:08:10'),
(72,'items_per_page','25','number',NULL,0,'2025-12-17 21:08:10');
/*!40000 ALTER TABLE `config` ENABLE KEYS */;
UNLOCK TABLES;
commit;

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
-- Dumping data for table `download_log`
--

LOCK TABLES `download_log` WRITE;
/*!40000 ALTER TABLE `download_log` DISABLE KEYS */;
set autocommit=0;
/*!40000 ALTER TABLE `download_log` ENABLE KEYS */;
UNLOCK TABLES;
commit;

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
  `is_expired` tinyint(1) NOT NULL DEFAULT 0,
  `never_expire` tinyint(1) NOT NULL DEFAULT 0,
  `expired_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_is_shared` (`is_shared`),
  KEY `idx_is_expired` (`is_expired`),
  KEY `idx_never_expire` (`never_expire`),
  KEY `idx_expired_at` (`expired_at`),
  KEY `idx_file_hash` (`file_hash`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `files_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `files`
--

LOCK TABLES `files` WRITE;
/*!40000 ALTER TABLE `files` DISABLE KEYS */;
set autocommit=0;
/*!40000 ALTER TABLE `files` ENABLE KEYS */;
UNLOCK TABLES;
commit;

--
-- Table structure for table `invitations`
--

DROP TABLE IF EXISTS `invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invitations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(128) NOT NULL,
  `email` varchar(255) NOT NULL,
  `inviter_id` int(11) DEFAULT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'user',
  `message` text DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `forced_username` varchar(100) DEFAULT NULL,
  `force_2fa` varchar(20) NOT NULL DEFAULT 'none',
  `totp_secret` varchar(128) DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `used_by` int(11) DEFAULT NULL,
  `is_revoked` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `email` (`email`),
  KEY `inviter_id` (`inviter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invitations`
--

LOCK TABLES `invitations` WRITE;
/*!40000 ALTER TABLE `invitations` DISABLE KEYS */;
set autocommit=0;
/*!40000 ALTER TABLE `invitations` ENABLE KEYS */;
UNLOCK TABLES;
commit;

--
-- Table structure for table `notification_jobs`
--

DROP TABLE IF EXISTS `notification_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recipient` varchar(255) NOT NULL,
  `subject` varchar(191) NOT NULL,
  `body` mediumtext NOT NULL,
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options`)),
  `actor_id` int(11) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `max_attempts` int(11) NOT NULL DEFAULT 3,
  `last_error` text DEFAULT NULL,
  `status` enum('pending','processing','failed','done') NOT NULL DEFAULT 'pending',
  `next_run_at` datetime DEFAULT current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_jobs`
--

LOCK TABLES `notification_jobs` WRITE;
/*!40000 ALTER TABLE `notification_jobs` DISABLE KEYS */;
set autocommit=0;
/*!40000 ALTER TABLE `notification_jobs` ENABLE KEYS */;
UNLOCK TABLES;
commit;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `security_events`
--

LOCK TABLES `security_events` WRITE;
/*!40000 ALTER TABLE `security_events` DISABLE KEYS */;
set autocommit=0;
/*!40000 ALTER TABLE `security_events` ENABLE KEYS */;
UNLOCK TABLES;
commit;

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
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
set autocommit=0;
INSERT INTO `sessions` VALUES
('ac6b40d51011af2fc0fd60a27d2602ea',1,'192.168.1.24','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','{\"created\":1766005047,\"user_id\":1,\"username\":\"admin\",\"email\":\"admin@doc.favala.es\",\"full_name\":\"Administrator\",\"role\":\"admin\",\"is_ldap\":0}','2025-12-17 20:57:27','2025-12-17 20:57:27');
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;
commit;

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
-- Dumping data for table `share_access_log`
--

LOCK TABLES `share_access_log` WRITE;
/*!40000 ALTER TABLE `share_access_log` DISABLE KEYS */;
set autocommit=0;
/*!40000 ALTER TABLE `share_access_log` ENABLE KEYS */;
UNLOCK TABLES;
commit;

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
-- Dumping data for table `shares`
--

LOCK TABLES `shares` WRITE;
/*!40000 ALTER TABLE `shares` DISABLE KEYS */;
set autocommit=0;
/*!40000 ALTER TABLE `shares` ENABLE KEYS */;
UNLOCK TABLES;
commit;

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
-- Dumping data for table `user_2fa`
--

LOCK TABLES `user_2fa` WRITE;
/*!40000 ALTER TABLE `user_2fa` DISABLE KEYS */;
set autocommit=0;
/*!40000 ALTER TABLE `user_2fa` ENABLE KEYS */;
UNLOCK TABLES;
commit;

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
  `locked_until` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_role` (`role`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_require_2fa` (`require_2fa`),
  KEY `idx_force_password_change` (`force_password_change`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
set autocommit=0;
INSERT INTO `users` VALUES
(1,'admin','admin@doc.favala.es','$2y$12$Izr2MVWgyhXQaTUMpUYdW.5stZPIPTNtdQjr8KbXVy5qEKw4UPI6S','Administrator','admin',1,0,NULL,0,0,0,NULL,'2025-12-17 20:57:27','2025-12-17 20:56:55','2025-12-17 20:57:27'),
(3,'nacho','nacho@favala.es','$2y$12$gI.VXCMBqlZOJb.tmMbY6OXKyLn52jys2vll9//Ps8i2AFVreaa/m','ignacio vargas','admin',1,0,10737418240,0,0,0,NULL,NULL,'2025-12-17 21:09:25','2025-12-17 21:09:25');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
commit;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2025-12-17 21:11:22
