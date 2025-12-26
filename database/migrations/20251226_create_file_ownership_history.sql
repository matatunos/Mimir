-- Migration: create file_ownership_history and trigger to record owner changes
-- Run this file against your MySQL database (e.g. `mysql -u root -p < 20251226_create_file_ownership_history.sql`)

CREATE TABLE IF NOT EXISTS `file_ownership_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_id` INT NOT NULL,
  `old_user_id` INT DEFAULT NULL,
  `new_user_id` INT DEFAULT NULL,
  `changed_by_user_id` INT DEFAULT NULL,
  `reason` VARCHAR(100) DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX (`file_id`),
  INDEX (`old_user_id`),
  INDEX (`new_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drop existing trigger if present
DROP TRIGGER IF EXISTS `files_owner_change`;

DELIMITER $$
CREATE TRIGGER `files_owner_change`
AFTER UPDATE ON `files`
FOR EACH ROW
BEGIN
    -- Only record when user_id actually changes
    IF NOT (OLD.user_id <=> NEW.user_id) THEN
        INSERT INTO file_ownership_history (
            file_id, old_user_id, new_user_id, changed_by_user_id, reason, note
        ) VALUES (
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

-- Notes:
-- * The trigger uses the user variables `@current_actor_id` and `@current_actor_note` (optional) to capture who triggered the change.
-- * The application should set `@current_actor_id` (integer) before performing any update that changes `files.user_id`, e.g.:
--     $db->prepare("SET @current_actor_id = ?")->execute([$actorId]);
-- * If `@current_actor_id` is not set, the trigger will still record old/new owner ids, but `changed_by_user_id` will be NULL.
