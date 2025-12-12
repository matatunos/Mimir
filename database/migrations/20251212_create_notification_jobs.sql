-- Migration: create notification_jobs table
CREATE TABLE IF NOT EXISTS notification_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recipient VARCHAR(255) NOT NULL,
  subject VARCHAR(191) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  options JSON DEFAULT NULL,
  actor_id INT DEFAULT NULL,
  target_id INT DEFAULT NULL,
  attempts INT NOT NULL DEFAULT 0,
  max_attempts INT NOT NULL DEFAULT 3,
  last_error TEXT DEFAULT NULL,
  status ENUM('pending','processing','failed','done') NOT NULL DEFAULT 'pending',
  next_run_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
