-- Migration: create disk_usage_metrics table
-- Run using: mysql < this-file > or via your migration runner
CREATE TABLE IF NOT EXISTS disk_usage_metrics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  total_bytes BIGINT UNSIGNED NOT NULL,
  free_bytes BIGINT UNSIGNED NOT NULL,
  used_bytes BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  INDEX (recorded_at)
);
