# Guía de Instalación - Mimir

## Instalación Rápida

### Paso 1: Requisitos Previos

Asegúrate de tener instalado:
- PHP 7.4 o superior
- MySQL 5.7 / MariaDB 10.2 o superior
- Apache con mod_rewrite habilitado

### Paso 2: Descargar el Código

```bash
git clone https://github.com/matatunos/Mimir.git
cd Mimir
```

### Paso 3: Configurar la Base de Datos

1. Crear una base de datos MySQL:

```sql
CREATE DATABASE mimir CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mimir_user'@'localhost' IDENTIFIED BY 'tu_contraseña_segura';
GRANT ALL PRIVILEGES ON mimir.* TO 'mimir_user'@'localhost';
FLUSH PRIVILEGES;
```

2. Copiar el archivo de configuración de ejemplo:

```bash
cp config/config.example.php config/config.php
```

3. Editar `config/config.php` con tus credenciales:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mimir');
define('DB_USER', 'mimir_user');
define('DB_PASS', 'tu_contraseña_segura');
define('BASE_URL', 'http://tu-dominio.com'); // o http://localhost
```

### Paso 4: Ejecutar el Script de Instalación

```bash
php setup.php
```

El script creará todas las tablas necesarias e insertará un usuario administrador por defecto.

### Paso 5: Configurar Permisos de Archivos

```bash
chmod -R 755 .
chmod -R 775 uploads logs
chown -R www-data:www-data uploads logs
```

Reemplaza `www-data` con el usuario del servidor web si es diferente.

### Paso 6: Configurar Apache

#### Opción A: Configurar Virtual Host (Recomendado)

Crear un archivo en `/etc/apache2/sites-available/mimir.conf`:

```apache
<VirtualHost *:80>
    ServerName mimir.local
    ServerAdmin admin@mimir.local
    DocumentRoot /var/www/html/Mimir
    
    <Directory /var/www/html/Mimir>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/mimir-error.log
    CustomLog ${APACHE_LOG_DIR}/mimir-access.log combined
</VirtualHost>
```

Habilitar el sitio:

```bash
sudo a2ensite mimir.conf
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### Opción B: Instalación en Subdirectorio

Si instalas en un subdirectorio, asegúrate de actualizar `BASE_URL` en `config/config.php`:

```php
define('BASE_URL', 'http://tu-dominio.com/mimir');
```

### Paso 7: Acceder a la Aplicación

1. Abre tu navegador y accede a la URL configurada
2. Inicia sesión con las credenciales por defecto:
   - **Usuario**: admin
   - **Contraseña**: admin123
3. **¡IMPORTANTE!** Cambia la contraseña del administrador inmediatamente

## Configuración Adicional

### Configurar Tareas Cron

Para limpieza automática de archivos y enlaces expirados:

```bash
crontab -e
```

Agregar la siguiente línea (ejecuta cada hora):

```
0 * * * * php /ruta/completa/a/Mimir/cron/cleanup.php
```

### Configurar Límites de PHP

Editar el archivo `php.ini` o crear un `.htaccess` en el directorio raíz:

```apache
php_value upload_max_filesize 100M
php_value post_max_size 100M
php_value max_execution_time 300
php_value max_input_time 300
php_value memory_limit 256M
```

### Configurar SMTP para Notificaciones

1. Acceder como administrador
2. Ir a Admin → Settings
3. Configurar los parámetros SMTP:
   - **smtp_host**: smtp.gmail.com (o tu servidor SMTP)
   - **smtp_port**: 587
   - **smtp_username**: tu@email.com
   - **smtp_password**: tu_contraseña
   - **smtp_from_email**: noreply@tu-dominio.com
   - **enable_email_notifications**: true

## Configuración de Producción

### Seguridad

1. **Cambiar credenciales por defecto**
2. **Deshabilitar errores de PHP**:
   ```php
   error_reporting(0);
   ini_set('display_errors', 0);
   ```
3. **Usar HTTPS**: Configurar certificado SSL
4. **Actualizar permisos**: Asegurar que solo el servidor web pueda escribir en `uploads` y `logs`
5. **Configurar firewall**: Permitir solo puertos 80/443

### Optimización

1. **Habilitar caché de PHP**: OPcache
2. **Configurar base de datos**: Optimizar configuración MySQL
3. **Usar CDN**: Para archivos estáticos (CSS, JS)

### Respaldo

1. **Base de datos**:
   ```bash
   mysqldump -u mimir_user -p mimir > backup_mimir.sql
   ```

2. **Archivos**:
   ```bash
   tar -czf mimir_files_backup.tar.gz uploads/
   ```

## Solución de Problemas

### No se puede conectar a la base de datos

- Verificar credenciales en `config/config.php`
- Verificar que MySQL esté en ejecución
- Verificar permisos del usuario de base de datos

### Archivos no se suben

- Verificar permisos de directorio `uploads/`
- Verificar límites de PHP (`upload_max_filesize`, `post_max_size`)
- Verificar espacio en disco

### Error 500

- Revisar logs de Apache: `/var/log/apache2/error.log`
- Revisar logs de PHP
- Verificar permisos de archivos

### Enlaces de compartición no funcionan

- Verificar que `mod_rewrite` esté habilitado
- Verificar que `.htaccess` tenga efecto
- Verificar `BASE_URL` en configuración

## Actualización

Para actualizar a una versión nueva:

1. Hacer respaldo de base de datos y archivos
2. Descargar nueva versión
3. Copiar archivos nuevos (excepto `config/config.php`)
4. Ejecutar migraciones de base de datos si las hay
5. Limpiar caché del navegador

## Soporte

Para problemas o preguntas:
- GitHub Issues: https://github.com/matatunos/Mimir/issues
- Documentación: Ver README.md
