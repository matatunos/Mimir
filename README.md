# Mimir - Sistema de Gestión de Archivos

Sistema completo de gestión y compartición de archivos con características empresariales, autenticación 2FA, análisis forense y administración avanzada.

## 🚀 Características Principales

### 📁 Gestión de Archivos
- **Subida de archivos** con límites configurables por usuario
- **Compartición de archivos** con enlaces públicos/privados
- **Control de acceso** granular por archivo
- **Gestión de cuotas** de almacenamiento por usuario
- **Búsqueda y filtrado** avanzado de archivos
- **Previsualización** de archivos multimedia

### 🔐 Seguridad y Autenticación
- **Autenticación 2FA** con soporte para:
  - TOTP (Google Authenticator, Authy, etc.)
  - Duo Security
- **Autenticación LDAP** integrada
- **Gestión de sesiones** seguras con tokens CSRF
- **Control de dispositivos** confiables
- **Registro de intentos** de acceso fallidos

### 📊 Dashboard y Estadísticas
- **Gráficas interactivas** con Chart.js:
  - Subidas diarias de archivos (últimos 30 días)
  - Análisis semanal (52 semanas)
  - Actividad por día de la semana
  - Comparativa fin de semana vs. días laborables
- **Selección de período** (30 días, 3 meses, 1-3 años)
- **Estadísticas en tiempo real** de uso y actividad
- **Métricas de 2FA** (usuarios activos, métodos utilizados)

### 🔍 Análisis Forense
Sistema completo de logging para análisis de seguridad y auditoría:

- **Registro de descargas** con más de 30 campos:
  - IP de origen (con soporte para proxies/Cloudflare)
  - User Agent completo
  - Navegador y versión
  - Sistema operativo
  - Tipo de dispositivo (móvil, tablet, desktop)
  - Marca y modelo del dispositivo
  - Detección de bots (Googlebot, Bingbot, cURL, Wget, etc.)
  - Referrer y Accept-Language
  - Duración de descarga
  - Códigos de estado HTTP
  - Checksums MD5/SHA256
  
- **Panel de análisis forense** con:
  - Estadísticas globales (descargas, IPs únicas, bots, dispositivos)
  - Top 10 IPs más activas
  - Distribución de navegadores
  - Desglose por tipo de dispositivo
  - Log completo de descargas con filtros
  - Eventos de seguridad

### 👥 Gestión de Usuarios (Avanzada)
- **Filtros múltiples**:
  - Búsqueda por nombre/email/usuario
  - Rol (admin/usuario)
  - Estado activo/inactivo
  - Estado 2FA (con/sin/obligatorio)
  - **Inactividad** (10/30/90/180/365 días)
  
- **Ordenación** por cualquier columna:
  - Username, nombre completo, email
  - Rol, fecha de registro
  - Cuota de almacenamiento
  - **Última actividad**
  
- **Acciones en bloque**:
  - Activar/desactivar usuarios
  - Requerir/quitar 2FA obligatorio
  - Eliminar múltiples usuarios
  - Selección individual o masiva
  
- **Visualización mejorada**:
  - Uso de almacenamiento con barras de progreso
  - Indicadores de última actividad
  - Avisos de inactividad prolongada
  - Contador de archivos por usuario

### 📂 Gestión de Archivos del Sistema (Avanzada)
- **Filtros avanzados**:
  - Búsqueda por nombre/descripción
  - Filtro por propietario
  - Estado de compartición
  - Tipo de archivo
  
- **Ordenación** por:
  - Nombre, propietario, tipo
  - Tamaño, fecha de creación
  - Número de comparticiones
  
- **Acciones en bloque**:
  - Eliminar archivos seleccionados
  - Dejar de compartir en bloque
  - Compartir múltiples archivos
  
- **Paginación configurable** (10/25/50/100 elementos por página)

## 📋 Requisitos del Sistema

- **PHP** 8.0 o superior
- **Apache** 2.4+ con mod_rewrite
- **MySQL/MariaDB** 5.7+
- **Composer** para dependencias PHP
- **Extensiones PHP**:
  - pdo_mysql
  - mbstring
  - openssl
  - curl
  - gd o imagick (para previsualización de imágenes)

## 🛠️ Instalación

### Instalación Automática

```bash
# Clonar el repositorio
git clone https://github.com/matatunos/Mimir.git
cd Mimir

# Ejecutar script de instalación
sudo chmod +x install.sh
sudo ./install.sh
```

El script de instalación:
1. Verifica dependencias del sistema
2. Instala paquetes necesarios de PHP y Apache
3. Configura Composer y dependencias
4. Crea la base de datos y ejecuta migraciones
5. Configura permisos de archivos
6. Crea el archivo de configuración
7. Configura Apache (VirtualHost)

### Instalación Manual

1. **Clonar el repositorio**
```bash
git clone https://github.com/matatunos/Mimir.git
cd Mimir
```

2. **Instalar dependencias**
```bash
composer install
```

3. **Configurar base de datos**
```bash
mysql -u root -p
CREATE DATABASE mimir;
CREATE USER 'mimir_user'@'localhost' IDENTIFIED BY 'tu_password_seguro';
GRANT ALL PRIVILEGES ON mimir.* TO 'mimir_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

4. **Importar esquema**
```bash
mysql -u root -p mimir < database/schema.sql
mysql -u root -p mimir < database/migrations/add_forensic_fields.sql
```

5. **Configurar aplicación**
```bash
cp config.example.php config.php
# Editar config.php con tus credenciales
```

6. **Configurar permisos**
```bash
sudo chown -R www-data:www-data /opt/Mimir/storage
sudo chmod -R 755 /opt/Mimir/storage
```

7. **Configurar Apache**
```bash
sudo cp /opt/Mimir/apache-config-example.conf /etc/apache2/sites-available/mimir.conf
sudo a2ensite mimir.conf
sudo a2enmod rewrite
sudo systemctl reload apache2
```

## 🔧 Configuración

### Archivo de Configuración Principal

Editar `config.php`:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mimir');
define('DB_USER', 'mimir_user');
define('DB_PASS', 'tu_password');

define('BASE_URL', 'https://files.favala.es');
define('SITE_NAME', 'Mimir Files');

// LDAP Configuration (opcional)
define('LDAP_ENABLED', false);
define('LDAP_HOST', 'ldap://ldap.example.com');
define('LDAP_PORT', 389);
define('LDAP_BASE_DN', 'dc=example,dc=com');

// 2FA Configuration
define('TOTP_ISSUER', 'Mimir Files');
define('DUO_ENABLED', false);
```

### Configuración de Apache detrás de Nginx

Si usas Nginx como proxy inverso con SSL:

```apache
<VirtualHost *:80>
    ServerName files.favala.es
    DocumentRoot /opt/Mimir/public

    <Directory /opt/Mimir/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Trust X-Forwarded headers from nginx
    SetEnvIf X-Forwarded-Proto https HTTPS=on
    SetEnvIf X-Forwarded-For ^.+ REMOTE_ADDR=%{X-Forwarded-For}e

    ErrorLog ${APACHE_LOG_DIR}/files.favala.es_error.log
    CustomLog ${APACHE_LOG_DIR}/files.favala.es_access.log combined
</VirtualHost>
```

## 📦 Actualización

```bash
cd /opt/Mimir

# Hacer backup de la base de datos
mysqldump -u root -p mimir > backup_$(date +%Y%m%d).sql

# Actualizar código
git pull origin main

# Actualizar dependencias
composer install --no-dev

# Ejecutar migraciones pendientes
mysql -u root -p mimir < database/migrations/*.sql

# Limpiar caché si es necesario
rm -rf storage/cache/*

# Reiniciar Apache
sudo systemctl restart apache2
```

## 🗄️ Estructura de la Base de Datos

### Tablas Principales
- `users` - Usuarios del sistema
- `user_2fa` - Configuración 2FA por usuario
- `files` - Archivos subidos
- `shares` - Enlaces de compartición
- `activity_log` - Registro de actividad general
- `download_log` - Registro forense de descargas
- `security_events` - Eventos de seguridad
- `share_access_log` - Accesos a archivos compartidos
- `2fa_attempts` - Intentos de autenticación 2FA
- `config` - Configuración del sistema

## 🔒 Seguridad

- Todas las contraseñas se hashean con `password_hash()` (bcrypt)
- Tokens CSRF en todos los formularios
- Validación de tipos de archivo
- Límites de tamaño de subida configurables
- Protección contra fuerza bruta en 2FA
- Headers de seguridad HTTP
- Sanitización de inputs
- Prepared statements para prevenir SQL injection

## 📱 Uso

### Para Usuarios

1. **Login**: Accede con tu usuario/contraseña
2. **2FA**: Si está habilitado, introduce el código TOTP o Duo
3. **Subir archivos**: Arrastra archivos o usa el botón de subida
4. **Compartir**: Haz clic en el icono de compartir para generar enlaces
5. **Gestionar**: Edita, descarga o elimina tus archivos

### Para Administradores

1. **Dashboard**: Visualiza estadísticas y gráficas del sistema
2. **Usuarios**: Gestiona usuarios, roles, cuotas y 2FA
3. **Archivos**: Supervisa todos los archivos del sistema
4. **Análisis Forense**: Revisa logs de descargas y eventos de seguridad
5. **Configuración**: Ajusta parámetros del sistema

## 🧪 Datos de Prueba

Para generar datos de prueba:

```bash
# Usuarios y archivos de ejemplo
php seed_database.php

# Actividad histórica (últimos 365 días)
php seed_historical_activity.php

# Descargas forenses (últimos 90 días)
php simulate_forensic_downloads.php
```

## 📈 Características Técnicas

- **Arquitectura MVC** organizada
- **OOP** con clases reutilizables
- **PDO** con prepared statements
- **Chart.js 4.4.0** para visualizaciones
- **FontAwesome 6** para iconos
- **Responsive design** adaptable a móviles
- **Dark/Light mode** support
- **API REST** preparada para expansión

## 🤝 Contribuir

Las contribuciones son bienvenidas. Por favor:

1. Fork del proyecto
2. Crea una rama para tu feature (`git checkout -b feature/AmazingFeature`)
3. Commit de tus cambios (`git commit -m 'Add some AmazingFeature'`)
4. Push a la rama (`git push origin feature/AmazingFeature`)
5. Abre un Pull Request

## 📄 Licencia

Este proyecto está bajo la Licencia MIT. Ver archivo `LICENSE` para más detalles.

## 👨‍💻 Autor

**matatunos**
- GitHub: [@matatunos](https://github.com/matatunos)

## 🙏 Agradecimientos

- Chart.js por las excelentes gráficas
- TOTP PHP Library
- FontAwesome por los iconos
- Comunidad de PHP por las mejores prácticas