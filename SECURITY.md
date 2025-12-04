# Seguridad en Mimir

## Resumen

Este documento describe las medidas de seguridad implementadas en Mimir y las mejores prácticas recomendadas para su despliegue seguro.

## Medidas de Seguridad Implementadas

### 1. Autenticación y Autorización

#### Hash de Contraseñas
- Uso de `password_hash()` con algoritmo PASSWORD_DEFAULT (bcrypt)
- Salt automático y único por contraseña
- Verificación con `password_verify()` que previene timing attacks
- No se almacenan contraseñas en texto plano

```php
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
```

#### Gestión de Sesiones
- Nombre de sesión personalizado (`MIMIR_SESSION`)
- Tiempo de expiración configurable (2 horas por defecto)
- Destrucción completa de sesión al logout
- Regeneración de ID de sesión tras login exitoso (recomendado añadir)

#### Control de Acceso
- Verificación de autenticación en cada página protegida
- Control de roles (usuario vs administrador)
- Validación de propiedad de recursos
- Redirección automática a login si no autenticado

### 2. Protección contra Ataques Comunes

#### SQL Injection
- Uso exclusivo de PDO con prepared statements
- Nunca concatenación directa de SQL con input de usuario
- Binding de parámetros en todas las consultas

```php
$stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);
```

#### XSS (Cross-Site Scripting)
- Escape de HTML en todas las salidas con `htmlspecialchars()`
- Función helper `escapeHtml()` para consistencia
- ENT_QUOTES para proteger atributos
- Charset UTF-8 especificado

```php
echo escapeHtml($userInput);
```

#### CSRF (Cross-Site Request Forgery)
- Tokens CSRF implementados (pendiente activar en formularios)
- Generación de token en sesión
- Verificación con `hash_equals()` para prevenir timing attacks

#### File Upload Vulnerabilities
- Validación de extensiones permitidas
- Lista blanca de tipos de archivo
- Nombres de archivo únicos (no se usa nombre original)
- Almacenamiento fuera de directorio web público
- Detección de tipo MIME
- Límite de tamaño de archivo

### 3. Seguridad de Archivos

#### Almacenamiento Seguro
- Archivos guardados con nombres únicos (UUID + timestamp)
- No se preserva nombre original en sistema de archivos
- Metadatos en base de datos, no en filesystem
- Hash SHA-256 para verificación de integridad

#### Control de Acceso a Archivos
- Validación de propiedad antes de descarga
- Tokens únicos para comparticiones públicas
- Verificación de expiración de enlaces
- Límites de descarga respetados

#### Comparticiones Públicas
- Tokens aleatorios de 32 caracteres (256 bits)
- Uso de `random_bytes()` para generación criptográficamente segura
- Verificación de validez antes de servir archivo
- Desactivación automática al expirar

### 4. Configuración del Servidor

#### Headers de Seguridad HTTP
Incluidos en `.htaccess`:

```apache
Header set X-Content-Type-Options "nosniff"
Header set X-Frame-Options "SAMEORIGIN"
Header set X-XSS-Protection "1; mode=block"
```

#### Restricciones de Acceso
- Archivos de configuración no accesibles vía web
- Directorio `.git` protegido
- Archivos sensibles protegidos

### 5. Base de Datos

#### Conexión Segura
- Credenciales en archivo de configuración fuera de web root
- Uso de PDO con modo de error exception
- Charset UTF-8 especificado
- No emulación de prepared statements

#### Integridad de Datos
- Foreign keys con integridad referencial
- Cascada de eliminación donde apropiado
- Validaciones a nivel de base de datos
- Transacciones para operaciones críticas (recomendado añadir)

### 6. Auditoría y Logging

#### Registro de Actividades
- Todas las acciones importantes registradas
- Captura de IP del cliente
- Captura de User Agent
- Timestamps precisos
- Usuario asociado a cada acción

#### Información Registrada
- Autenticación (login, logout, fallos)
- Operaciones con archivos (upload, download, delete)
- Comparticiones (creación, acceso, desactivación)
- Cambios administrativos
- Actividades del sistema

### 7. Cuotas y Límites

#### Protección contra Abuso
- Cuota de almacenamiento por usuario
- Límite de tamaño de archivo
- Validación antes de permitir upload
- Tiempo máximo de compartición (30 días)

## Mejores Prácticas de Despliegue

### 1. Configuración de Producción

#### Deshabilitar Errores Visibles
```php
error_reporting(0);
ini_set('display_errors', 0);
```

#### Cambiar Credenciales por Defecto
- Cambiar contraseña del admin inmediatamente
- Usar contraseñas fuertes (mínimo 12 caracteres)
- Considerar usar generador de contraseñas

#### Configurar HTTPS
- Obtener certificado SSL/TLS
- Forzar redirección a HTTPS
- Configurar HSTS

```apache
# Force HTTPS
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# HSTS Header
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

### 2. Permisos de Archivos

#### Configuración Recomendada
```bash
# Archivos de código
chmod 644 *.php
chmod 644 includes/*.php
chmod 644 public/*.php

# Directorios
chmod 755 config
chmod 755 includes
chmod 755 public

# Directorios de escritura
chmod 775 uploads
chmod 775 logs

# Propiedad
chown -R www-data:www-data uploads logs
```

### 3. Base de Datos

#### Permisos de Usuario
- Usuario de base de datos con permisos mínimos necesarios
- No usar usuario root
- Solo permisos en base de datos específica

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON mimir.* TO 'mimir_user'@'localhost';
```

#### Respaldo Regular
```bash
# Backup diario
mysqldump -u mimir_user -p mimir > backup_$(date +%Y%m%d).sql
```

### 4. Firewall y Red

#### Configuración de Firewall
```bash
# Permitir solo HTTP/HTTPS
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
```

#### Restricción de Acceso Administrativo
- Considerar restringir acceso admin por IP
- Usar VPN para acceso administrativo
- Implementar rate limiting

### 5. Monitoreo

#### Logs a Revisar
- Logs de acceso de Apache
- Logs de error de Apache
- Logs de PHP
- Logs de auditoría de Mimir
- Intentos fallidos de login

#### Alertas Recomendadas
- Múltiples intentos fallidos de login
- Uso inusual de cuota
- Acceso desde IPs sospechosas
- Errores críticos de aplicación

## Recomendaciones Adicionales

### 1. Autenticación Mejorada

#### Implementar 2FA (Futuro)
- TOTP (Google Authenticator, Authy)
- Códigos de respaldo
- Verificación por email

#### Política de Contraseñas
- Longitud mínima de 8 caracteres (actual: 6)
- Complejidad (mayúsculas, números, símbolos)
- Expiración periódica
- Historial de contraseñas

### 2. Protección Adicional de Archivos

#### Cifrado en Reposo (Futuro)
- Cifrar archivos antes de almacenar
- Clave de cifrado segura
- Cifrado AES-256

#### Escaneo de Malware
- Integración con ClamAV
- Escaneo al subir archivos
- Cuarentena de archivos sospechosos

### 3. Rate Limiting

#### Protección contra Brute Force
```php
// Implementar contador de intentos fallidos
// Bloquear temporalmente después de N intentos
// Usar CAPTCHA después de intentos fallidos
```

#### Protección contra DoS
- Limitar requests por IP
- Usar mod_evasive o fail2ban
- CDN con protección DDoS

### 4. Headers de Seguridad Adicionales

```apache
Header set Content-Security-Policy "default-src 'self'"
Header set Referrer-Policy "strict-origin-when-cross-origin"
Header set Permissions-Policy "geolocation=(), microphone=()"
```

### 5. Auditoría y Compliance

#### Retención de Logs
- Definir política de retención
- Rotación de logs
- Backup de logs importantes

#### GDPR Compliance (Si aplica)
- Derecho al olvido (eliminación de datos)
- Exportación de datos del usuario
- Consentimiento explícito
- Notificación de brechas

## Reporte de Vulnerabilidades

Si encuentras una vulnerabilidad de seguridad:

1. **NO** la publiques públicamente
2. Envía un email a: security@mimir.local (configurar email real)
3. Incluye:
   - Descripción detallada
   - Pasos para reproducir
   - Impacto potencial
   - Sugerencia de solución si la tienes

## Actualizaciones de Seguridad

### Mantenerse Actualizado
- Revisar actualizaciones de PHP
- Actualizar MySQL/MariaDB
- Parches de seguridad de Apache
- Revisar dependencias (si se añaden en futuro)

### Proceso de Actualización
1. Backup completo
2. Probar en ambiente de desarrollo
3. Revisar changelog
4. Aplicar en producción
5. Verificar funcionalidad

## Checklist de Seguridad

### Antes del Despliegue
- [ ] Cambiar contraseña de admin
- [ ] Configurar HTTPS
- [ ] Deshabilitar display_errors
- [ ] Configurar permisos correctos
- [ ] Revisar configuración de firewall
- [ ] Configurar backups automáticos
- [ ] Configurar cron de limpieza
- [ ] Revisar logs de errores
- [ ] Configurar headers de seguridad
- [ ] Deshabilitar registro si no necesario

### Mantenimiento Regular
- [ ] Revisar logs de auditoría
- [ ] Verificar intentos fallidos de login
- [ ] Revisar uso de cuotas
- [ ] Actualizar software del servidor
- [ ] Verificar backups
- [ ] Revisar enlaces expirados
- [ ] Limpiar logs antiguos
- [ ] Revisar usuarios inactivos

## Conclusión

La seguridad es un proceso continuo. Este documento debe revisarse y actualizarse regularmente conforme evolucionan las amenazas y mejores prácticas.

Para preguntas sobre seguridad, consultar la documentación de PHP y OWASP Top 10.
