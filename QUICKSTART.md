# Guía de Inicio Rápido - Mimir

Esta guía te ayudará a tener Mimir funcionando en menos de 5 minutos.

## Requisitos Mínimos

- PHP 7.4+
- MySQL 5.7+ o MariaDB 10.2+
- Apache con mod_rewrite

## Instalación en 5 Pasos

### 1. Descargar el Código

```bash
git clone https://github.com/matatunos/Mimir.git
cd Mimir
```

### 2. Crear Base de Datos

```bash
mysql -u root -p
```

```sql
CREATE DATABASE mimir CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mimir_user'@'localhost' IDENTIFIED BY 'tu_password';
GRANT ALL PRIVILEGES ON mimir.* TO 'mimir_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 3. Configurar

```bash
cp config/config.example.php config/config.php
nano config/config.php  # o usa tu editor favorito
```

Edita estas líneas:
```php
define('DB_USER', 'mimir_user');
define('DB_PASS', 'tu_password');
define('BASE_URL', 'http://localhost'); // o tu dominio
```

### 4. Instalar

```bash
php setup.php
```

### 5. Configurar Permisos

```bash
chmod -R 775 uploads logs
chown -R www-data:www-data uploads logs  # o el usuario de tu servidor web
```

## ¡Listo!

Accede a: `http://localhost` (o tu dominio)

**Credenciales por defecto:**
- Usuario: `admin`
- Contraseña: `admin123`

⚠️ **IMPORTANTE:** Cambia la contraseña del admin inmediatamente.

## Primer Uso

### Como Usuario

1. **Login** con las credenciales por defecto
2. **Cambiar contraseña** (recomendado)
3. **Subir archivo**: Click en "Upload File"
4. **Crear carpeta**: Click en "New Folder"
5. **Compartir**: Click en "Share" junto al archivo
   - Elige "Time-based" para expiración por días
   - O "Downloads" para límite por descargas
6. **Copiar enlace** y compartir

### Como Administrador

1. **Ir a Admin** en el menú superior
2. **Ver Dashboard** con estadísticas
3. **Gestionar Usuarios**: Pestaña "Users"
4. **Configurar Sistema**: Pestaña "Settings"
   - Ajusta límites de archivo
   - Configura SMTP para emails
   - Modifica cuotas de almacenamiento
5. **Ver Auditoría**: Pestaña "Audit Log"

## Configuración Opcional

### Limpieza Automática (Cron)

```bash
crontab -e
```

Añade:
```
0 * * * * php /ruta/completa/a/Mimir/cron/cleanup.php
```

### Configurar SMTP (Emails)

1. Ir a Admin → Settings
2. Configurar:
   - smtp_host: `smtp.gmail.com` (o tu servidor)
   - smtp_port: `587`
   - smtp_username: `tu@email.com`
   - smtp_password: `tu_password`
   - enable_email_notifications: `true`

### HTTPS (Producción)

```bash
# Obtener certificado Let's Encrypt
sudo certbot --apache -d tu-dominio.com
```

## Comandos Útiles

### Backup

```bash
# Base de datos
mysqldump -u mimir_user -p mimir > backup.sql

# Archivos
tar -czf mimir_files.tar.gz uploads/
```

### Restaurar Backup

```bash
# Base de datos
mysql -u mimir_user -p mimir < backup.sql

# Archivos
tar -xzf mimir_files.tar.gz
```

### Ver Logs

```bash
# Logs de Mimir
tail -f logs/*.log

# Logs de Apache
tail -f /var/log/apache2/error.log
```

## Problemas Comunes

### No se conecta a base de datos

✓ Verifica credenciales en `config/config.php`
✓ Verifica que MySQL esté corriendo: `systemctl status mysql`

### No se suben archivos

✓ Verifica permisos: `ls -la uploads/`
✓ Verifica límites PHP en `php.ini`:
  - `upload_max_filesize = 100M`
  - `post_max_size = 100M`

### Error 500

✓ Revisa logs de Apache: `/var/log/apache2/error.log`
✓ Verifica que mod_rewrite esté habilitado: `a2enmod rewrite`

## Siguientes Pasos

- Lee [README.md](README.md) para documentación completa
- Revisa [FEATURES.md](FEATURES.md) para ver todas las características
- Consulta [SECURITY.md](SECURITY.md) para seguridad
- Lee [INSTALL.md](INSTALL.md) para instalación avanzada

## Soporte

¿Problemas? Abre un issue en GitHub:
https://github.com/matatunos/Mimir/issues

## Demo Rápida

### Compartir un archivo por tiempo (3 días)

1. Sube un archivo
2. Click en "Share"
3. Selecciona "Time-based"
4. Ingresa "3" días
5. Click "Create Share Link"
6. Copia y comparte el enlace

### Compartir un archivo por descargas (5 descargas)

1. Sube un archivo
2. Click en "Share"
3. Selecciona "Download-based"
4. Ingresa "5" descargas
5. Click "Create Share Link"
6. Copia y comparte el enlace

¡Disfruta usando Mimir! 🎉
