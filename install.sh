#!/bin/bash
################################################################################
# Mimir File Storage System - Installation Script
# Este script automatiza la instalación completa de Mimir
################################################################################

set -e

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Funciones de utilidad
print_header() {
    echo -e "\n${BLUE}========================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}========================================${NC}\n"
}

print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_info() {
    echo -e "${BLUE}ℹ${NC} $1"
}

# Detectar sistema operativo
detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$ID
        OS_VERSION=$VERSION_ID
        OS_NAME=$NAME
    elif [ -f /etc/debian_version ]; then
        OS="debian"
        OS_NAME="Debian"
    elif [ -f /etc/redhat-release ]; then
        OS="rhel"
        OS_NAME="RedHat/CentOS"
    else
        OS="unknown"
        OS_NAME="Unknown"
    fi
    
    print_info "Sistema operativo detectado: $OS_NAME"
}

# Verificar si el script se ejecuta como root
check_root() {
    if [ "$EUID" -eq 0 ]; then
        print_warning "Este script está corriendo como root. Se recomienda ejecutar sin root y usar sudo cuando sea necesario."
        read -p "¿Deseas continuar? (s/n): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Ss]$ ]]; then
            exit 1
        fi
    fi
}

# Instalar requisitos automáticamente
install_requirements() {
    print_header "Instalando Requisitos del Sistema"
    
    local install_needed=false
    
    # Verificar si ya están instalados los requisitos básicos
    if ! command -v php &> /dev/null || ! command -v mysql &> /dev/null; then
        install_needed=true
    fi
    
    # Verificar extensiones PHP necesarias
    local missing_extensions=()
    local required_extensions=("mysqli" "pdo" "pdo_mysql" "json" "fileinfo" "mbstring")
    
    if command -v php &> /dev/null; then
        for ext in "${required_extensions[@]}"; do
            if ! php -m | grep -q "^$ext$"; then
                missing_extensions+=("$ext")
                install_needed=true
            fi
        done
    fi
    
    if [ "$install_needed" = false ]; then
        print_info "Todos los requisitos ya están instalados"
        return 0
    fi
    
    if [ ${#missing_extensions[@]} -gt 0 ]; then
        print_warning "Extensiones PHP faltantes: ${missing_extensions[*]}"
    fi
    
    print_warning "Se necesita instalar algunos paquetes del sistema"
    read -p "¿Deseas instalar automáticamente los requisitos? (s/n): " -n 1 -r
    echo
    
    if [[ ! $REPLY =~ ^[Ss]$ ]]; then
        print_info "Instalación manual requerida. Abortando..."
        exit 0
    fi
    
    case "$OS" in
        debian|ubuntu)
            print_info "Instalando paquetes en sistema Debian/Ubuntu..."
            sudo apt-get update
            
            # Instalar paquetes básicos si faltan
            if ! command -v php &> /dev/null || ! command -v mysql &> /dev/null; then
                sudo apt-get install -y \
                    php \
                    php-cli \
                    php-common \
                    mariadb-server \
                    mariadb-client \
                    apache2 \
                    libapache2-mod-php
            fi
            
            # Instalar extensiones PHP faltantes
            local php_packages=()
            for ext in "${missing_extensions[@]}"; do
                case "$ext" in
                    mysqli|pdo|pdo_mysql)
                        if ! dpkg -l | grep -q "php.*-mysql"; then
                            php_packages+=("php-mysql")
                        fi
                        ;;
                    mbstring)
                        if ! dpkg -l | grep -q "php.*-mbstring"; then
                            php_packages+=("php-mbstring")
                        fi
                        ;;
                    json)
                        if ! dpkg -l | grep -q "php.*-json"; then
                            php_packages+=("php-json")
                        fi
                        ;;
                    fileinfo)
                        # fileinfo suele venir con php-common
                        ;;
                esac
            done
            
            # Eliminar duplicados e instalar
            if [ ${#php_packages[@]} -gt 0 ]; then
                php_packages=($(echo "${php_packages[@]}" | tr ' ' '\n' | sort -u | tr '\n' ' '))
                print_info "Instalando extensiones PHP: ${php_packages[*]}"
                sudo apt-get install -y "${php_packages[@]}" php-xml php-curl
            fi
            
            # Habilitar módulos de Apache
            sudo a2enmod rewrite 2>/dev/null || true
            sudo a2enmod php 2>/dev/null || true
            
            # Iniciar servicios
            if command -v systemctl &> /dev/null; then
                sudo systemctl start mariadb 2>/dev/null || true
                sudo systemctl enable mariadb 2>/dev/null || true
                sudo systemctl restart apache2 2>/dev/null || true
                sudo systemctl enable apache2 2>/dev/null || true
            fi
            
            print_success "Requisitos instalados correctamente en Debian/Ubuntu"
            ;;
            
        centos|rhel|fedora)
            print_info "Instalando paquetes en sistema RedHat/CentOS/Fedora..."
            
            if command -v dnf &> /dev/null; then
                PKG_MANAGER="dnf"
            else
                PKG_MANAGER="yum"
            fi
            
            # Instalar paquetes necesarios
            local packages=(php php-cli php-mysqlnd php-pdo php-json php-mbstring php-xml mariadb-server mariadb httpd)
            sudo $PKG_MANAGER install -y "${packages[@]}"
            
            # Iniciar servicios
            if command -v systemctl &> /dev/null; then
                sudo systemctl start mariadb
                sudo systemctl enable mariadb
                sudo systemctl restart httpd
                sudo systemctl enable httpd
            fi
            
            print_success "Requisitos instalados correctamente en RedHat/CentOS/Fedora"
            ;;
            
        arch|manjaro)
            print_info "Instalando paquetes en sistema Arch Linux..."
            sudo pacman -Sy --noconfirm \
                php \
                php-apache \
                mariadb \
                apache
            
            # Configurar MariaDB si es necesario
            if [ ! -d /var/lib/mysql/mysql ]; then
                sudo mariadb-install-db --user=mysql --basedir=/usr --datadir=/var/lib/mysql
            fi
            
            # Iniciar servicios
            if command -v systemctl &> /dev/null; then
                sudo systemctl start mariadb
                sudo systemctl enable mariadb
                sudo systemctl restart httpd
                sudo systemctl enable httpd
            fi
            
            print_success "Requisitos instalados correctamente en Arch Linux"
            ;;
            
        alpine)
            print_info "Instalando paquetes en sistema Alpine Linux..."
            sudo apk add --no-cache \
                php \
                php-cli \
                php-mysqli \
                php-pdo \
                php-pdo_mysql \
                php-json \
                php-mbstring \
                php-fileinfo \
                php-apache2 \
                mariadb \
                mariadb-client \
                apache2
            
            # Iniciar servicios
            if command -v rc-service &> /dev/null; then
                sudo rc-service mariadb start 2>/dev/null || true
                sudo rc-update add mariadb 2>/dev/null || true
                sudo rc-service apache2 start 2>/dev/null || true
                sudo rc-update add apache2 2>/dev/null || true
            fi
            
            print_success "Requisitos instalados correctamente en Alpine Linux"
            ;;

            
        *)
            print_error "Sistema operativo no soportado para instalación automática: $OS_NAME"
            echo ""
            echo "Por favor, instala manualmente los siguientes componentes:"
            echo "  - PHP 7.4 o superior"
            echo "  - MySQL 5.7 / MariaDB 10.2 o superior"
            echo "  - Apache con mod_rewrite"
            echo "  - Extensiones PHP: mysqli, pdo, pdo_mysql, json, fileinfo, mbstring"
            echo ""
            read -p "¿Deseas continuar de todos modos? (s/n): " -n 1 -r
            echo
            if [[ ! $REPLY =~ ^[Ss]$ ]]; then
                exit 1
            fi
            ;;
    esac
    
    # Verificar que PHP esté en el PATH después de la instalación
    if ! command -v php &> /dev/null; then
        print_warning "PHP no está en el PATH. Puede que necesites reiniciar tu sesión o agregar PHP al PATH manualmente."
    else
        print_info "Verificando extensiones PHP instaladas..."
        sleep 2
        
        # Verificar nuevamente las extensiones
        local still_missing=()
        for ext in "${required_extensions[@]}"; do
            if ! php -m | grep -q "^$ext$"; then
                still_missing+=("$ext")
            fi
        done
        
        if [ ${#still_missing[@]} -gt 0 ]; then
            print_warning "Aún faltan algunas extensiones: ${still_missing[*]}"
            print_info "Puede que necesites reiniciar el servidor web o instalar paquetes adicionales"
        else
            print_success "Todas las extensiones PHP necesarias están instaladas"
        fi
    fi
}

# Verificar requisitos del sistema
check_requirements() {
    print_header "Verificando Requisitos del Sistema"
    
    local all_ok=true
    
    # Verificar PHP
    if command -v php &> /dev/null; then
        local php_version=$(php -r 'echo PHP_VERSION;')
        print_success "PHP $php_version instalado"
        
        # Verificar versión mínima de PHP
        if php -r 'exit(version_compare(PHP_VERSION, "7.4.0", ">=") ? 0 : 1);'; then
            print_success "Versión de PHP es compatible (7.4+)"
        else
            print_error "Se requiere PHP 7.4 o superior"
            all_ok=false
        fi
    else
        print_error "PHP no está instalado"
        all_ok=false
    fi
    
    # Verificar MySQL/MariaDB
    if command -v mysql &> /dev/null; then
        print_success "Cliente MySQL/MariaDB instalado"
    else
        print_error "Cliente MySQL/MariaDB no está instalado"
        all_ok=false
    fi
    
    # Verificar extensiones PHP necesarias
    local required_extensions=("mysqli" "pdo" "pdo_mysql" "json" "fileinfo" "mbstring")
    for ext in "${required_extensions[@]}"; do
        if php -m | grep -q "^$ext$"; then
            print_success "Extensión PHP '$ext' disponible"
        else
            # PDO base puede estar listado como PDO (mayúscula)
            if [ "$ext" = "pdo" ] && php -m | grep -qi "^PDO$"; then
                print_success "Extensión PHP 'PDO' disponible"
            elif [ "$ext" = "pdo" ] && php -m | grep -q "^pdo_mysql$"; then
                # Si pdo_mysql funciona, PDO está disponible
                print_success "Extensión PHP 'pdo' disponible (vía pdo_mysql)"
            else
                print_error "Extensión PHP '$ext' no disponible"
                all_ok=false
            fi
        fi
    done
    
    if [ "$all_ok" = false ]; then
        print_error "Algunos requisitos no están cumplidos. Por favor, instala los componentes faltantes."
        exit 1
    fi
    
    print_success "Todos los requisitos están cumplidos"
}

# Obtener directorio de instalación
INSTALL_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

# Solicitar información de configuración
gather_config_info() {
    print_header "Configuración de la Base de Datos"
    
    read -p "Host de MySQL (default: localhost): " DB_HOST
    DB_HOST=${DB_HOST:-localhost}
    
    read -p "Puerto de MySQL (default: 3306): " DB_PORT
    DB_PORT=${DB_PORT:-3306}
    
    read -p "Nombre de la base de datos (default: mimir): " DB_NAME
    DB_NAME=${DB_NAME:-mimir}
    
    read -p "Usuario de la base de datos (default: mimir_user): " DB_USER
    DB_USER=${DB_USER:-mimir_user}
    
    read -sp "Contraseña del usuario de la base de datos: " DB_PASS
    echo
    
    if [ -z "$DB_PASS" ]; then
        DB_PASS=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 16)
        print_info "Contraseña generada automáticamente: $DB_PASS"
    fi
    
    read -sp "Contraseña del usuario root de MySQL: " MYSQL_ROOT_PASS
    echo
    
    print_header "Configuración de la Aplicación"
    
    read -p "URL base de la aplicación (ej: http://localhost o http://tu-dominio.com): " BASE_URL
    if [ -z "$BASE_URL" ]; then
        BASE_URL="http://localhost"
    fi
    
    read -p "Usuario administrador (default: admin): " ADMIN_USER
    ADMIN_USER=${ADMIN_USER:-admin}
    
    read -p "Email del administrador (default: admin@mimir.local): " ADMIN_EMAIL
    ADMIN_EMAIL=${ADMIN_EMAIL:-admin@mimir.local}
    
    read -sp "Contraseña del administrador: " ADMIN_PASS
    echo
    
    if [ -z "$ADMIN_PASS" ]; then
        ADMIN_PASS=$(openssl rand -base64 12)
        print_info "Contraseña de administrador generada: $ADMIN_PASS"
    fi
    
    # Resumen de configuración
    print_header "Resumen de Configuración"
    echo "Base de datos:"
    echo "  - Host: $DB_HOST:$DB_PORT"
    echo "  - Nombre: $DB_NAME"
    echo "  - Usuario: $DB_USER"
    echo "  - Contraseña: ********"
    echo ""
    echo "Aplicación:"
    echo "  - URL Base: $BASE_URL"
    echo "  - Usuario Admin: $ADMIN_USER"
    echo "  - Email Admin: $ADMIN_EMAIL"
    echo ""
    
    read -p "¿Es correcta la configuración? (s/n): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Ss]$ ]]; then
        print_info "Instalación cancelada por el usuario"
        exit 0
    fi
}

# Crear base de datos y usuario
create_database() {
    print_header "Creando Base de Datos"
    
    # Crear script temporal de SQL
    local temp_sql=$(mktemp)
    
    cat > "$temp_sql" << EOF
-- Crear base de datos si no existe
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Crear usuario si no existe (compatible con MySQL 5.7 y superior)
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';

-- Otorgar privilegios
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%';

-- Aplicar cambios
FLUSH PRIVILEGES;
EOF
    
    # Ejecutar script SQL
    if [ -n "$MYSQL_ROOT_PASS" ]; then
        if mysql -h "$DB_HOST" -P "$DB_PORT" -u root -p"$MYSQL_ROOT_PASS" < "$temp_sql" 2>/dev/null; then
            print_success "Base de datos y usuario creados correctamente"
        else
            print_error "Error al crear la base de datos. Verifica las credenciales de root."
            rm -f "$temp_sql"
            exit 1
        fi
    else
        if mysql -h "$DB_HOST" -P "$DB_PORT" -u root < "$temp_sql" 2>/dev/null; then
            print_success "Base de datos y usuario creados correctamente"
        else
            print_error "Error al crear la base de datos. Verifica las credenciales de root."
            rm -f "$temp_sql"
            exit 1
        fi
    fi
    
    rm -f "$temp_sql"
}

# Importar esquema de base de datos
import_schema() {
    print_header "Importando Esquema de Base de Datos"
    
    local schema_file="$INSTALL_DIR/database/schema.sql"
    
    if [ ! -f "$schema_file" ]; then
        print_error "Archivo de esquema no encontrado: $schema_file"
        exit 1
    fi
    
    if mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$schema_file" 2>/dev/null; then
        print_success "Esquema de base de datos importado correctamente"
    else
        print_error "Error al importar el esquema de base de datos"
        exit 1
    fi
}

# Crear usuario administrador
create_admin_user() {
    print_header "Creando Usuario Administrador"
    
    local password_hash=$(php -r "echo password_hash('$ADMIN_PASS', PASSWORD_BCRYPT);")
    
    local temp_sql=$(mktemp)
    cat > "$temp_sql" << EOF
INSERT INTO users (username, email, password_hash, role, is_active, storage_quota)
VALUES ('$ADMIN_USER', '$ADMIN_EMAIL', '$password_hash', 'admin', TRUE, 10737418240)
ON DUPLICATE KEY UPDATE
    email = '$ADMIN_EMAIL',
    password_hash = '$password_hash',
    role = 'admin',
    is_active = TRUE;
EOF
    
    if mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$temp_sql" 2>/dev/null; then
        print_success "Usuario administrador creado correctamente"
    else
        print_error "Error al crear usuario administrador"
        rm -f "$temp_sql"
        exit 1
    fi
    
    rm -f "$temp_sql"
}

# Crear archivo de configuración
create_config_file() {
    print_header "Creando Archivo de Configuración"
    
    local config_file="$INSTALL_DIR/config/config.php"
    local example_file="$INSTALL_DIR/config/config.example.php"
    
    if [ ! -f "$example_file" ]; then
        print_error "Archivo de ejemplo de configuración no encontrado: $example_file"
        exit 1
    fi
    
    # Hacer backup si ya existe config.php
    if [ -f "$config_file" ]; then
        local backup_file="$config_file.backup.$(date +%Y%m%d_%H%M%S)"
        cp "$config_file" "$backup_file"
        print_info "Backup del archivo de configuración anterior creado: $backup_file"
    fi
    
    # Copiar y modificar configuración
    cat > "$config_file" << 'EOF'
<?php
/**
 * Mimir File Storage System - Configuration File
 * Generated by install.sh
 */

// Database Configuration
define('DB_HOST', 'DB_HOST_VALUE');
define('DB_NAME', 'DB_NAME_VALUE');
define('DB_USER', 'DB_USER_VALUE');
define('DB_PASS', 'DB_PASS_VALUE');
define('DB_CHARSET', 'utf8mb4');

// Application Settings
define('APP_NAME', 'Mimir');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'BASE_URL_VALUE');

// File Storage Settings
define('UPLOAD_DIR', __DIR__ . '/../uploads/files/');
define('TEMP_DIR', __DIR__ . '/../uploads/temp/');
define('LOG_DIR', __DIR__ . '/../logs/');

// Security Settings
define('SESSION_NAME', 'MIMIR_SESSION');
define('SESSION_LIFETIME', 7200); // 2 hours in seconds
define('CSRF_TOKEN_NAME', 'csrf_token');

// File Settings
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', '7z', 'mp3', 'mp4', 'avi', 'mov']);
define('MAX_FILE_SIZE_DEFAULT', 104857600); // 100MB in bytes

// Share Settings
define('MAX_SHARE_TIME_DAYS_DEFAULT', 30);
define('SHARE_TOKEN_LENGTH', 32);

// Timezone
date_default_timezone_set('UTC');

// Error Reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create required directories if they don't exist
$dirs = [UPLOAD_DIR, TEMP_DIR, LOG_DIR];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            error_log("Failed to create directory: $dir");
            die("Error: Could not create required directory: $dir. Please create it manually and ensure proper permissions.");
        }
    }
}
EOF
    
    # Reemplazar valores
    sed -i "s|DB_HOST_VALUE|$DB_HOST|g" "$config_file"
    sed -i "s|DB_NAME_VALUE|$DB_NAME|g" "$config_file"
    sed -i "s|DB_USER_VALUE|$DB_USER|g" "$config_file"
    sed -i "s|DB_PASS_VALUE|$DB_PASS|g" "$config_file"
    sed -i "s|BASE_URL_VALUE|$BASE_URL|g" "$config_file"
    
    print_success "Archivo de configuración creado: $config_file"
}

# Crear directorios necesarios
create_directories() {
    print_header "Creando Directorios"
    
    local dirs=(
        "$INSTALL_DIR/uploads"
        "$INSTALL_DIR/uploads/files"
        "$INSTALL_DIR/uploads/temp"
        "$INSTALL_DIR/logs"
    )
    
    for dir in "${dirs[@]}"; do
        if [ ! -d "$dir" ]; then
            mkdir -p "$dir"
            print_success "Directorio creado: $dir"
        else
            print_info "Directorio ya existe: $dir"
        fi
    done
}

# Configurar permisos
set_permissions() {
    print_header "Configurando Permisos"
    
    # Detectar usuario del servidor web
    local web_user=""
    if id "www-data" &>/dev/null; then
        web_user="www-data"
    elif id "apache" &>/dev/null; then
        web_user="apache"
    elif id "nginx" &>/dev/null; then
        web_user="nginx"
    else
        print_warning "No se pudo detectar el usuario del servidor web automáticamente"
        read -p "Introduce el usuario del servidor web (ej: www-data, apache, nginx): " web_user
    fi
    
    if [ -n "$web_user" ]; then
        print_info "Usando usuario del servidor web: $web_user"
        
        # Establecer propietario
        if sudo chown -R "$web_user:$web_user" "$INSTALL_DIR/uploads" "$INSTALL_DIR/logs" 2>/dev/null; then
            print_success "Propietario configurado correctamente"
        else
            print_warning "No se pudo cambiar el propietario. Es posible que necesites ejecutar manualmente:"
            echo "  sudo chown -R $web_user:$web_user $INSTALL_DIR/uploads $INSTALL_DIR/logs"
        fi
    fi
    
    # Establecer permisos
    chmod -R 755 "$INSTALL_DIR"
    chmod -R 775 "$INSTALL_DIR/uploads" "$INSTALL_DIR/logs"
    chmod 600 "$INSTALL_DIR/config/config.php"
    
    print_success "Permisos configurados correctamente"
}

# Configurar cron job
setup_cron() {
    print_header "Configurar Tarea Cron (Opcional)"
    
    print_info "Se recomienda configurar una tarea cron para limpiar archivos expirados"
    echo "El script de limpieza está en: $INSTALL_DIR/cron/cleanup.php"
    echo ""
    echo "Ejemplo de configuración cron (ejecutar cada hora):"
    echo "0 * * * * php $INSTALL_DIR/cron/cleanup.php"
    echo ""
    
    read -p "¿Deseas configurar el cron job automáticamente? (s/n): " -n 1 -r
    echo
    
    if [[ $REPLY =~ ^[Ss]$ ]]; then
        local cron_line="0 * * * * php $INSTALL_DIR/cron/cleanup.php"
        (crontab -l 2>/dev/null | grep -v "cleanup.php"; echo "$cron_line") | crontab -
        print_success "Tarea cron configurada correctamente"
    else
        print_info "Puedes configurar el cron job manualmente más tarde"
    fi
}

# Mostrar información de finalización
show_completion_info() {
    print_header "¡Instalación Completada!"
    
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║         Mimir se ha instalado correctamente               ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo "📁 Directorio de instalación: $INSTALL_DIR"
    echo "🌐 URL de acceso: $BASE_URL/public/"
    echo ""
    echo "👤 Credenciales de administrador:"
    echo "   Usuario: $ADMIN_USER"
    echo "   Email: $ADMIN_EMAIL"
    echo "   Contraseña: $ADMIN_PASS"
    echo ""
    echo "🔐 Credenciales de base de datos:"
    echo "   Host: $DB_HOST:$DB_PORT"
    echo "   Base de datos: $DB_NAME"
    echo "   Usuario: $DB_USER"
    echo "   Contraseña: $DB_PASS"
    echo ""
    print_warning "IMPORTANTE: Guarda estas credenciales en un lugar seguro"
    echo ""
    echo "📝 Próximos pasos:"
    echo "   1. Configura tu servidor web (Apache/Nginx) para apuntar a $INSTALL_DIR/public"
    echo "   2. Asegúrate de que el módulo rewrite esté habilitado"
    echo "   3. Accede a $BASE_URL/public/ para comenzar a usar Mimir"
    echo "   4. Cambia la contraseña del administrador después del primer inicio de sesión"
    echo ""
    
    # Guardar credenciales en archivo
    local creds_file="$INSTALL_DIR/CREDENTIALS.txt"
    cat > "$creds_file" << EOF
MIMIR - CREDENCIALES DE INSTALACIÓN
Generado: $(date)

URL DE ACCESO: $BASE_URL/public/

ADMINISTRADOR:
Usuario: $ADMIN_USER
Email: $ADMIN_EMAIL
Contraseña: $ADMIN_PASS

BASE DE DATOS:
Host: $DB_HOST:$DB_PORT
Base de datos: $DB_NAME
Usuario: $DB_USER
Contraseña: $DB_PASS

IMPORTANTE: Elimina este archivo después de guardar las credenciales en un lugar seguro.
EOF
    chmod 600 "$creds_file"
    print_info "Credenciales guardadas en: $creds_file"
}

# Función principal
main() {
    echo -e "${BLUE}"
    cat << "EOF"
    ╔═══════════════════════════════════════════════════════════╗
    ║                                                           ║
    ║   ███╗   ███╗██╗███╗   ███╗██╗██████╗                   ║
    ║   ████╗ ████║██║████╗ ████║██║██╔══██╗                  ║
    ║   ██╔████╔██║██║██╔████╔██║██║██████╔╝                  ║
    ║   ██║╚██╔╝██║██║██║╚██╔╝██║██║██╔══██╗                  ║
    ║   ██║ ╚═╝ ██║██║██║ ╚═╝ ██║██║██║  ██║                  ║
    ║   ╚═╝     ╚═╝╚═╝╚═╝     ╚═╝╚═╝╚═╝  ╚═╝                  ║
    ║                                                           ║
    ║        Script de Instalación Automática                  ║
    ║                                                           ║
    ╚═══════════════════════════════════════════════════════════╝
EOF
    echo -e "${NC}\n"
    
    check_root
    detect_os
    install_requirements
    check_requirements
    gather_config_info
    create_database
    import_schema
    create_admin_user
    create_config_file
    create_directories
    set_permissions
    setup_cron
    show_completion_info
    
    echo -e "\n${GREEN}¡Gracias por usar Mimir!${NC}\n"
}

# Ejecutar script principal
main
