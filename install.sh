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
        # Check if user has sudo privileges
        if ! sudo -n true 2>/dev/null; then
            print_error "This script requires sudo privileges. Please run with sudo or as root."
            exit 1
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
    sudo apt update && sudo apt upgrade -y
    print_status "System updated"
}

# Function to install Apache
install_apache() {
    print_info "Installing Apache web server..."
    if ! command -v apache2 &> /dev/null; then
        sudo apt install apache2 -y
        sudo systemctl start apache2
        sudo systemctl enable apache2
        print_status "Apache installed and started"
    else
        print_status "Apache already installed"
    fi
}

# Function to install MySQL
install_mysql() {
    print_info "Installing MySQL server..."
    if ! command -v mysql &> /dev/null; then
        sudo DEBIAN_FRONTEND=noninteractive apt install mysql-server -y
        sudo systemctl start mysql
        sudo systemctl enable mysql
        print_status "MySQL installed and started"
    else
        print_status "MySQL already installed"
    fi
}

# Function to install PHP and extensions
install_php() {
    print_info "Installing PHP and required extensions..."
    sudo apt install -y \
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
        libapache2-mod-php
    
    print_status "PHP and extensions installed"
}

# Function to configure MySQL database
configure_database() {
    print_info "Configuring MySQL database..."
    
    # Create database and user
    sudo mysql -u root -p${DB_ROOT_PASS} <<EOF 2>/dev/null || sudo mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF
    
    print_status "Database '${DB_NAME}' created"
    print_status "Database user '${DB_USER}' created"
}

# Function to configure Apache virtual host
configure_apache() {
    print_info "Configuring Apache virtual host..."
    
    # Create virtual host configuration
    sudo tee /etc/apache2/sites-available/${APACHE_VHOST}.conf > /dev/null <<EOF
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
</VirtualHost>
EOF
    
    # Enable required Apache modules
    sudo a2enmod rewrite
    sudo a2enmod headers
    
    # Enable site
    sudo a2ensite ${APACHE_VHOST}.conf
    
    # Add to hosts file if not already there
    if ! grep -q "${APACHE_VHOST}" /etc/hosts; then
        echo "127.0.0.1    ${APACHE_VHOST} www.${APACHE_VHOST}" | sudo tee -a /etc/hosts
        print_status "Added ${APACHE_VHOST} to /etc/hosts"
    fi
    
    print_status "Apache virtual host configured"
}

# Function to create directory structure
create_directories() {
    print_info "Creating directory structure..."
    
    # Create directories
    sudo mkdir -p ${INSTALL_DIR}/{public,includes,classes,database}
    sudo mkdir -p ${INSTALL_DIR}/public/{admin,user,assets/{css,js,images}}
    sudo mkdir -p ${UPLOADS_DIR}
    sudo mkdir -p ${TEMP_DIR}
    sudo mkdir -p ${STORAGE_DIR}/logs
    
    print_status "Directory structure created"
}

# Function to set permissions
set_permissions() {
    print_info "Setting permissions..."
    
    # Set ownership
    sudo chown -R www-data:www-data ${INSTALL_DIR}/public
    sudo chown -R www-data:www-data ${STORAGE_DIR}
    
    # Set permissions
    sudo chmod -R 755 ${INSTALL_DIR}/public
    sudo chmod -R 750 ${STORAGE_DIR}
    sudo chmod -R 770 ${UPLOADS_DIR}
    sudo chmod -R 770 ${TEMP_DIR}
    sudo chmod -R 770 ${STORAGE_DIR}/logs
    
    # Make install.sh executable
    sudo chmod +x ${INSTALL_DIR}/install.sh
    
    print_status "Permissions set"
}

# Function to create configuration file
create_config() {
    print_info "Creating configuration file..."
    
    sudo tee ${INSTALL_DIR}/includes/config.php > /dev/null <<EOF
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
define('BASE_URL', 'http://${APACHE_VHOST}');

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
    
    sudo chown www-data:www-data ${INSTALL_DIR}/includes/config.php
    sudo chmod 640 ${INSTALL_DIR}/includes/config.php
    
    print_status "Configuration file created"
}

# Function to import database schema
import_schema() {
    if [ -f "${INSTALL_DIR}/database/schema.sql" ]; then
        print_info "Importing database schema..."
        sudo mysql -u ${DB_USER} -p${DB_PASS} ${DB_NAME} < ${INSTALL_DIR}/database/schema.sql 2>/dev/null
        print_status "Database schema imported"
        
        # Import forensic logging migrations
        if [ -f "${INSTALL_DIR}/database/migrations/add_forensic_fields.sql" ]; then
            print_info "Applying forensic logging migrations..."
            sudo mysql -u ${DB_USER} -p${DB_PASS} ${DB_NAME} < ${INSTALL_DIR}/database/migrations/add_forensic_fields.sql 2>/dev/null
            print_status "Forensic logging migrations applied"
        fi
    else
        print_info "Database schema file not found yet - will be created in next steps"
    fi
}

# Function to install Composer
install_composer() {
    if ! command -v composer &> /dev/null; then
        print_info "Installing Composer..."
        cd /tmp
        curl -sS https://getcomposer.org/installer -o composer-setup.php
        sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
        rm composer-setup.php
        print_status "Composer installed"
    else
        print_status "Composer already installed"
    fi
}

# Function to install PHP dependencies
install_dependencies() {
    if [ -f "${INSTALL_DIR}/composer.json" ]; then
        print_info "Installing PHP dependencies via Composer..."
        cd ${INSTALL_DIR}
        sudo -u www-data composer install --no-dev --optimize-autoloader
        print_status "PHP dependencies installed"
    else
        print_info "composer.json not found - skipping dependency installation"
    fi
}

# Function to restart Apache
restart_apache() {
    print_info "Restarting Apache..."
    sudo systemctl restart apache2
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
    print_info "Starting installation..."
    echo
    
    update_system
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
    install_dependencies
    restart_apache
    
    display_summary
    
    print_status "Installation completed successfully!"
}

# Run main function
main
