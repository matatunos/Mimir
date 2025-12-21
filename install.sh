#!/bin/bash
#####################################################################
# Mimir File Management System - Complete Installer
#####################################################################
#!/bin/bash
#####################################################################
# Mimir File Management System - Complete Installer
# Installs LAMP stack, configures database, sets up forensic logging
# Supports Debian/Ubuntu systems
# Version: 2.0
#####################################################################

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration variables
INSTALL_DIR="/opt/Mimir"
APACHE_VHOST="mimir.local"
DB_NAME="mimir"
DB_USER="mimir_user"
DB_PASS=$(openssl rand -base64 12)
DB_ROOT_PASS="root"
STORAGE_DIR="${INSTALL_DIR}/storage"
UPLOADS_DIR="${STORAGE_DIR}/uploads"
TEMP_DIR="${STORAGE_DIR}/temp"

# Prompt for uploads directory so user can choose a physical mount point
if [ -t 1 ]; then
    read -p "Uploads directory [${UPLOADS_DIR}]: " user_uploads
    if [ -n "${user_uploads}" ]; then
        UPLOADS_DIR="${user_uploads}"
    fi
fi

# Function to print colored messages
print_status() {
    echo -e "${GREEN}[✓]${NC} $1"
}

print_error() {
    echo -e "${RED}[✗]${NC} $1"
}

print_info() {
    echo -e "${YELLOW}[i]${NC} $1"
}

# Function to check if running as root
check_root() {
    if [[ $EUID -ne 0 ]]; then
        # If sudo is available, ensure current user can run it without password prompts
        if command -v sudo >/dev/null 2>&1; then
            if ! sudo -n true 2>/dev/null; then
                print_error "This script requires sudo privileges. Please run with sudo or as root."
                exit 1
            fi
        else
            print_error "This script requires sudo but 'sudo' is not installed. Please run this script as root."
            exit 1
        fi
    fi
}

# Helper to run commands as root (uses sudo when not running as root)
run() {
    if [[ $EUID -ne 0 ]]; then
        sudo "$@"
    else
        "$@"
    fi
}

# Helper to run a command as the www-data user (Composer steps)
run_as_www() {
    # Usage: run_as_www <command...>
    if command -v sudo >/dev/null 2>&1 && [[ $EUID -ne 0 ]]; then
        sudo -u www-data "$@"
    else
        # If running as root and sudo may not be available, use su
        if [[ $EUID -eq 0 ]]; then
            # Escape arguments safely and run via su as www-data
            CMD=$(printf '%q ' "$@")
            su -s /bin/sh -c "$CMD" www-data
        else
            # Fallback: try to run command directly
            "$@"
        fi
    fi
}

# Function to detect OS
detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$ID
        VERSION=$VERSION_ID
        print_info "Detected OS: $PRETTY_NAME"
        
        if [ "$OS" != "debian" ]; then
            print_error "This installer is designed for Debian 13. Detected: $PRETTY_NAME"
            read -p "Do you want to continue anyway? (y/N): " -n 1 -r
            echo
            if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                exit 1
            fi
        fi
    else
        print_error "Cannot detect OS version"
        exit 1
    fi
}

# Function to update system
update_system() {
    print_info "Updating system packages..."
    run apt update && run apt upgrade -y
    print_status "System updated"
}

# Function to install Apache
install_apache() {
    print_info "Installing Apache web server..."
    if ! command -v apache2 &> /dev/null; then
        run apt install apache2 -y
        run systemctl start apache2
        run systemctl enable apache2
        print_status "Apache installed and started"
    else
        print_status "Apache already installed"
    fi
}

# Function to install MySQL
install_mysql() {
    print_info "Installing MySQL server..."
    if ! command -v mysql &> /dev/null; then
        # Prefer MariaDB on newer Debian releases where available
        PKG="mysql-server"
        if apt-cache show mariadb-server >/dev/null 2>&1; then
            PKG="mariadb-server"
        fi
        run DEBIAN_FRONTEND=noninteractive apt install -y "$PKG"
        # Try both service names (mariadb/mysql)
        run systemctl start mariadb || run systemctl start mysql || true
        run systemctl enable mariadb || run systemctl enable mysql || true
        print_status "${PKG} installed and started"
    else
        print_status "MySQL already installed"
    fi
}

# Function to install PHP and extensions
install_php() {
    print_info "Installing PHP and required extensions..."
    run apt install -y \
        php \
        php-mysql \
        php-cli \
        php-curl \
        php-gd \
        php-mbstring \
        php-xml \
        php-xmlrpc \
        php-soap \
        php-intl \
        php-zip \
        php-ldap \
        php-fileinfo \
        php-imagick \
        imagemagick \
        libapache2-mod-php

    print_status "PHP and extensions installed"

    # Ensure sendmail service is available and running (mail() fallback requires a local MTA)
    if command -v sendmail >/dev/null 2>&1; then
        print_info "Configuring local sendmail service..."
        # Try both common service names; some distros use sendmail, others use sendmail.service
        run systemctl enable sendmail.service || true
        run systemctl start sendmail.service || true
        print_status "Local sendmail configured"
    else
        print_error "sendmail command not found after install. Mail fallback may not work."
    fi
}

# Function to configure MySQL database
configure_database() {
    print_info "Configuring MySQL database..."
    
    # Create database and user using a safe SQL command (avoid multiple here-docs on one line)
    # Write SQL to a temporary file to avoid quoting/word-splitting issues
    TMP_SQL_FILE=$(mktemp)
    printf '%s\n' "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
        "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" \
        "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';" \
        "FLUSH PRIVILEGES;" > "${TMP_SQL_FILE}"

    # Debug: report temp SQL file
    print_info "Debug: temp SQL file -> ${TMP_SQL_FILE} (size: $(wc -c < "${TMP_SQL_FILE}" 2>/dev/null) bytes)"

    # Try with root password first, fall back to passwordless root if needed
    if run mysql -u root -p"${DB_ROOT_PASS}" < "${TMP_SQL_FILE}" 2>/dev/null; then
        true
    else
        run mysql -u root < "${TMP_SQL_FILE}"
    fi
    rm -f "${TMP_SQL_FILE}"
    
    print_status "Database '${DB_NAME}' created"
    print_status "Database user '${DB_USER}' created"
}

# Function to configure Apache virtual host
configure_apache() {
    print_info "Configuring Apache virtual host..."
    
    # If proxy integration requested, prepare values
    if [ "${PROXY_ENABLED:-0}" -eq 1 ]; then
        PROXY_IPS_SPACE="${PROXY_IPS//,/ }"
        EXTRA_VHOST_BLOCK=$(cat <<PROXY
    # Reverse proxy / remote IP settings
    RemoteIPHeader X-Forwarded-For
    RemoteIPTrustedProxy ${PROXY_IPS_SPACE}
    # Treat forwarded proto header to set HTTPS environment
    SetEnvIf X-Forwarded-Proto "https" HTTPS=on
PROXY
)
        # Ensure remoteip module is enabled
        run a2enmod remoteip || true
    else
        EXTRA_VHOST_BLOCK=""
    fi

    # Create virtual host configuration
    run tee /etc/apache2/sites-available/${APACHE_VHOST}.conf > /dev/null <<EOF
<VirtualHost *:80>
    ServerName ${APACHE_VHOST}
    ServerAlias www.${APACHE_VHOST}
    DocumentRoot ${INSTALL_DIR}/public

    <Directory ${INSTALL_DIR}/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Prevent access to storage directory from web
    <Directory ${STORAGE_DIR}>
        Require all denied
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/${APACHE_VHOST}_error.log
    CustomLog \${APACHE_LOG_DIR}/${APACHE_VHOST}_access.log combined

    # PHP settings
    php_value upload_max_filesize 512M
    php_value post_max_size 512M
    php_value max_execution_time 300
    php_value max_input_time 300
${EXTRA_VHOST_BLOCK}
</VirtualHost>
EOF
    
    # Enable required Apache modules
    run a2enmod rewrite
    run a2enmod headers

    # Enable site
    run a2ensite ${APACHE_VHOST}.conf
    
    # Add to hosts file if not already there
    if ! grep -q "${APACHE_VHOST}" /etc/hosts; then
        echo "127.0.0.1    ${APACHE_VHOST} www.${APACHE_VHOST}" | run tee -a /etc/hosts
        print_status "Added ${APACHE_VHOST} to /etc/hosts"
    fi
    
    print_status "Apache virtual host configured"
}

# Function to create directory structure
create_directories() {
    print_info "Creating directory structure..."
    
    # Create directories
    run mkdir -p ${INSTALL_DIR}/{public,includes,classes,database}
    run mkdir -p ${INSTALL_DIR}/public/{admin,user,assets/{css,js,images}}
    run mkdir -p ${UPLOADS_DIR}
    run mkdir -p ${TEMP_DIR}
    run mkdir -p ${STORAGE_DIR}/logs
    
    print_status "Directory structure created"
}

# Function to set permissions
set_permissions() {
    print_info "Setting permissions..."
    
    # Set ownership
    run chown -R www-data:www-data ${INSTALL_DIR}/public
    run chown -R www-data:www-data ${STORAGE_DIR}
    
    # Set permissions
    run chmod -R 755 ${INSTALL_DIR}/public
    run chmod -R 750 ${STORAGE_DIR}
    run chmod -R 770 ${UPLOADS_DIR}
    run chmod -R 770 ${TEMP_DIR}
    run chmod -R 770 ${STORAGE_DIR}/logs

    # Make install.sh executable
    run chmod +x ${INSTALL_DIR}/install.sh
    
    print_status "Permissions set"
}

# Function to create configuration file
create_config() {
    print_info "Creating configuration file..."
    
    # Determine BASE_URL protocol based on proxy TLS setting
    if [ "${PROXY_HTTPS:-0}" -eq 1 ]; then
        PROTO="https"
    else
        PROTO="http"
    fi

    run tee ${INSTALL_DIR}/includes/config.php > /dev/null <<EOF
<?php
/**
 * Mimir File Management System - Configuration
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', '${DB_NAME}');
define('DB_USER', '${DB_USER}');
define('DB_PASS', '${DB_PASS}');
define('DB_CHARSET', 'utf8mb4');

// Paths
define('BASE_PATH', '${INSTALL_DIR}');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('STORAGE_PATH', '${STORAGE_DIR}');
define('UPLOADS_PATH', '${UPLOADS_DIR}');
define('TEMP_PATH', '${TEMP_DIR}');
define('LOGS_PATH', STORAGE_PATH . '/logs');

// URL configuration
define('BASE_URL', '${PROTO}://${APACHE_VHOST}');

// Security
define('SESSION_NAME', 'MIMIR_SESSION');
define('SESSION_LIFETIME', 3600); // 1 hour

// File upload defaults
define('MAX_FILE_SIZE', 512 * 1024 * 1024); // 512MB
define('ALLOWED_EXTENSIONS', 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png,gif,zip,rar,7z');

// Share defaults
define('DEFAULT_MAX_SHARE_DAYS', 30);
define('DEFAULT_MAX_DOWNLOADS', 100);

// Timezone
date_default_timezone_set('Europe/Madrid');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
EOF
    
    run chown www-data:www-data ${INSTALL_DIR}/includes/config.php
    run chmod 640 ${INSTALL_DIR}/includes/config.php
    
    print_status "Configuration file created"
}

# Function to import database schema
import_schema() {
    # Require a single schema file `database/schema.sql` to be present.
    if [ -f "${INSTALL_DIR}/database/schema.sql" ]; then
        SCHEMA_FILE="${INSTALL_DIR}/database/schema.sql"
    else
        print_error "No database schema file found! Please provide database/schema.sql"
        exit 1
    fi

    print_info "Importing database schema from ${SCHEMA_FILE}..."
    # Run import as current user (avoid sudo which can change environment and cause auth issues)
    run mysql -u ${DB_USER} -p"${DB_PASS}" ${DB_NAME} < ${SCHEMA_FILE}
    print_status "Database schema imported successfully"

    # Apply any known migrations (kept for backward compatibility)
    if [ -f "${INSTALL_DIR}/database/migration_2fa.sql" ]; then
        print_info "Applying 2FA migration..."
        run mysql -u ${DB_USER} -p${DB_PASS} ${DB_NAME} < ${INSTALL_DIR}/database/migration_2fa.sql 2>/dev/null
        print_status "2FA migration applied"
    fi
    if [ -f "${INSTALL_DIR}/database/migrations/add_forensic_fields.sql" ]; then
        print_info "Applying forensic logging migration..."
        run mysql -u ${DB_USER} -p${DB_PASS} ${DB_NAME} < ${INSTALL_DIR}/database/migrations/add_forensic_fields.sql 2>/dev/null
        print_status "Forensic logging migration applied"
    fi

    # All schema migrations are consolidated into the single schema file.
}

# Apply additional SQL migrations from database/migrations
apply_migrations() {
    print_info "Applying SQL migrations from ${INSTALL_DIR}/database/migrations (if any)..."
    if [ -d "${INSTALL_DIR}/database/migrations" ]; then
        for f in "${INSTALL_DIR}/database/migrations"/*.sql; do
            [ -e "$f" ] || continue
            print_info "Applying migration: $(basename "$f")"
            run mysql -u ${DB_USER} -p"${DB_PASS}" ${DB_NAME} < "$f" || {
                print_error "Failed applying migration: $f"
            }
        done
    else
        print_info "No migrations directory found; skipping."
    fi
}

# Install systemd unit for notification worker and enable it
install_notification_worker() {
    # Only proceed if the worker script exists
    WORKER_SCRIPT="${INSTALL_DIR}/tools/notification_worker.php"
    if [ ! -f "$WORKER_SCRIPT" ]; then
        print_info "No notification worker script found at $WORKER_SCRIPT; skipping service installation."
        return
    fi

    print_info "Installing notification worker systemd service..."
    SERVICE_FILE="/etc/systemd/system/mimir-notification-worker.service"
    run tee "$SERVICE_FILE" > /dev/null <<'EOF'
[Unit]
Description=Mimir Notification Worker
After=network.target mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/opt/Mimir
ExecStart=/usr/bin/php /opt/Mimir/tools/notification_worker.php
Restart=on-failure
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

    run systemctl daemon-reload
    run systemctl enable mimir-notification-worker.service || true
    run systemctl start mimir-notification-worker.service || true
    print_status "Notification worker service installed and started (if supported)"
}

# Function to ensure an admin user exists (username: admin, password: admin123)
create_admin_user() {
    # Allow providing a full admin email via ADMIN_EMAIL; fallback to ADMIN_USER@APACHE_VHOST
    if [ -z "${ADMIN_EMAIL:-}" ]; then
        ADMIN_EMAIL="${ADMIN_USER}@${APACHE_VHOST}"
    fi

    print_info "Ensuring admin user '${ADMIN_USER}' exists (email: ${ADMIN_EMAIL})..."

    # Generate bcrypt hash using PHP (requires php-cli)
    if ! command -v php &> /dev/null; then
        print_error "php-cli is required to hash the admin password. Skipping admin creation."
        return
    fi

    ADMIN_HASH=$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_BCRYPT);")

    # Insert admin if not exists
    # Use INSERT ... ON DUPLICATE KEY UPDATE to ensure password is set and force_password_change enabled
        run mysql -u ${DB_USER} -p"${DB_PASS}" ${DB_NAME} <<EOF 2>/dev/null
INSERT INTO users (username, email, password, full_name, role, is_active, is_ldap, created_at, updated_at)
VALUES ('${ADMIN_USER}', '${ADMIN_EMAIL}', '${ADMIN_HASH}', 'Administrator', 'admin', 1, 0, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  password = VALUES(password),
  email = VALUES(email),
  full_name = VALUES(full_name),
  role = VALUES(role),
  is_active = VALUES(is_active),
  is_ldap = VALUES(is_ldap),
  updated_at = NOW(),
  force_password_change = 1;
EOF

    print_status "Admin user ensured/updated (username: ${ADMIN_USER}) and force_password_change enabled"
}

# Function to install Composer
install_composer() {
    if ! command -v composer &> /dev/null; then
        print_info "Installing Composer..."
        cd /tmp
        # Prefer distro package when available
        if apt-cache show composer >/dev/null 2>&1; then
            print_info "Installing Composer from distro repository..."
            run apt install -y composer
            print_status "Composer installed via apt"
        else
            curl -sS https://getcomposer.org/installer -o composer-setup.php
            run php composer-setup.php --install-dir=/usr/local/bin --filename=composer
            rm composer-setup.php
            print_status "Composer installed (downloaded)"
        fi
    else
        print_status "Composer already installed"
    fi
}

# Function to install PHP dependencies
install_dependencies() {
    if [ -f "${INSTALL_DIR}/composer.json" ]; then
        # If vendor/autoload.php exists, assume deps installed
        if [ -f "${INSTALL_DIR}/vendor/autoload.php" ]; then
            print_info "PHP dependencies already present (vendor/autoload.php found). Skipping install."
            return
        fi

        print_info "Installing PHP dependencies via Composer..."

        # Ensure composer binary is available
        if ! command -v composer &> /dev/null; then
            print_info "Composer not found - attempting to install Composer..."
            install_composer
        fi

        cd ${INSTALL_DIR}
        # Run composer as the web user to ensure correct ownership of vendor files
        if ! run_as_www composer install --no-dev --optimize-autoloader; then
            print_error "Composer install failed. Retrying once..."
            sleep 1
            if ! run_as_www composer install --no-dev --optimize-autoloader; then
                print_error "Composer install failed again. Please run 'composer install' manually in ${INSTALL_DIR} as www-data."
                return 1
            fi
        fi

        print_status "PHP dependencies installed"
    else
        print_info "composer.json not found - skipping dependency installation"
    fi
}

# Function to restart Apache
restart_apache() {
    print_info "Restarting Apache..."
    run systemctl restart apache2
    print_status "Apache restarted"
}

# Function to display summary
display_summary() {
    echo
    echo "=========================================="
    echo "  Mimir Installation Complete!"
    echo "=========================================="
    echo
    echo "Installation Directory: ${INSTALL_DIR}"
    echo "Web URL: http://${APACHE_VHOST}"
    echo
    echo "Database Information:"
    echo "  Database Name: ${DB_NAME}"
    echo "  Database User: ${DB_USER}"
    echo "  Database Pass: ${DB_PASS}"
    echo
    echo "Storage Directory: ${STORAGE_DIR}"
    echo "Uploads Directory: ${UPLOADS_DIR}"
    echo
    echo "Configuration file: ${INSTALL_DIR}/includes/config.php"
    echo
    echo "Features Installed:"
    echo "  ✓ User authentication with 2FA (TOTP/Duo)"
    echo "  ✓ File management with sharing"
    echo "  ✓ Advanced dashboard with Chart.js analytics"
    echo "  ✓ Forensic logging system (30+ fields per download)"
    echo "  ✓ Advanced user management (filters, bulk actions)"
    echo "  ✓ Advanced file management (filters, sorting)"
    echo
    echo "Next Steps:"
    echo "1. Add your domain to /etc/hosts: echo '127.0.0.1 ${APACHE_VHOST}' | sudo tee -a /etc/hosts"
    echo "2. Access the application: http://${APACHE_VHOST}"
    echo "3. Create admin user via database or seed script"
    echo "4. Configure LDAP/2FA settings in includes/config.php if needed"
    echo
    echo "Optional: Generate test data"
    echo "  - php seed_database.php (users and files)"
    echo "  - php seed_historical_activity.php (365 days of activity)"
    echo "  - php simulate_forensic_downloads.php (90 days of downloads)"
    echo
    echo "=========================================="
    echo
}

# Main installation process
main() {
    echo "=========================================="
    echo "  Mimir File Management System Installer"
    echo "  for Debian 13"
    echo "=========================================="
    echo
    
    check_root
    detect_os
    
    echo
    read -p "Continue with installation? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        print_info "Installation cancelled"
        exit 0
    fi
    
    echo
    # Prompt for domain and proxy settings before starting installation
    read -p "Enter domain for the site (default: ${APACHE_VHOST}): " DOMAIN_INPUT
    if [ -n "${DOMAIN_INPUT}" ]; then
        APACHE_VHOST="${DOMAIN_INPUT}"
    fi

    # Ask about reverse proxy
    read -p "Is the application behind an Nginx reverse proxy? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        PROXY_ENABLED=1
        read -p "Enter proxy trusted IPs/CIDRs (comma-separated, default: 127.0.0.1,192.168.0.0/16,10.0.0.0/8): " PROXY_IPS_INPUT
        PROXY_IPS="${PROXY_IPS_INPUT:-127.0.0.1,192.168.0.0/16,10.0.0.0/8}"
        read -p "Does the proxy terminate TLS (serve HTTPS)? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            PROXY_HTTPS=1
        else
            PROXY_HTTPS=0
        fi
    else
        PROXY_ENABLED=0
        PROXY_IPS=""
        PROXY_HTTPS=0
    fi

    # Database and application credentials
    read -p "Database root user (default: root): " DB_ROOT_USER_INPUT
    DB_ROOT_USER="${DB_ROOT_USER_INPUT:-root}"
    read -s -p "Database root password (will be used to create DB/user) : " DB_ROOT_PASS_INPUT
    echo
    if [ -n "${DB_ROOT_PASS_INPUT}" ]; then
        DB_ROOT_PASS="${DB_ROOT_PASS_INPUT}"
    fi

    read -p "Database name to create (default: ${DB_NAME}): " DB_NAME_INPUT
    DB_NAME="${DB_NAME_INPUT:-${DB_NAME}}"
    read -p "Database app user (default: ${DB_USER}): " DB_USER_INPUT
    DB_USER="${DB_USER_INPUT:-${DB_USER}}"
    read -s -p "Password for database app user (leave empty to generate): " DB_PASS_INPUT
    echo
    if [ -n "${DB_PASS_INPUT}" ]; then
        DB_PASS="${DB_PASS_INPUT}"
    else
        DB_PASS=$(openssl rand -base64 16)
        print_info "Generated DB user password"
    fi

    # Admin web user
    read -p "Admin web username (default: admin): " ADMIN_USER_INPUT
    ADMIN_USER="${ADMIN_USER_INPUT:-admin}"
    read -s -p "Admin web password (leave empty to generate): " ADMIN_PASS_INPUT
    echo
    if [ -n "${ADMIN_PASS_INPUT}" ]; then
        ADMIN_PASS="${ADMIN_PASS_INPUT}"
    else
        ADMIN_PASS=$(openssl rand -base64 12)
        print_info "Generated admin web password"
    fi

    print_info "Starting installation..."
    echo
    
    # Allow skipping the system update (useful when retrying installer after package fixes)
    if [ "${SKIP_UPDATE:-0}" -ne 1 ]; then
        update_system
    else
        print_info "Skipping system update (SKIP_UPDATE=1)"
    fi
    install_apache
    install_mysql
    install_php
    install_composer
    configure_database
    configure_apache
    create_directories
    set_permissions
    create_config
    import_schema
        # Apply any additional migrations found in database/migrations
        apply_migrations
    
        # Install background worker service for notifications (if available)
        install_notification_worker
    create_admin_user
    install_dependencies
    restart_apache
    
    display_summary
    
    print_status "Installation completed successfully!"
}

# Run main function
main
