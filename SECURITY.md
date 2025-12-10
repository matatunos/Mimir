# Mimir - Guía de Seguridad

## 🛡️ Protecciones Implementadas

Esta documentación describe todas las medidas de seguridad implementadas en Mimir para proteger contra ataques comunes.

---

## 📋 Índice

1. [SQL Injection](#sql-injection)
2. [XSS (Cross-Site Scripting)](#xss-cross-site-scripting)
3. [CSRF (Cross-Site Request Forgery)](#csrf-cross-site-request-forgery)
4. [Path Traversal](#path-traversal)
5. [File Upload Attacks](#file-upload-attacks)
6. [Rate Limiting](#rate-limiting)
7. [HTTP Security Headers](#http-security-headers)
8. [Session Security](#session-security)
9. [Password Security](#password-security)
10. [Forensic Logging](#forensic-logging)

---

## 1. SQL Injection

### ✅ Protecciones Aplicadas

**PDO Prepared Statements**: Todas las consultas SQL usan prepared statements con placeholders.

```php
// ✅ CORRECTO
$stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);

// ❌ INCORRECTO (NUNCA HACER ESTO)
$query = "SELECT * FROM users WHERE username = '$username'";
```

**Detección Automática**: La clase `SecurityValidator` detecta patrones de SQL injection.

```php
$security->detectSQLInjection($input); // Returns true si detecta patrones sospechosos
```

**Logging**: Todos los intentos de SQL injection se registran en `security_events`.

### 🔍 Patrones Detectados

- `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `DROP`, `UNION`, `EXEC`
- Comentarios SQL: `--`, `/* */`
- Operadores: `OR 1=1`, `AND 1=1`
- Comillas en contextos sospechosos

---

## 2. XSS (Cross-Site Scripting)

### ✅ Protecciones Aplicadas

**HTML Escaping**: Todo output se escapa con `htmlspecialchars()`.

```php
// ✅ CORRECTO
echo htmlspecialchars($user['username'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

// ❌ INCORRECTO
echo $user['username'];
```

**Content Security Policy (CSP)**: Headers que bloquean scripts inline no autorizados.

```
Content-Security-Policy: 
  default-src 'self';
  script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net;
  style-src 'self' 'unsafe-inline';
  object-src 'none';
```

**Sanitización de HTML**: La clase `SecurityValidator` permite HTML solo con tags seguros.

```php
$security->sanitizeString($input, $allowHTML = false);
```

**Detección Automática**: Detecta patrones de XSS.

```php
$security->detectXSS($input); // Detecta <script>, javascript:, onclick=, etc.
```

### 🔍 Patrones Detectados

- `<script>`, `<iframe>`, `<object>`, `<embed>`
- `javascript:` URLs
- Event handlers: `onclick`, `onload`, `onerror`, etc.
- Inline styles sospechosos

---

## 3. CSRF (Cross-Site Request Forgery)

### ✅ Protecciones Aplicadas

**CSRF Tokens**: Todos los formularios incluyen tokens únicos.

```php
// Generar token
$token = $auth->generateCsrfToken();

// En el formulario
<input type="hidden" name="csrf_token" value="<?php echo $token; ?>">

// Validar
if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
    die('Token de seguridad inválido');
}
```

**SameSite Cookies**: Las cookies tienen atributo `SameSite=None` para autenticación externa (Duo).

```php
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', 1);
```

**Validación en POST**: Todos los endpoints POST validan el token CSRF.

### 📝 Archivos Protegidos

- ✅ `login.php` - Ya usa Auth class con CSRF
- ✅ `upload.php` - Validación CSRF implementada
- ✅ `share.php` - Validación CSRF implementada
- ✅ `admin/config.php` - Validación CSRF implementada
- ✅ `admin/user_actions.php` - Validación CSRF implementada

---

## 4. Path Traversal

### ✅ Protecciones Aplicadas

**Validación de Rutas**: Método `validateFilePath()` en `SecurityValidator`.

```php
$validPath = $security->validateFilePath($filename, UPLOADS_PATH);

if (!$validPath) {
    // Path traversal attempt blocked
    error_log("Path traversal blocked: $filename");
    return false;
}
```

**Realpath Verification**: Verifica que el archivo esté dentro del directorio permitido.

```php
$realPath = realpath($file['file_path']);
$realUploadsPath = realpath(UPLOADS_PATH);

if (strpos($realPath, $realUploadsPath) !== 0) {
    // Outside allowed directory!
    return false;
}
```

**Basename**: Usa `basename()` para eliminar componentes de ruta.

```php
$filename = basename($_FILES['file']['name']);
```

### 🔍 Patrones Bloqueados

- `../`, `..\\`
- `%2e%2e` (encoded dots)
- `/etc/passwd`, `/etc/shadow`
- Rutas absolutas fuera del webroot

---

## 5. File Upload Attacks

### ✅ Protecciones Aplicadas

**MIME Type Verification**: Detecta el MIME real del archivo, no el reportado por el navegador.

```php
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $fileData['tmp_name']);
finfo_close($finfo);
```

**Extension Whitelist/Blacklist**: Solo permite extensiones configuradas.

```php
$security->validateFileExtension($filename, $allowedExtensions);
```

**Extension Blocklist**: Bloquea extensiones peligrosas incluso con wildcard (*).

```php
$blocked = ['php', 'phtml', 'php3', 'php4', 'php5', 'exe', 'bat', 'cmd', 
            'sh', 'bash', 'js', 'vbs', 'jar', 'app', 'msi', 'scr'];
```

**MIME Validation**: Verifica que el MIME coincida con la extensión.

```php
$this->validateMimeType($mimeType, $extension);
```

**File Size Limits**: Valida tamaño máximo configurable.

```php
if ($fileSize > $maxFileSize) {
    // Log security event
    throw new Exception("Archivo demasiado grande");
}
```

**Filename Sanitization**: Limpia nombres de archivo peligrosos.

```php
$security->validateFilename($originalName);
```

**Storage Outside Webroot**: Archivos se almacenan fuera del public root.

```
/opt/Mimir/storage/uploads/{user_id}/{unique_file}
```

**Unique Filenames**: Nombres únicos para prevenir overwrites.

```php
$storedName = uniqid() . '_' . time() . '.' . $ext;
```

### 🚫 Tipos de Archivo Bloqueados

- **Scripts**: PHP, Python, Bash, JavaScript, VBS
- **Ejecutables**: EXE, BAT, CMD, COM, MSI, APP, SCR
- **Archives peligrosos**: JAR (puede contener malware)

---

## 6. Rate Limiting

### ✅ Protecciones Aplicadas

**Login Rate Limiting**: Máximo 5 intentos fallidos por IP en 15 minutos.

```php
if (!$security->checkIPRateLimit($clientIP, 'failed_login', 5, 15)) {
    $error = 'Demasiados intentos fallidos...';
}
```

**Share Download Rate Limiting**: Máximo 20 descargas por IP por hora.

```php
if (!$security->checkIPRateLimit($clientIP, 'share_download', 20, 60)) {
    $error = 'Demasiados intentos...';
}
```

**Brute Force Protection**: Sleep de 2 segundos después de login fallido.

```php
if ($loginFailed) {
    sleep(2); // Slow down brute force attacks
}
```

**Database Tracking**: Los intentos se registran en `security_events`.

```sql
SELECT COUNT(*) FROM security_events
WHERE ip_address = ? 
AND event_type = 'failed_login'
AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
```

## Cambios recientes: campo `security_events.username` y límites por IP configurables

- Se añadió la columna `username` en la tabla `security_events` para mejorar precisión en auditoría y conteo de intentos fallidos por usuario.
- Las comprobaciones por IP usan ahora valores configurables en la tabla `config`:
    - `ip_rate_limit_threshold` (int, por defecto 5)
    - `ip_rate_limit_window_minutes` (int, por defecto 15)

Pasos de migración/rollback rápidos

1. Hacer backup de la base de datos:

```bash
mysqldump -h <host> -u <user> -p<pass> <db> > /tmp/mimir_backup.sql
```

2. Aplicar migración que añade la columna (si no existe):

```sql
ALTER TABLE security_events ADD COLUMN username VARCHAR(100) NULL AFTER event_type;
```

3. Rellenar datos históricos con el script PHP incluido:

```bash
php tools/backfill_username.php
```

4. (Opcional) Añadir índice para consultas por usuario:

```sql
ALTER TABLE security_events ADD KEY idx_username (username);
```

El repositorio incluye un helper `scripts/migrate_security_events.sh` que automatiza backup + migración + backfill + creación del índice.

---

## 7. HTTP Security Headers

### ✅ Headers Implementados

**Content-Security-Policy (CSP)**:
```
default-src 'self';
script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net;
style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
font-src 'self' https://fonts.gstatic.com;
img-src 'self' data: https:;
object-src 'none';
frame-ancestors 'self';
base-uri 'self';
form-action 'self';
upgrade-insecure-requests;
```

**X-Frame-Options**: Previene clickjacking
```
X-Frame-Options: SAMEORIGIN
```

**X-Content-Type-Options**: Previene MIME sniffing
```
X-Content-Type-Options: nosniff
```

**Referrer-Policy**: Controla información de referrer
```
Referrer-Policy: strict-origin-when-cross-origin
```

**Strict-Transport-Security (HSTS)**: Fuerza HTTPS
```
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
```

**X-XSS-Protection**: Protección legacy XSS
```
X-XSS-Protection: 1; mode=block
```

**Permissions-Policy**: Controla APIs del navegador
```
Permissions-Policy: geolocation=(self), microphone=(), camera=()
```

### 📄 Implementación

Aplicados globalmente en `includes/layout.php`:

```php
SecurityHeaders::applyAll();
```

---

## 8. Session Security

### ✅ Protecciones Aplicadas

**HttpOnly Cookies**: Las cookies no son accesibles via JavaScript.

```php
ini_set('session.cookie_httponly', 1);
```

**Secure Cookies**: Solo se envían sobre HTTPS.

```php
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
```

**Session Regeneration**: Se regenera el ID periódicamente.

```php
if (time() - $_SESSION['created'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}
```

**Session Timeouts**: Sesiones expiran después de inactividad.

```php
define('SESSION_LIFETIME', 3600); // 1 hour
```

**Device Tracking**: Guarda IP y user agent para detectar hijacking.

```php
$_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
$_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
```

---

## 9. Password Security

### ✅ Protecciones Aplicadas

**Bcrypt Hashing**: Usa bcrypt para hashear contraseñas.

```php
$hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
```

**Password Verification**: Verifica con `password_verify()`.

```php
if (password_verify($inputPassword, $hashedPassword)) {
    // Password correct
}
```

**Password Strength Validation**: Valida complejidad.

```php
$validation = $security->validatePassword($password, 8);

// Checks:
// - Minimum 8 characters
// - At least one number
// - At least one letter
// - Not in common password list
```

**Common Password Blocklist**:
- password, 12345678, qwerty, abc123, letmein, welcome, monkey, password123

---

## 10. Forensic Logging

### ✅ Logging Implementado

**Security Events**: Todos los eventos de seguridad se registran.

```sql
CREATE TABLE security_events (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    event_type ENUM('failed_login', 'brute_force', 'suspicious_download', 
                    'rate_limit', 'unauthorized_access', 'data_breach_attempt',
                    'malware_upload', 'path_traversal_attempt'),
    severity ENUM('low', 'medium', 'high', 'critical'),
    user_id INT,
    ip_address VARCHAR(45),
    user_agent VARCHAR(500),
    description TEXT,
    details JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Download Logging**: Tracking completo de descargas (36 campos).

```sql
- ip_address, user_agent, referer
- browser, browser_version, os, os_version
- device_type, device_brand, device_model
- country_code, country_name, city, latitude, longitude
- isp, bytes_transferred, download_duration
- http_status_code, checksum_verified
```

**Activity Logging**: Todas las acciones de usuarios.

```sql
- user_id, action, entity_type, entity_id
- description, ip_address, user_agent, metadata
```

---

## 🚨 Eventos de Seguridad Monitoreados

| Evento | Severity | Descripción |
|--------|----------|-------------|
| `failed_login` | Low | Intento de login fallido |
| `brute_force` | High | Múltiples intentos fallidos detectados |
| `path_traversal_attempt` | Critical | Intento de acceder fuera del directorio permitido |
| `invalid_file_extension` | Medium | Intento de subir extensión bloqueada |
| `file_too_large` | Low | Archivo excede tamaño máximo |
| `sql_injection_attempt` | Critical | Patrones de SQL injection detectados |
| `xss_attempt` | High | Patrones de XSS detectados |
| `rate_limit` | Medium | Rate limit aplicado |
| `unauthorized_access` | High | Intento de acceso no autorizado |
| `suspicious_download` | Medium | Descarga sospechosa detectada |

---

## 🔒 Archivos Protegidos con .htaccess

### `/includes/.htaccess`
- Niega acceso a archivos de configuración
- Previene directory listing
- Aplica security headers
- Configura PHP security settings

### `/storage/.htaccess`
- Niega TODO acceso HTTP al directorio storage
- Los archivos solo se sirven via download.php

### `/database/.htaccess`
- Niega TODO acceso a archivos de base de datos y migraciones

---

## 📝 Checklist de Seguridad

### ✅ Autenticación
- [x] Passwords hasheadas con bcrypt
- [x] Rate limiting en login (5 intentos / 15 min)
- [x] Brute force protection (sleep 2s)
- [x] 2FA con TOTP y Duo
- [x] Trusted devices
- [x] Failed login logging

### ✅ Autorización
- [x] Role-based access control (admin/user)
- [x] File ownership verification
- [x] Share token validation
- [x] Admin-only endpoints protected

### ✅ Input Validation
- [x] All GET/POST/COOKIE inputs sanitized
- [x] SQL injection detection
- [x] XSS detection
- [x] Path traversal prevention
- [x] Filename validation
- [x] CSRF tokens on all forms

### ✅ File Security
- [x] MIME type verification con finfo
- [x] Extension whitelist/blacklist
- [x] File size limits
- [x] Unique filenames
- [x] Storage outside webroot
- [x] Path validation before read/write

### ✅ Session Security
- [x] HttpOnly cookies
- [x] Secure cookies (HTTPS)
- [x] Session regeneration
- [x] Device tracking
- [x] Timeout configuration

### ✅ HTTP Security
- [x] Content-Security-Policy
- [x] X-Frame-Options
- [x] X-Content-Type-Options
- [x] Strict-Transport-Security
- [x] Referrer-Policy
- [x] X-XSS-Protection
- [x] Permissions-Policy

### ✅ Logging & Monitoring
- [x] Security events table
- [x] Failed login tracking
- [x] Download forensics (36 fields)
- [x] Activity logging
- [x] Rate limit tracking

---

## 🛠️ Herramientas de Seguridad

### SecurityValidator Class

```php
$security = SecurityValidator::getInstance();

// Input sanitization
$clean = $security->sanitizeString($input);
$clean = $security->escapeHTML($output);

// Validation
$security->validateEmail($email);
$security->validateURL($url);
$security->validateInt($num, $min, $max);
$security->validateFilePath($path, $baseDir);
$security->validateFilename($filename);
$security->validateFileExtension($filename, $allowed);
$security->validateUsername($username);
$security->validatePassword($password);

// Detection
$security->detectSQLInjection($input);
$security->detectXSS($input);

// Rate limiting
$security->checkRateLimit($identifier, $max, $window);
$security->checkIPRateLimit($ip, $action, $max, $windowMins);

// Utilities
$security->generateToken(32);
$security->sanitizeOrderBy($column, $allowedColumns);
$security->sanitizeDirection($direction);
```

### SecurityHeaders Class

```php
// Apply all headers
SecurityHeaders::applyAll();

// Individual headers
SecurityHeaders::setContentSecurityPolicy();
SecurityHeaders::setXFrameOptions('SAMEORIGIN');
SecurityHeaders::setXContentTypeOptions();
SecurityHeaders::setReferrerPolicy();
SecurityHeaders::setStrictTransportSecurity();

// Download headers
SecurityHeaders::setDownloadHeaders($filename, $mimeType, $attachment);

// JSON headers
SecurityHeaders::setJSONHeaders();
```

---

## 🧪 Testing de Seguridad

### SQL Injection Tests

```bash
# Test login con SQL injection
curl -X POST http://mimir.local/login.php \
  -d "username=admin' OR '1'='1&password=test"

# Should log security event and block
```

### XSS Tests

```bash
# Test XSS en filename
curl -X POST http://mimir.local/user/upload.php \
  -F "file=@test.txt;filename=<script>alert('XSS')</script>.txt"

# Should be sanitized
```

### Path Traversal Tests

```bash
# Test path traversal
curl http://mimir.local/user/download.php?id=1&file=../../etc/passwd

# Should be blocked and logged
```

### Rate Limiting Tests

```bash
# Test login rate limit
for i in {1..10}; do
    curl -X POST http://mimir.local/login.php \
      -d "username=test&password=wrong"
done

# After 5 attempts, should block for 15 minutes
```

---

## 📞 Contacto

Para reportar vulnerabilidades de seguridad:
- **NO** crear issues públicos en GitHub
- Enviar email a: security@mimir.local
- Usar PGP si es posible

---

**Última actualización:** Diciembre 2024  
**Versión:** 2.0 (Security Hardened)
