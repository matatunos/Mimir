#!/bin/bash
#####################################################################
# Mimir Database Verification Script
# Verifica la integridad y completitud del esquema de base de datos
#####################################################################

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

DB_NAME="mimir"
REQUIRED_TABLES=11
REQUIRED_FOREIGN_KEYS=13

print_header() {
    echo -e "${BLUE}=========================================="
    echo "  Mimir - Database Verification"
    echo -e "==========================================${NC}"
    echo
}

print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

print_info() {
    echo -e "${YELLOW}ℹ${NC} $1"
}

check_database_exists() {
    print_info "Verificando existencia de base de datos '${DB_NAME}'..."
    
    DB_EXISTS=$(sudo mysql -e "SHOW DATABASES LIKE '${DB_NAME}';" | grep -c "${DB_NAME}" || true)
    
    if [ "$DB_EXISTS" -eq 0 ]; then
        print_error "Base de datos '${DB_NAME}' no existe"
        return 1
    fi
    
    print_success "Base de datos '${DB_NAME}' encontrada"
    return 0
}

check_tables() {
    print_info "Verificando tablas..."
    
    TABLE_COUNT=$(sudo mysql -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_NAME}';" | tail -1)
    
    echo -e "${YELLOW}Tablas esperadas:${NC} ${REQUIRED_TABLES}"
    echo -e "${YELLOW}Tablas encontradas:${NC} ${TABLE_COUNT}"
    
    if [ "$TABLE_COUNT" -ne "$REQUIRED_TABLES" ]; then
        print_error "Número de tablas incorrecto (esperadas: ${REQUIRED_TABLES}, encontradas: ${TABLE_COUNT})"
        echo
        print_info "Tablas actuales:"
        sudo mysql -e "SHOW TABLES FROM ${DB_NAME};"
        return 1
    fi
    
    print_success "Todas las tablas presentes (${TABLE_COUNT}/${REQUIRED_TABLES})"
    
    echo
    print_info "Lista de tablas:"
    sudo mysql ${DB_NAME} -e "SHOW TABLES;" | tail -n +2 | while read table; do
        echo "  • $table"
    done
    
    return 0
}

check_foreign_keys() {
    print_info "Verificando foreign keys..."
    
    FK_COUNT=$(sudo mysql -e "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '${DB_NAME}';" | tail -1)
    
    echo -e "${YELLOW}Foreign keys esperadas:${NC} ${REQUIRED_FOREIGN_KEYS}"
    echo -e "${YELLOW}Foreign keys encontradas:${NC} ${FK_COUNT}"
    
    if [ "$FK_COUNT" -ne "$REQUIRED_FOREIGN_KEYS" ]; then
        print_error "Número de foreign keys incorrecto (esperadas: ${REQUIRED_FOREIGN_KEYS}, encontradas: ${FK_COUNT})"
        return 1
    fi
    
    print_success "Todas las foreign keys presentes (${FK_COUNT}/${REQUIRED_FOREIGN_KEYS})"
    return 0
}

check_indexes() {
    print_info "Verificando índices..."
    
    INDEX_COUNT=$(sudo mysql -e "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '${DB_NAME}' AND INDEX_NAME != 'PRIMARY';" | tail -1)
    
    echo -e "${YELLOW}Índices (no PRIMARY):${NC} ${INDEX_COUNT}"
    
    if [ "$INDEX_COUNT" -lt 40 ]; then
        print_error "Parece que faltan índices (esperados ~50+, encontrados: ${INDEX_COUNT})"
        return 1
    fi
    
    print_success "Índices presentes: ${INDEX_COUNT}"
    return 0
}

check_config_entries() {
    print_info "Verificando entradas de configuración..."
    
    CONFIG_COUNT=$(sudo mysql ${DB_NAME} -e "SELECT COUNT(*) FROM config;" | tail -1)
    
    echo -e "${YELLOW}Parámetros de configuración:${NC} ${CONFIG_COUNT}"
    
    if [ "$CONFIG_COUNT" -lt 40 ]; then
        print_error "Faltan parámetros de configuración (esperados ~50+, encontrados: ${CONFIG_COUNT})"
        return 1
    fi
    
    print_success "Configuración inicializada con ${CONFIG_COUNT} parámetros"
    
    # Verificar parámetros críticos
    echo
    print_info "Verificando parámetros críticos:"
    
    CRITICAL_PARAMS=("max_file_size" "allowed_extensions" "duo_client_id" "enable_ldap" "enable_ad" "ad_host")
    
    for param in "${CRITICAL_PARAMS[@]}"; do
        EXISTS=$(sudo mysql ${DB_NAME} -e "SELECT COUNT(*) FROM config WHERE config_key = '${param}';" | tail -1)
        if [ "$EXISTS" -eq 1 ]; then
            echo -e "  ${GREEN}✓${NC} ${param}"
        else
            echo -e "  ${RED}✗${NC} ${param} ${RED}(FALTA)${NC}"
        fi
    done
    
    return 0
}

check_admin_user() {
    print_info "Verificando usuario admin por defecto..."
    
    ADMIN_EXISTS=$(sudo mysql ${DB_NAME} -e "SELECT COUNT(*) FROM users WHERE username = 'admin' AND role = 'admin';" | tail -1)
    
    if [ "$ADMIN_EXISTS" -eq 0 ]; then
        print_error "Usuario admin por defecto no existe"
        echo
        print_info "Para crear el usuario admin, ejecuta:"
        echo "  INSERT INTO users (username, email, password, full_name, role) VALUES"
        echo "  ('admin', 'admin@mimir.local', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin');"
        echo
        echo "  Contraseña por defecto: admin123"
        return 1
    fi
    
    print_success "Usuario admin existe"
    return 0
}

check_table_details() {
    print_info "Verificando detalles de tablas críticas..."
    
    # Verificar columnas de users
    echo
    echo -e "${YELLOW}Tabla users:${NC}"
    USER_COLUMNS=$(sudo mysql ${DB_NAME} -e "SHOW COLUMNS FROM users;" | tail -n +2 | wc -l)
    echo "  Columnas: ${USER_COLUMNS}"
    
    # Verificar si tiene columnas 2FA
    HAS_2FA=$(sudo mysql ${DB_NAME} -e "SHOW COLUMNS FROM users LIKE 'require_2fa';" | tail -n +2 | wc -l)
    if [ "$HAS_2FA" -eq 1 ]; then
        print_success "Columna 'require_2fa' presente en users"
    else
        print_error "Columna 'require_2fa' NO presente en users (migración 2FA no aplicada)"
    fi
    
    # Verificar tabla download_log (forensics)
    echo
    echo -e "${YELLOW}Tabla download_log (forensics):${NC}"
    DL_EXISTS=$(sudo mysql ${DB_NAME} -e "SHOW TABLES LIKE 'download_log';" | tail -n +2 | wc -l)
    if [ "$DL_EXISTS" -eq 1 ]; then
        DL_COLUMNS=$(sudo mysql ${DB_NAME} -e "SHOW COLUMNS FROM download_log;" | tail -n +2 | wc -l)
        echo "  Columnas: ${DL_COLUMNS}"
        print_success "Tabla download_log presente (forensic logging activo)"
    else
        print_error "Tabla download_log NO presente (migración forensics no aplicada)"
    fi
    
    # Verificar tabla security_events
    echo
    echo -e "${YELLOW}Tabla security_events:${NC}"
    SE_EXISTS=$(sudo mysql ${DB_NAME} -e "SHOW TABLES LIKE 'security_events';" | tail -n +2 | wc -l)
    if [ "$SE_EXISTS" -eq 1 ]; then
        print_success "Tabla security_events presente"
    else
        print_error "Tabla security_events NO presente"
    fi
    
    return 0
}

display_summary() {
    echo
    echo -e "${BLUE}=========================================="
    echo "  Resumen de Verificación"
    echo -e "==========================================${NC}"
    echo
    
    # Obtener estadísticas
    TOTAL_USERS=$(sudo mysql ${DB_NAME} -e "SELECT COUNT(*) FROM users;" 2>/dev/null | tail -1 || echo "0")
    TOTAL_FILES=$(sudo mysql ${DB_NAME} -e "SELECT COUNT(*) FROM files;" 2>/dev/null | tail -1 || echo "0")
    TOTAL_SHARES=$(sudo mysql ${DB_NAME} -e "SELECT COUNT(*) FROM shares;" 2>/dev/null | tail -1 || echo "0")
    TOTAL_2FA=$(sudo mysql ${DB_NAME} -e "SELECT COUNT(*) FROM user_2fa WHERE is_enabled = 1;" 2>/dev/null | tail -1 || echo "0")
    
    echo "Estadísticas de la base de datos:"
    echo "  • Usuarios: ${TOTAL_USERS}"
    echo "  • Archivos: ${TOTAL_FILES}"
    echo "  • Shares activos: ${TOTAL_SHARES}"
    echo "  • Usuarios con 2FA: ${TOTAL_2FA}"
    echo
    
    # Tamaño de la BD
    DB_SIZE=$(sudo mysql -e "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)' FROM information_schema.TABLES WHERE table_schema = '${DB_NAME}';" | tail -1)
    echo "Tamaño de la base de datos: ${DB_SIZE} MB"
    echo
}

# Main verification
main() {
    print_header
    
    ERRORS=0
    
    check_database_exists || ERRORS=$((ERRORS + 1))
    echo
    
    if [ $ERRORS -eq 0 ]; then
        check_tables || ERRORS=$((ERRORS + 1))
        echo
        
        check_foreign_keys || ERRORS=$((ERRORS + 1))
        echo
        
        check_indexes || ERRORS=$((ERRORS + 1))
        echo
        
        check_config_entries || ERRORS=$((ERRORS + 1))
        echo
        
        check_admin_user || ERRORS=$((ERRORS + 1))
        echo
        
        check_table_details || ERRORS=$((ERRORS + 1))
        echo
        
        display_summary
    fi
    
    echo -e "${BLUE}==========================================${NC}"
    
    if [ $ERRORS -eq 0 ]; then
        echo -e "${GREEN}✓ Verificación completada sin errores${NC}"
        echo
        print_info "La base de datos está correctamente instalada y configurada."
        return 0
    else
        echo -e "${RED}✗ Verificación completada con ${ERRORS} error(es)${NC}"
        echo
        print_info "Revisa los errores anteriores y aplica las migraciones necesarias."
        print_info "Consulta database/README.md para más información."
        return 1
    fi
}

main
