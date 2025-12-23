-- Migration: add language column to users
-- Idempotent: only adds the column if it does not exist
SET @dbname = DATABASE();

SELECT COUNT(*) INTO @exists FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'users' AND COLUMN_NAME = 'language';

-- If not exists, alter table
SET @sql = IF(@exists = 0,
  'ALTER TABLE users ADD COLUMN language VARCHAR(10) NULL DEFAULT NULL AFTER email;',
  'SELECT "column_exists"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- No backfill: users can set preference in profile
