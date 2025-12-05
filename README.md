# Mimir - Personal Cloud Storage

Sistema de almacenamiento personal en la nube con gestión de usuarios, compartición de archivos y auditoría completa.

## Características

### Multi-usuario con Roles
- **Usuario Simple**: Puede subir, bajar, borrar y compartir archivos
- **Administrador**: Control completo del sistema, configuración, gestión de usuarios y auditoría

### Compartición de Archivos Públicos
- **Compartir por tiempo**: Enlaces con expiración (máximo 30 días configurable)
- **Compartir por descargas**: Enlaces con límite de descargas
- El usuario decide el tipo de enlace público sin configuraciones complejas

### Gestión de Archivos
- Soporte para carpetas jerárquicas
- Límites de vida útil de archivos (configurable por administrador)
- Cuotas de almacenamiento por usuario
- Múltiples tipos de archivos soportados

### Panel de Administración
- Configuración completa del sistema
- Gestión de usuarios y cuotas
- Auditoría completa de todas las acciones
- Estadísticas de uso del sistema

### Notificaciones y Auditoría
- Notificaciones por correo electrónico (configurable)
- Registro completo de auditoría de todas las acciones
- Seguimiento de descargas y accesos

## Requisitos del Sistema

- PHP 7.4 o superior
- MySQL 5.7 o MariaDB 10.2 o superior
- Servidor web Apache con mod_rewrite
- Extensiones PHP requeridas:
  - PDO
  - pdo_mysql
  - fileinfo
  - mbstring

## Instalación

### Inicio Rápido para Desarrollo

Si quieres probar Mimir rápidamente sin configurar Apache:

```bash
git clone https://github.com/matatunos/Mimir.git
cd Mimir
cp config/config.example.php config/config.php
# Edita config.php con tus credenciales de base de datos
php setup.php
php -S localhost:8000 -t public/
# Accede a http://localhost:8000
```

📖 Ver [DEVELOPMENT.md](DEVELOPMENT.md) para más opciones (XAMPP, Docker, hosting online)

### Instalación Completa

### 1. Clonar el Repositorio

```bash
git clone https://github.com/matatunos/Mimir.git
cd Mimir
```

### 2. Configurar Base de Datos

Editar `config/config.php` y configurar las credenciales de la base de datos:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mimir');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_contraseña');
```

### 3. Ejecutar Script de Instalación

```bash
php setup.php
```

Este script creará la base de datos, tablas necesarias y un usuario administrador por defecto.

### 4. Configurar Servidor Web

#### Apache

El proyecto incluye un archivo `.htaccess`. Asegúrate de que:
- `mod_rewrite` esté habilitado
- `AllowOverride All` esté configurado para el directorio

Ejemplo de configuración de Virtual Host:

```apache
<VirtualHost *:80>
    ServerName mimir.local
    DocumentRoot /ruta/a/Mimir
    
    <Directory /ruta/a/Mimir>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/mimir-error.log
    CustomLog ${APACHE_LOG_DIR}/mimir-access.log combined
</VirtualHost>
```

### 5. Configurar Permisos

```bash
chmod -R 755 /ruta/a/Mimir
chmod -R 775 uploads logs
chown -R www-data:www-data uploads logs
```

### 6. Acceder a la Aplicación

Accede a tu instalación en el navegador:
```
http://tu-servidor/
```

Credenciales por defecto:
- **Usuario**: admin
- **Contraseña**: admin123

**⚠️ IMPORTANTE**: Cambia la contraseña del administrador inmediatamente después del primer acceso.

## Configuración

### Configuración del Sistema

Los administradores pueden configurar el sistema desde el panel de administración:

- Tamaño máximo de archivo
- Cuota de almacenamiento por defecto
- Máximo de días para enlaces temporales
- Tiempo de vida de archivos
- Configuración SMTP para notificaciones
- Permitir/denegar registro de usuarios

### Configuración SMTP

Para habilitar notificaciones por correo, configura SMTP en el panel de administración:

1. Ir a Admin → Settings
2. Configurar:
   - `smtp_host`: Servidor SMTP
   - `smtp_port`: Puerto (587 para TLS, 465 para SSL)
   - `smtp_username`: Usuario SMTP
   - `smtp_password`: Contraseña SMTP
   - `smtp_from_email`: Email remitente
   - `enable_email_notifications`: true

### Tarea Cron para Limpieza

Para limpiar automáticamente archivos y enlaces expirados, configura un cron job:

```bash
0 * * * * php /ruta/a/Mimir/cron/cleanup.php
```

Este cron se ejecutará cada hora y limpiará:
- Archivos con vida útil expirada
- Enlaces de compartición expirados por tiempo
- Enlaces de compartición que alcanzaron el límite de descargas

## Uso

### Usuarios Regulares

1. **Subir Archivos**
   - Click en "Upload File"
   - Seleccionar archivo
   - El archivo se guardará en la carpeta actual

2. **Crear Carpetas**
   - Click en "New Folder"
   - Ingresar nombre de carpeta

3. **Compartir Archivos**
   - Click en "Share" junto al archivo
   - Elegir tipo de compartición:
     - **Por tiempo**: Especificar días (máx. 30)
     - **Por descargas**: Especificar número máximo de descargas
   - Copiar y compartir el enlace generado

4. **Gestionar Comparticiones**
   - Ir a "Shares"
   - Ver todas las comparticiones activas
   - Desactivar o eliminar comparticiones

### Administradores

1. **Panel de Administración**
   - Acceder a "Admin" en el menú
   - Dashboard con estadísticas del sistema

2. **Gestión de Usuarios**
   - Ver todos los usuarios
   - Modificar roles (usuario/admin)
   - Ajustar cuotas de almacenamiento
   - Activar/desactivar usuarios

3. **Configuración del Sistema**
   - Ajustar límites y parámetros
   - Configurar notificaciones
   - Personalizar nombre del sitio

4. **Auditoría**
   - Ver registro completo de acciones
   - Filtrar por usuario, acción, fecha
   - Incluye IP y user agent

## Seguridad

- Contraseñas hasheadas con `password_hash()` de PHP
- Protección contra SQL injection mediante PDO prepared statements
- Validación de tipos de archivo
- Límites de tamaño de archivo
- Cuotas de almacenamiento por usuario
- Auditoría completa de acciones
- Archivos almacenados fuera del directorio web público
- Headers de seguridad HTTP

## Estructura del Proyecto

```
Mimir/
├── config/
│   └── config.php          # Configuración principal
├── cron/
│   └── cleanup.php         # Script de limpieza
├── database/
│   └── schema.sql          # Esquema de base de datos
├── includes/
│   ├── init.php            # Inicialización
│   ├── Database.php        # Conexión a base de datos
│   ├── Auth.php            # Autenticación
│   ├── AuditLog.php        # Registro de auditoría
│   ├── FileManager.php     # Gestión de archivos
│   ├── FolderManager.php   # Gestión de carpetas
│   ├── ShareManager.php    # Gestión de comparticiones
│   ├── SystemConfig.php    # Configuración del sistema
│   └── Notification.php    # Notificaciones
├── logs/                   # Logs del sistema
├── public/
│   ├── css/
│   │   └── style.css       # Estilos
│   ├── js/
│   │   └── script.js       # JavaScript
│   ├── index.php           # Página principal
│   ├── login.php           # Login/Registro
│   ├── dashboard.php       # Dashboard de usuario
│   ├── share modal in `dashboard.php`  # Crear/gestionar comparticiones (reemplaza `share_file.php`/`shares.php`)
│   ├── share.php           # Acceso público a compartición
│   ├── admin.php           # Panel de administración
│   └── ...
├── uploads/
│   ├── files/              # Archivos subidos
│   └── temp/               # Archivos temporales
├── .htaccess              # Configuración Apache
├── .gitignore             # Git ignore
├── setup.php              # Script de instalación
└── README.md              # Este archivo
```

## Tecnologías Utilizadas

- **PHP**: Backend
- **MySQL/MariaDB**: Base de datos
- **HTML/CSS/JavaScript**: Frontend
- **PDO**: Acceso a base de datos
- **Apache**: Servidor web

## Troubleshooting

### Error de Conexión a Base de Datos

Verifica que:
- MySQL/MariaDB esté en ejecución
- Las credenciales en `config/config.php` sean correctas
- El usuario de base de datos tenga permisos suficientes

### Archivos No Se Suben

Verifica que:
- Los directorios `uploads/files` y `uploads/temp` existan
- Los permisos sean correctos (775)
- El tamaño del archivo no exceda el límite configurado
- La configuración PHP permita el tamaño (`upload_max_filesize`, `post_max_size`)

### Enlaces de Compartición No Funcionan

Verifica que:
- `mod_rewrite` esté habilitado en Apache
- El archivo `.htaccess` tenga efecto
- La URL base esté correctamente configurada en `config/config.php`

## Licencia

Este proyecto está licenciado bajo la Licencia MIT. Ver archivo LICENSE para más detalles.

## Soporte

Para reportar problemas o solicitar funcionalidades, crear un issue en GitHub:
https://github.com/matatunos/Mimir/issues

## Créditos

Desarrollado como sistema de almacenamiento personal en la nube con características empresariales.
