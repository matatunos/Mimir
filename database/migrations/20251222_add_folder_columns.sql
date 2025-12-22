-- Migration: add folder columns to `files` table (idempotent)
-- Date: 2025-12-22

-- This migration adds `is_folder` and `parent_folder_id` columns to the
-- `files` table if they do not already exist. It also adds simple indexes.

SET @cnt_is_folder := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'files'
                 AND COLUMN_NAME = 'is_folder');

SET @sql = IF(@cnt_is_folder = 0,
    'ALTER TABLE `files` 
       ADD COLUMN `is_folder` TINYINT(1) NOT NULL DEFAULT 0,
       ADD COLUMN `parent_folder_id` INT(11) DEFAULT NULL,
       ADD INDEX `idx_is_folder` (`is_folder`),
       ADD INDEX `idx_parent_folder_id` (`parent_folder_id`);',
    'SELECT "columns_exist"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Optional: add a foreign key to parent_folder_id if desired and supported
-- Note: enabling the FK is commented out to avoid errors in deployments that
-- may not want the constraint. Uncomment if you want cascading deletes.
--
-- ALTER TABLE `files`
--   ADD CONSTRAINT `files_parent_fk` FOREIGN KEY (`parent_folder_id`) REFERENCES `files`(`id`) ON DELETE CASCADE;
