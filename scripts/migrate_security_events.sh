#!/usr/bin/env bash
# Deployment helper: backup DB, apply migration, backfill username, add index
# Usage: ./scripts/migrate_security_events.sh <db_host> <db_user> <db_pass> <db_name>
set -euo pipefail

DB_HOST=${1:-localhost}
DB_USER=${2:-mimir_user}
DB_PASS=${3:-}
DB_NAME=${4:-mimir}

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="/tmp/${DB_NAME}_backup_${TIMESTAMP}.sql"
MIGRATION_SQL="$(pwd)/database/migrations/20251210_add_username_to_security_events.sql"
BACKFILL_PHP="$(pwd)/tools/backfill_username.php"

if [ -z "$DB_PASS" ]; then
  echo "Please provide DB password as third argument or set DB_PASS variable." >&2
  exit 2
fi

echo "Backing up database to $BACKUP_FILE..."
mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_FILE"

echo "Applying migration SQL: $MIGRATION_SQL (if needed)"
# Only apply migration if column does not exist
COL_EXISTS=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -N -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='security_events' AND COLUMN_NAME='username';")
if [ "$COL_EXISTS" -eq 0 ]; then
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$MIGRATION_SQL"
else
  echo "Column 'username' already exists, skipping ALTER TABLE."
fi

echo "Running PHP backfill to populate username from details (safe JSON decode)..."
php "$BACKFILL_PHP"

echo "Adding index idx_username on security_events.username (if not exists)..."
IDX_EXISTS=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -N -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='security_events' AND INDEX_NAME='idx_username';")
if [ "$IDX_EXISTS" -eq 0 ]; then
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -e "ALTER TABLE security_events ADD KEY idx_username (username);"
else
  echo "Index 'idx_username' already exists, skipping."
fi

echo "Migration complete. Backup at: $BACKUP_FILE"
