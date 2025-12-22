-- Migration: create config_translations and backfill from config.description
-- Run with: mysql -h <host> -u <user> -p <db> < this_file.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS config_translations (
  config_key varchar(100) NOT NULL,
  lang char(2) NOT NULL,
  text TEXT NOT NULL,
  PRIMARY KEY (config_key, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill Spanish descriptions from existing config.description when present
INSERT INTO config_translations (config_key, lang, text)
SELECT config_key, 'es', description FROM config
WHERE description IS NOT NULL AND description != ''
ON DUPLICATE KEY UPDATE text = VALUES(text);

-- Optional: create a helper index to speed lookups by lang
CREATE INDEX IF NOT EXISTS idx_config_translations_lang ON config_translations (lang);
