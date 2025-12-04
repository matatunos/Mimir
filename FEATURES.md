# Características de Mimir - Sistema de Almacenamiento en la Nube

## Resumen

Mimir es un sistema completo de almacenamiento personal en la nube desarrollado en PHP puro, sin frameworks externos, con todas las características empresariales necesarias para gestionar archivos de manera segura.

## Características Principales

### 1. Sistema Multi-usuario con Roles

#### Usuario Simple
- **Subir archivos**: Carga archivos con validación de tipo y tamaño
- **Bajar archivos**: Descarga de archivos propios con seguimiento
- **Borrar archivos**: Eliminación segura de archivos con actualización de cuota
- **Compartir archivos**: Creación de enlaces públicos con dos modalidades
- **Gestión de carpetas**: Organización jerárquica de archivos

#### Usuario Administrador
- **Todo lo del usuario simple** más:
- **Gestión de usuarios**: Crear, modificar roles, activar/desactivar
- **Configuración del sistema**: Control total de parámetros
- **Auditoría completa**: Acceso a todos los logs del sistema
- **Estadísticas**: Dashboard con métricas del sistema
- **Gestión de cuotas**: Asignación personalizada de almacenamiento

### 2. Compartición de Archivos Públicos

#### Compartir por Tiempo
- Creación de enlaces con fecha de expiración
- Máximo configurable (por defecto 30 días)
- El usuario selecciona días de validez (1-30)
- Desactivación automática al expirar
- URL única y segura con token de 32 caracteres

#### Compartir por Descargas
- Creación de enlaces con límite de descargas
- El usuario especifica número máximo de descargas
- Contador automático de descargas
- Desactivación automática al alcanzar límite
- URL única y segura con token de 32 caracteres

#### Gestión de Comparticiones
- Vista de todas las comparticiones del usuario
- Información de estado (activo/inactivo)
- Contador de descargas realizadas
- Fecha de expiración visible
- Desactivación manual de enlaces
- Eliminación de comparticiones

### 3. Gestión de Archivos y Carpetas

#### Archivos
- Múltiples tipos de archivo soportados (documentos, imágenes, videos, archivos comprimidos)
- Almacenamiento con nombre único para evitar conflictos
- Hash SHA-256 para verificación de integridad
- Detección automática de tipo MIME
- Control de tamaño de archivo
- Límite de vida útil configurable
- Limpieza automática de archivos expirados

#### Carpetas
- Estructura jerárquica ilimitada
- Navegación intuitiva
- Eliminación en cascada (carpetas con contenido)
- Renombrado de carpetas
- Rutas completas almacenadas

#### Cuotas de Almacenamiento
- Cuota por usuario configurable
- Barra de progreso visual
- Actualización automática al subir/borrar
- Validación antes de subir archivos
- Configuración personalizada por administrador

### 4. Panel de Administración

#### Dashboard
- Total de usuarios registrados
- Total de archivos en el sistema
- Almacenamiento total utilizado
- Enlaces de compartición activos

#### Gestión de Usuarios
- Lista completa de usuarios
- Información de cuotas y uso
- Modificación de roles
- Activación/desactivación de cuentas
- Ajuste de cuotas individuales
- Fecha de registro y último acceso

#### Configuración del Sistema
- **Límites de archivo**: Tamaño máximo
- **Cuotas**: Cuota por defecto para nuevos usuarios
- **Comparticiones**: Máximo de días para enlaces temporales
- **Archivos**: Tiempo de vida por defecto
- **SMTP**: Configuración completa para emails
- **Notificaciones**: Activar/desactivar emails
- **Registro**: Permitir/denegar nuevos usuarios
- **Personalización**: Nombre del sitio

#### Auditoría Completa
- Registro de todas las acciones del sistema
- Información detallada por evento:
  - Usuario que realizó la acción
  - Tipo de acción (login, upload, download, delete, share, etc.)
  - Entidad afectada (archivo, carpeta, usuario, compartición)
  - Detalles adicionales
  - Dirección IP
  - User Agent del navegador
  - Fecha y hora exacta
- Filtrado y búsqueda
- Paginación para grandes volúmenes
- Acciones del sistema (limpiezas automáticas)

### 5. Notificaciones por Email

#### Sistema de Notificaciones
- Notificaciones configurables
- Soporte para SMTP estándar
- Plantillas HTML personalizables
- Eventos notificables:
  - Archivo subido
  - Archivo descargado
  - Archivo eliminado
  - Enlace de compartición creado
  - Enlace accedido
  - Advertencia de cuota
  - Cuota llena

#### Configuración SMTP
- Host y puerto configurables
- Autenticación de usuario
- Email y nombre del remitente
- Activación/desactivación global

### 6. Seguridad

#### Autenticación
- Hash de contraseñas con `password_hash()` (bcrypt)
- Sesiones seguras con nombre personalizado
- Tiempo de expiración de sesión configurable
- Logout completo con destrucción de sesión

#### Autorización
- Control de acceso basado en roles
- Validación de propiedad de archivos
- Protección de rutas administrativas
- Verificación de permisos en cada acción

#### Protección de Datos
- PDO con prepared statements (prevención de SQL injection)
- Validación de tipos de archivo
- Sanitización de nombres de archivo
- Archivos almacenados con nombres únicos
- Headers de seguridad HTTP
- Protección contra XSS
- Validación de tokens para comparticiones

#### Auditoría de Seguridad
- Registro de todos los accesos
- Captura de IP y User Agent
- Seguimiento de descargas públicas
- Detección de enlaces expirados

### 7. Limpieza Automática

#### Script Cron
- Limpieza de archivos expirados
- Desactivación de enlaces por tiempo
- Desactivación de enlaces por descargas
- Actualización de cuotas
- Registro de actividades de limpieza

#### Configuración
- Ejecución programada vía cron
- Sin intervención manual
- Logs de actividad

### 8. Interfaz de Usuario

#### Diseño
- Interfaz moderna y limpia
- Diseño responsive (móviles y tablets)
- Navegación intuitiva
- Retroalimentación visual

#### Componentes
- Dashboard con información clara
- Tablas de archivos ordenables
- Modales para acciones rápidas
- Formularios validados
- Alertas informativas
- Barras de progreso

#### Experiencia
- Carga de archivos drag-and-drop ready
- Confirmaciones antes de eliminaciones
- Copiado de enlaces con un click
- Estados visuales claros
- Mensajes de error descriptivos

### 9. Base de Datos

#### Esquema Completo
- Usuarios con roles y cuotas
- Archivos con metadatos
- Carpetas con jerarquía
- Comparticiones públicas
- Logs de auditoría
- Configuración del sistema

#### Características
- Índices optimizados
- Relaciones con integridad referencial
- Cascada de eliminación
- Campos de auditoría (timestamps)
- Soporte UTF-8 completo

### 10. Despliegue y Mantenimiento

#### Instalación
- Script de setup automatizado
- Configuración mediante archivo
- Creación automática de directorios
- Usuario administrador por defecto

#### Configuración
- Archivo de configuración centralizado
- Variables de entorno soportadas
- Configuración por interfaz web

#### Mantenimiento
- Script de limpieza automática
- Sistema de logs
- Actualización de configuración sin código
- Respaldo simple de base de datos

## Tecnología

### Backend
- PHP 7.4+
- PDO para base de datos
- Sesiones nativas de PHP
- Funciones de hash seguras

### Base de Datos
- MySQL 5.7+ / MariaDB 10.2+
- Charset UTF-8 (utf8mb4)
- InnoDB engine

### Frontend
- HTML5 semántico
- CSS3 moderno (Flexbox, Grid)
- JavaScript vanilla (sin frameworks)
- Diseño responsive

### Servidor
- Apache con mod_rewrite
- .htaccess para configuración
- Headers de seguridad

## Flujo de Trabajo del Usuario

### Usuario Regular
1. Registro o login
2. Ver dashboard con archivos y carpetas
3. Subir archivos a carpetas
4. Organizar en carpetas
5. Compartir archivos (tiempo o descargas)
6. Gestionar comparticiones activas
7. Descargar archivos propios
8. Eliminar archivos no necesarios

### Administrador
1. Login con cuenta admin
2. Ver estadísticas del sistema
3. Gestionar usuarios y cuotas
4. Configurar parámetros del sistema
5. Revisar auditoría de acciones
6. Configurar notificaciones
7. Ajustar límites y restricciones

### Usuario Público (Comparticiones)
1. Recibir enlace de compartición
2. Acceder a página pública
3. Ver información del archivo
4. Descargar archivo
5. Contador de descargas o tiempo restante visible

## Casos de Uso

### Empresarial
- Compartir documentos con clientes
- Control de acceso por tiempo
- Límite de descargas para seguridad
- Auditoría para cumplimiento
- Gestión centralizada de usuarios

### Personal
- Almacenar archivos personales
- Compartir fotos con familiares
- Backup de documentos
- Organización en carpetas
- Acceso desde cualquier lugar

### Educativo
- Compartir material con estudiantes
- Control de acceso temporal
- Límites de descarga para evaluaciones
- Organización por cursos (carpetas)
- Auditoría de accesos

## Ventajas Competitivas

1. **Todo en PHP**: Sin dependencias externas complejas
2. **Auto-contenido**: No requiere frameworks pesados
3. **Configurable**: Panel de administración completo
4. **Seguro**: Buenas prácticas de seguridad implementadas
5. **Auditable**: Registro completo de todas las acciones
6. **Escalable**: Diseño modular y extensible
7. **Documentado**: Documentación completa incluida
8. **Open Source**: Código fuente disponible y modificable

## Próximas Características Potenciales

- Previsualización de archivos (imágenes, PDFs)
- Búsqueda de archivos
- Etiquetas y metadatos personalizados
- Compartición entre usuarios registrados
- Permisos de carpeta
- API REST
- Cliente de escritorio
- Aplicación móvil
- Integración con almacenamiento en la nube (S3, etc.)
- Cifrado de archivos en reposo
- Autenticación de dos factores
- Temas personalizables
- Multi-idioma
