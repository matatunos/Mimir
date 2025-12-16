Upload storage and configuration
===============================

This document explains how Mimir handles uploaded user files, how to configure a custom physical storage path, installer behavior, and security/migration notes.

1) Paths and constants
----------------------
- `STORAGE_PATH` (default: `/opt/Mimir/storage`) — top-level storage area.
- `UPLOADS_PATH` — directory where user files are stored. Default: `STORAGE_PATH/uploads`.
  - The app uses the PHP constant `UPLOADS_PATH` at runtime. Starting in this version the application will attempt a best-effort read of a DB config key named `storage_uploads_path` and, when present, use that value instead of the compile-time default.
- `TEMP_PATH` — temporary working directory for uploads and processing (`STORAGE_PATH/temp`).

2) Installer behavior
---------------------
- During interactive install (`install.sh`) you are now prompted for the uploads directory. The default shown is `${INSTALL_DIR}/storage/uploads`.
- The installer will use the chosen path when it writes `includes/config.php`, so the application will use that value immediately after install.
- For scripted/non-interactive installs you can override the choice by editing `install.sh` or by running the installer and passing environment variables (we can add a `--uploads-dir` CLI flag if you need).

3) Admin UI configuration
-------------------------
- There is a new admin config key: `storage_uploads_path` (visible under Storage in Admin → Configuración). Setting this value updates the DB and allows changing the uploads path from the UI.
- If the DB contains `storage_uploads_path`, the application will prefer that path at runtime (best-effort lookup during includes loading). Note: on fresh installs where DB isn't yet available the installer-written `includes/config.php` value is used until the DB key is set.

4) Recommended deployment options
--------------------------------
- Use a dedicated mount (e.g. `/mnt/storage/uploads` or an attached block device) and set that path during install or in the admin UI.
- Use a symlink from the configured uploads path to your mounted filesystem if you prefer the code to keep a canonical path.
- Ensure ownership and permissions: web server user (`www-data`) must be owner (or at least have write access) to `UPLOADS_PATH` and its subfolders. Typical permissions: `chown -R www-data:www-data /path/to/uploads` and `chmod -R 770` (or 750) depending on your needs.

5) Git hygiene
--------------
- Uploaded user files must not be tracked in git. The repository's `.gitignore` now ignores `public/uploads/*` and `public/favicon*` to prevent accidental commits of user content.
- If any uploaded files were accidentally committed previously, they should be removed from the index (not necessarily deleted from disk) and purged from history if they contained secrets.

6) Security considerations
--------------------------
- Never point `storage_uploads_path` to a directory that is directly executed by the webserver. Files in uploads should be served by the application with access checks, not executed as PHP.
- The application validates file paths before serving or performing filesystem operations; avoid placing uploads under system directories.
- When changing the uploads path, update backup jobs, antivirus/AV scanning, and any external indexing or search services.

7) Migration steps (change after install)
----------------------------------------
To move existing uploads to a new location:

1. Stop web server or place app in maintenance mode.
2. Create target directory and set ownership:

```bash
sudo mkdir -p /mnt/storage/uploads
sudo chown -R www-data:www-data /mnt/storage/uploads
sudo chmod -R 770 /mnt/storage/uploads
```

3. Rsync current uploads to target (preserve perms):

```bash
sudo rsync -aH --progress /opt/Mimir/storage/uploads/ /mnt/storage/uploads/
```

4. Update either:
   - `includes/config.php` → set `UPLOADS_PATH` to the new absolute path (installer writes this); or
   - In Admin → Configuración set `storage_uploads_path` to the new path (DB key). After changing the DB value, restart PHP/Apache or reload the app so the include-time lookup picks it up if necessary.

5. Validate by listing a few files and by running a download test from a user account.

8) Backups and monitoring
-------------------------
- Include the uploads directory in regular backups.
- Monitor disk usage and set alerts for low space on the uploads mount.

9) Tools in this repo
---------------------
- `tools/reconcile_uploads.php`, `tools/aggressive_reconcile_apply.php`, and other `tools/*` scripts assume `UPLOADS_PATH` and can help find or reconcile missing files.
- `tools/generate_favicon_force.php` uses the configured `site_logo` to build favicons and writes them to `/public` (owned by `www-data`).

If you want, I can also:
- add an `--uploads-dir` flag to `install.sh` for fully non-interactive installs,
- write a small migration script that updates `includes/config.php` and DB `storage_uploads_path` atomically,
- or remove the leftover branding files from `/public/uploads/branding/`.

---
Last updated: 2025-12-15
