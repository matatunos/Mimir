# File ownership history

This document describes the `file_ownership_history` table and how the DB trigger records owner changes.

Location:
- Migration SQL: `database/migrations/20251226_create_file_ownership_history.sql`
- Schema files updated: `database/schema.sql`, `database/complete_schema.sql`

Purpose
- Keep a reliable, queryable history of changes to `files.user_id` (assign, reassign, orphaned).
- Unlike free-form `activity_log` messages, this table stores structured old/new owner ids and (optionally) who triggered the change.

Schema (summary)
- `id` BIGINT AUTO_INCREMENT (PK)
- `file_id` INT NOT NULL
- `old_user_id` INT NULL
- `new_user_id` INT NULL
- `changed_by_user_id` INT NULL
- `reason` VARCHAR(100) NULL — values like `assigned`, `orphaned`, `reassign`
- `note` TEXT NULL — diagnostic note (trigger includes `@current_actor_note`)
- `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

Trigger behavior
- Trigger name: `files_owner_change` (AFTER UPDATE on `files`).
- It fires when `OLD.user_id` != `NEW.user_id` and inserts a row in `file_ownership_history`.
- If the application sets the session user variable `@current_actor_id` before performing the update, the trigger records it in `changed_by_user_id`.

How the application should set the actor
1. Set the DB user variable before the update:

```php
$db->prepare('SET @current_actor_id = ?')->execute([ $_SESSION['user_id'] ?? null ]);
// perform UPDATE files SET user_id = ? WHERE id = ?
$db->query('SET @current_actor_id = NULL');
```

2. Alternatively use the helper scripts included in `tools/` for testing:
- `php tools/check_file_ownership_history.php` — checks table existence and prints recent rows.
- `php tools/simulate_owner_change.php <file_id> <new_user_id> [actor_user_id]` — sets `@current_actor_id`, performs an update and prints history rows.

Notes and recommendations
- The trigger records all changes regardless of how they are performed (direct SQL, application routes, or cascades). To capture who performed the change, the application must set `@current_actor_id` before the update.
- I added `@current_actor_id` usage in the main ownership paths: `public/admin/orphan_files_api.php`, `classes/File.php::reassignOwner` and `classes/User.php::delete`.
- If your environment has other code paths that change `files.user_id`, consider setting `@current_actor_id` there as well.

Applying migration
Run on the DB server (adjust credentials):

```bash
mysql -u <db_user> -p <database_name> < database/migrations/20251226_create_file_ownership_history.sql
```

Rollback
- The migration creates a table and a trigger; rollback would drop them. If you need a rollback script, contact the maintainer and we can add one.
