# Guía de Desarrollo Local - Mimir

Esta guía te ayudará a probar Mimir rápidamente sin necesidad de configurar Apache.

## Opción 1: Servidor de Desarrollo PHP (Recomendado para Pruebas)

La forma más rápida de probar Mimir sin configurar Apache:

### Requisitos
- PHP 7.4+ con extensiones: pdo_mysql, fileinfo, mbstring
- MySQL/MariaDB

### Pasos

1. **Clonar y configurar**:
```bash
git clone https://github.com/matatunos/Mimir.git
cd Mimir
cp config/config.example.php config/config.php
```

2. **Editar `config/config.php`**:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mimir');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_password');
define('BASE_URL', 'http://localhost:8000');  // Puerto del servidor PHP
```

3. **Crear base de datos**:
```bash
mysql -u root -p
```
```sql
CREATE DATABASE mimir CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mimir_user'@'localhost' IDENTIFIED BY 'password';
GRANT ALL PRIVILEGES ON mimir.* TO 'mimir_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

4. **Instalar esquema**:
```bash
php setup.php
```

5. **Iniciar servidor de desarrollo**:
```bash
php -S localhost:8000 -t public/
```

6. **Acceder a la aplicación**:
   - Abrir navegador en: `http://localhost:8000`
   - Usuario: `admin`
   - Contraseña: `admin123`

### Notas Importantes

⚠️ **El servidor de desarrollo PHP NO debe usarse en producción**. Es solo para desarrollo y pruebas locales.

**Limitaciones**:
- No procesa archivos `.htaccess` (algunas URLs pueden no funcionar correctamente)
- Rendimiento limitado
- Sin soporte para configuraciones avanzadas
- Gestión básica de archivos estáticos

**Ventajas**:
- ✅ Instalación instantánea (sin Apache)
- ✅ Ideal para desarrollo y pruebas
- ✅ Fácil de iniciar y detener

## Opción 2: Apache (Recomendado para Producción)

Para un entorno más completo y similar a producción, consulta [INSTALL.md](INSTALL.md) para configurar Apache con mod_rewrite.

### Servidor Local con XAMPP/WAMP/MAMP

Si prefieres una solución todo-en-uno:

#### XAMPP (Windows/Linux/Mac)
1. Descargar de: https://www.apachefriends.org/
2. Instalar XAMPP
3. Copiar Mimir a `C:\xampp\htdocs\mimir` (Windows) o `/opt/lampp/htdocs/mimir` (Linux)
4. Configurar `BASE_URL` a `http://localhost/mimir`
5. Acceder a phpMyAdmin para crear la base de datos
6. Ejecutar `php setup.php`
7. Acceder a `http://localhost/mimir`

#### WAMP (Windows)
1. Descargar de: https://www.wampserver.com/
2. Instalar WAMP
3. Copiar Mimir a `C:\wamp64\www\mimir`
4. Seguir los mismos pasos que XAMPP

#### MAMP (Mac)
1. Descargar de: https://www.mamp.info/
2. Instalar MAMP
3. Copiar Mimir a `/Applications/MAMP/htdocs/mimir`
4. Seguir los mismos pasos que XAMPP

## Opción 3: Docker (Avanzado)

Si estás familiarizado con Docker, puedes crear un contenedor:

```dockerfile
# Dockerfile (crear en el directorio raíz)
FROM php:7.4-apache

# Instalar extensiones
RUN docker-php-ext-install pdo pdo_mysql

# Habilitar mod_rewrite
RUN a2enmod rewrite

# Copiar código
COPY . /var/www/html/

# Permisos
RUN chown -R www-data:www-data /var/www/html/uploads /var/www/html/logs
RUN chmod -R 775 /var/www/html/uploads /var/www/html/logs

EXPOSE 80
```

```yaml
# docker-compose.yml
version: '3'
services:
  web:
    build: .
    ports:
      - "8080:80"
    volumes:
      - ./uploads:/var/www/html/uploads
      - ./logs:/var/www/html/logs
    depends_on:
      - db
    environment:
      DB_HOST: db
      DB_NAME: mimir
      DB_USER: mimir_user
      DB_PASS: mimir_password

  db:
    image: mysql:5.7
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: mimir
      MYSQL_USER: mimir_user
      MYSQL_PASSWORD: mimir_password
    volumes:
      - mysql_data:/var/lib/mysql

volumes:
  mysql_data:
```

Luego ejecutar:
```bash
docker-compose up -d
docker exec -it <container_name> php setup.php
```

## Resolución de Problemas

### Error de conexión a base de datos
- Verifica que MySQL esté ejecutándose
- Verifica credenciales en `config/config.php`
- Prueba conexión: `mysql -u mimir_user -p mimir`

### Archivos no se suben
- Verifica permisos: `chmod -R 775 uploads logs`
- Verifica límites PHP en `php.ini`:
  ```ini
  upload_max_filesize = 100M
  post_max_size = 100M
  ```

### Error 404 en todas las páginas (servidor PHP)
- El servidor de desarrollo PHP no soporta `.htaccess`
- Algunas rutas pueden requerir acceso directo: `http://localhost:8000/login.php`

### Puerto ocupado
Si el puerto 8000 está en uso:
```bash
php -S localhost:8080 -t public/  # Usar otro puerto
```

## Recomendaciones

**Para desarrollo y pruebas locales**: Usa el servidor PHP (`php -S`)

**Para demo o presentación**: Usa XAMPP/WAMP/MAMP

**Para producción**: Usa Apache/Nginx con configuración apropiada (ver [INSTALL.md](INSTALL.md))

## Demo Online

Si necesitas mostrar Mimir sin instalarlo localmente, considera:

1. **Servicios de hosting gratuito con PHP/MySQL**:
   - InfinityFree: https://infinityfree.net/
   - 000webhost: https://www.000webhost.com/
   - Freehostia: https://www.freehostia.com/

2. **Plataformas PaaS** (Platform as a Service):
   - Heroku (con ClearDB MySQL addon)
   - Railway.app
   - Render.com

3. **VPS gratuito/trial**:
   - Oracle Cloud Free Tier
   - Google Cloud Free Tier
   - AWS Free Tier

Cada una de estas opciones te permitiría tener una URL pública para mostrar el sistema sin necesidad de servidor local.

## Soporte

Para más información:
- [README.md](README.md) - Información general
- [QUICKSTART.md](QUICKSTART.md) - Inicio rápido con Apache
- [INSTALL.md](INSTALL.md) - Instalación detallada

¿Problemas? Abre un issue en GitHub: https://github.com/matatunos/Mimir/issues
