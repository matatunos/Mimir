# Mimir - Database Schema Documentation

## Overview

La base de datos de Mimir utiliza MySQL/MariaDB con charset UTF-8 (utf8mb4_unicode_ci) para soporte completo de caracteres internacionales.

## Archivos del esquema

### `complete_schema.sql` ⭐ RECOMENDADO
**Esquema completo consolidado** que incluye todas las tablas, índices y migraciones integradas.

Este archivo es el **recomendado para instalaciones nuevas** ya que contiene:
- ✅ 11 tablas completas
- ✅ 67 índices (11 PRIMARY, 5 UNIQUE, 38 regulares, 13 FOREIGN KEYS)
- ✅ Tablas de autenticación 2FA (user_2fa, 2fa_attempts)
- ✅ Tablas de forensic logging (download_log, security_events)
- ✅ Usuario admin por defecto (usuario: `admin`, contraseña: `admin123`)
- ✅ 50+ parámetros de configuración por defecto

**Uso:**
```bash
mysql -u mimir_user -p mimir < complete_schema.sql
```

### `schema.sql` (Legacy)
Esquema base original que contiene solo las 7 tablas principales. 

**⚠️ NOTA:** Si usas este archivo, debes aplicar las migraciones manualmente después.

Incluye:
- users
- files
- shares
- share_access_log
- activity_log
- sessions
- config

### Archivos de migración

Estos archivos se usan para actualizar bases de datos existentes. **NO son necesarios para instalaciones nuevas usando `complete_schema.sql`**.

#### `migration_2fa.sql`
Añade soporte para autenticación de dos factores (TOTP y Duo):
- Tabla `user_2fa` - Configuración 2FA por usuario
- Tabla `2fa_attempts` - Log de intentos de autenticación
- Columnas en `users`: `require_2fa`, `trusted_devices`
- Parámetros de configuración 2FA

**Aplicar:**
```bash
mysql -u mimir_user -p mimir < migration_2fa.sql
```

#### `migration_orphan_files.sql`
Modifica la tabla `files` para permitir archivos huérfanos:
- Cambia `user_id` a NULL permitido
- Modifica la clave foránea a `ON DELETE SET NULL`
- Añade índice para consultas de archivos huérfanos

**Aplicar:**
```bash
mysql -u mimir_user -p mimir < migration_orphan_files.sql
```

#### `migrations/add_forensic_fields.sql`
Añade capacidades avanzadas de logging forense:
- Tabla `download_log` - Tracking detallado de descargas (IP, geolocalización, dispositivo, navegador, duración)
- Tabla `security_events` - Registro de eventos de seguridad
- Campos adicionales en `share_access_log` y `activity_log`

**Aplicar:**
```bash
mysql -u mimir_user -p mimir < migrations/add_forensic_fields.sql
```

## Estructura de tablas

### 1. `users` (Usuarios del sistema)
**Propósito:** Almacena información de usuarios y autenticación.

**Columnas principales:**
- `id` - ID único del usuario
- `username` - Nombre de usuario único
- `email` - Email único
- `password` - Hash bcrypt de la contraseña (NULL para usuarios LDAP/AD)
- `role` - Rol: 'admin' o 'user'
- `is_ldap` - Boolean: indica si es usuario LDAP/AD
- `require_2fa` - Boolean: fuerza 2FA para este usuario
- `trusted_devices` - JSON: array de hashes de dispositivos confiables
- `storage_quota` - Cuota de almacenamiento en bytes (NULL = ilimitado)
- `storage_used` - Espacio usado actualmente

**Índices:**
- PRIMARY KEY (id)
- UNIQUE (username, email)
- INDEX (role, is_active, require_2fa)

---

### 2. `user_2fa` (Configuración de 2FA)
**Propósito:** Almacena la configuración de autenticación de dos factores por usuario.

**Columnas principales:**
- `user_id` - FK a users (UNIQUE)
- `method` - Método: 'none', 'totp', 'duo'
- `totp_secret` - Secreto TOTP encriptado
- `duo_username` - Username en Duo Security
- `backup_codes` - JSON: array de códigos de respaldo hasheados
- `is_enabled` - Boolean: 2FA actualmente activo
- `grace_period_until` - Permite deshabilitar sin código hasta esta fecha

---

### 3. `2fa_attempts` (Log de intentos 2FA)
**Propósito:** Auditoría de todos los intentos de autenticación 2FA.

**Columnas principales:**
- `user_id` - FK a users
- `method` - Método usado: 'totp', 'duo', 'backup'
- `success` - Boolean: éxito o fallo
- `ip_address` - IP del intento
- `error_message` - Mensaje de error si falló

**Uso:** Detección de ataques de fuerza bruta, análisis de seguridad.

---

### 4. `files` (Archivos subidos)
**Propósito:** Registro de todos los archivos almacenados en el sistema.

**Columnas principales:**
- `user_id` - FK a users (NULL permitido para archivos huérfanos)
- `original_name` - Nombre original del archivo
- `stored_name` - Nombre único en el sistema de archivos
- `file_path` - Ruta completa al archivo
- `file_size` - Tamaño en bytes
- `mime_type` - Tipo MIME detectado
- `file_hash` - SHA256 para deduplicación
- `is_shared` - Boolean: archivo compartido actualmente

**ON DELETE:** Si se elimina un usuario, `user_id` se pone a NULL (archivos huérfanos).

---

### 5. `shares` (Enlaces de compartición)
**Propósito:** Gestión de enlaces públicos para compartir archivos.

**Columnas principales:**
- `file_id` - FK a files
- `share_token` - Token único para el enlace (64 caracteres)
- `password` - Hash de contraseña opcional
- `max_downloads` - Límite de descargas (NULL = ilimitado)
- `download_count` - Contador actual
- `expires_at` - Fecha de expiración
- `is_active` - Boolean: enlace activo
- `created_by` - FK a users (creador del share)

**ON DELETE:** 
- Si se elimina el archivo → se elimina el share (CASCADE)
- Si se elimina el usuario creador → se elimina el share (CASCADE)

---

### 6. `share_access_log` (Log de accesos a shares)
**Propósito:** Auditoría básica de accesos a enlaces públicos.

**Columnas principales:**
- `share_id` - FK a shares
- `ip_address` - IP del visitante
- `user_agent` - Navegador/dispositivo
- `action` - 'view' o 'download'
- `accessed_at` - Timestamp del acceso

---

### 7. `download_log` (Forensic logging de descargas)
**Propósito:** Tracking detallado de todas las descargas para análisis forense.

**Columnas principales:**
- `file_id` - FK a files
- `share_id` - FK a shares (puede ser NULL)
- `user_id` - FK a users (puede ser NULL si es anónimo)
- `ip_address` - IP del descargador
- `browser`, `browser_version` - Información del navegador
- `os`, `os_version` - Sistema operativo
- `device_type` - 'desktop', 'mobile', 'tablet', 'bot', 'unknown'
- `is_bot` - Boolean: detectado como bot
- `country_code`, `country_name`, `city` - Geolocalización
- `latitude`, `longitude` - Coordenadas GPS
- `isp` - Proveedor de internet
- `bytes_transferred` - Bytes realmente descargados
- `download_started_at`, `download_completed_at`, `download_duration` - Timing
- `http_status_code` - Código de respuesta HTTP
- `checksum_verified` - Boolean: integridad verificada

**Uso:** Análisis de patrones de descarga, detección de fraude, cumplimiento normativo.

---

### 8. `security_events` (Eventos de seguridad)
**Propósito:** Registro centralizado de eventos sospechosos y amenazas.

**Columnas principales:**
- `event_type` - Tipo: 'failed_login', 'brute_force', 'suspicious_download', 'rate_limit', 'unauthorized_access', 'data_breach_attempt', 'malware_upload'
- `severity` - 'low', 'medium', 'high', 'critical'
- `user_id` - FK a users (puede ser NULL)
- `ip_address` - IP del atacante
- `description` - Descripción del evento
- `details` - JSON con datos adicionales
- `action_taken` - Acción tomada por el sistema
- `resolved` - Boolean: incidente resuelto
- `resolved_by` - FK a users (admin que resolvió)

**Uso:** SOC (Security Operations Center), respuesta a incidentes, compliance.

---

### 9. `activity_log` (Log general de actividades)
**Propósito:** Registro de todas las acciones de usuarios en el sistema.

**Columnas principales:**
- `user_id` - FK a users
- `action` - Acción realizada (ej: 'upload', 'delete', 'share_create')
- `entity_type` - Tipo de entidad afectada ('file', 'share', 'user', 'config')
- `entity_id` - ID de la entidad
- `description` - Descripción legible
- `metadata` - JSON con datos adicionales

---

### 10. `sessions` (Sesiones de usuario)
**Propósito:** Gestión de sesiones activas.

**Columnas principales:**
- `id` - Session ID (128 caracteres)
- `user_id` - FK a users
- `ip_address` - IP de la sesión
- `data` - Datos de la sesión (serializado)
- `last_activity` - Última actividad

**Limpieza:** Se recomienda limpiar sesiones antiguas periódicamente.

---

### 11. `config` (Configuración del sistema)
**Propósito:** Almacenamiento clave-valor de toda la configuración.

**Columnas principales:**
- `config_key` - Clave única (ej: 'max_file_size')
- `config_value` - Valor (text)
- `config_type` - Tipo: 'string', 'number', 'boolean', 'json'
- `is_system` - Boolean: las configs del sistema no se pueden eliminar

**Parámetros importantes:**
- `max_file_size` - Tamaño máximo de archivo (bytes)
- `allowed_extensions` - Extensiones permitidas (CSV)
- `enable_ldap`, `enable_ad` - Autenticación externa
- `duo_*` - Configuración de Duo Security
- `2fa_*` - Parámetros de 2FA

---

## Relaciones e integridad referencial

```
users (1) ←→ (1) user_2fa
users (1) → (N) 2fa_attempts
users (1) → (N) files [ON DELETE SET NULL - archivos huérfanos]
users (1) → (N) shares [ON DELETE CASCADE]
users (1) → (N) activity_log [ON DELETE SET NULL]
users (1) → (N) sessions [ON DELETE CASCADE]

files (1) → (N) shares [ON DELETE CASCADE]
files (1) → (N) download_log [ON DELETE CASCADE]

shares (1) → (N) share_access_log [ON DELETE CASCADE]
shares (1) → (N) download_log [ON DELETE SET NULL]

users (1) → (N) security_events [ON DELETE SET NULL]
users (1) → (N) security_events.resolved_by [ON DELETE SET NULL]
```

## Índices de rendimiento

El esquema incluye **67 índices** optimizados para las siguientes operaciones:

### Búsquedas frecuentes:
- `users`: role, is_active, require_2fa
- `files`: is_shared, file_hash, created_at, user_id
- `shares`: is_active, expires_at, share_token (UNIQUE)
- `download_log`: ip_address, country_code, device_type, is_bot, created_at
- `security_events`: event_type, severity, ip_address, resolved, created_at
- `activity_log`: action, entity_type, created_at

### Joins:
- Todas las foreign keys tienen índices automáticos

### Índices compuestos:
- `2fa_attempts`: (user_id, attempted_at) - Para consultas temporales por usuario
- `download_log`: (download_started_at) - Para análisis de tráfico

## Tamaño estimado de la base de datos

**Base inicial (vacía):** ~2 MB
**Por usuario:** ~10 KB
**Por archivo:** ~2 KB + tamaño del archivo en disco
**Por share:** ~1 KB
**Por descarga (forensic log):** ~5 KB

**Ejemplo con 1000 usuarios y 100,000 archivos:**
- Metadata: ~200 MB
- Archivos en disco: Variable (depende del contenido)
- Logs forenses (1 año): ~500 MB - 2 GB

## Mantenimiento recomendado

### Limpieza periódica

```sql
-- Eliminar sesiones antiguas (>7 días)
DELETE FROM sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- Eliminar logs de acceso antiguos (>1 año)
DELETE FROM share_access_log WHERE accessed_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);

-- Eliminar shares expirados inactivos
DELETE FROM shares WHERE expires_at < NOW() AND is_active = 0;

-- Archivar download_log antiguo (>6 meses) a tabla de archivo
INSERT INTO download_log_archive SELECT * FROM download_log 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH);
DELETE FROM download_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH);
```

### Optimización de tablas

```sql
-- Optimizar tablas grandes
OPTIMIZE TABLE files;
OPTIMIZE TABLE download_log;
OPTIMIZE TABLE activity_log;

-- Analizar índices
ANALYZE TABLE files, shares, users, download_log;
```

## Backup recomendado

```bash
# Backup completo
mysqldump -u mimir_user -p --single-transaction --routines --triggers \
  mimir > mimir_backup_$(date +%Y%m%d_%H%M%S).sql

# Backup solo estructura
mysqldump -u mimir_user -p --no-data mimir > mimir_schema_backup.sql

# Backup solo datos
mysqldump -u mimir_user -p --no-create-info mimir > mimir_data_backup.sql
```

## Migración de versiones

Si ya tienes una instalación de Mimir con `schema.sql` antiguo:

```bash
# 1. Hacer backup
mysqldump -u mimir_user -p mimir > backup_before_migration.sql

# 2. Aplicar migraciones en orden
mysql -u mimir_user -p mimir < migration_2fa.sql
mysql -u mimir_user -p mimir < migration_orphan_files.sql
mysql -u mimir_user -p mimir < migrations/add_forensic_fields.sql

# 3. Verificar integridad
mysql -u mimir_user -p mimir -e "SHOW TABLES;"
mysql -u mimir_user -p mimir -e "SELECT COUNT(*) AS total_tables FROM information_schema.tables WHERE table_schema = 'mimir';"
```

Deberías ver **11 tablas** en total.

## Seguridad

### Usuario de base de datos
El usuario `mimir_user` debe tener **solo** estos privilegios:
```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON mimir.* TO 'mimir_user'@'localhost';
```

**NO dar:**
- DROP (eliminar tablas)
- CREATE (crear tablas)
- ALTER (modificar estructura)
- GRANT (dar permisos)

### Datos sensibles
- ✅ Las contraseñas se almacenan hasheadas con bcrypt
- ✅ Los secretos TOTP están encriptados
- ✅ Los códigos de respaldo 2FA están hasheados
- ⚠️ `duo_client_secret` y `smtp_password` en la tabla `config` están en texto plano
  - Considera encriptarlos con funciones AES de MySQL
  - O gestiónalos mediante variables de entorno

### SQL Injection
Todas las consultas en las clases PHP utilizan **prepared statements** con PDO para prevenir SQL injection.

## Soporte

Para problemas o preguntas sobre el esquema de base de datos:
- Revisar logs: `/opt/Mimir/storage/logs/`
- Verificar integridad: `mysqlcheck -u mimir_user -p --check --databases mimir`
- Reparar tablas: `mysqlcheck -u mimir_user -p --repair --databases mimir`

---

**Última actualización:** Diciembre 2024  
**Versión del esquema:** 2.0 (completo con forensics)
