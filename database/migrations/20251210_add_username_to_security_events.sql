-- Migration: Add username column to security_events
-- Run this as the DB admin: mysql -u <user> -p <db> < 20251210_add_username_to_security_events.sql

ALTER TABLE `security_events`
  ADD COLUMN `username` VARCHAR(100) NULL AFTER `event_type`;

-- Optional: populate username from JSON details where possible (MySQL 5.7+ with JSON functions)
-- If your MySQL supports JSON_EXTRACT, you can run:
-- UPDATE security_events SET username = JSON_UNQUOTE(JSON_EXTRACT(details, '$.username')) WHERE username IS NULL AND details IS NOT NULL;

-- End of migration
