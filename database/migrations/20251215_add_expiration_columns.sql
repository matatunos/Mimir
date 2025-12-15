-- Migration: add expiration columns to `files` table (idempotent)
-- Date: 2025-12-15

-- This migration will add `is_expired`, `never_expire`, and `expired_at`
-- columns plus indexes only if `is_expired` does not already exist.

SET @cnt := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'files'
                 AND COLUMN_NAME = 'is_expired');

SET @sql = IF(@cnt = 0,
    'ALTER TABLE `files` 
       ADD COLUMN `is_expired` TINYINT(1) NOT NULL DEFAULT 0,
       ADD COLUMN `never_expire` TINYINT(1) NOT NULL DEFAULT 0,
       ADD COLUMN `expired_at` DATETIME DEFAULT NULL,
       ADD INDEX `idx_is_expired` (`is_expired`),
       ADD INDEX `idx_never_expire` (`never_expire`),
       ADD INDEX `idx_expired_at` (`expired_at`);',
    'SELECT "columns_exist";');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
