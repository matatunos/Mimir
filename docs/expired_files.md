# Manejo de Archivos Expirados

Este documento describe los cambios y pasos para la funcionalidad de "archivos expirados" implementada en la aplicación.

Resumen
- Objetivo: marcar archivos antiguos como "expirados" (ocultos para usuarios), permitir a administradores restaurarlos, marcarlos como "nunca expirar" o eliminarlos.
- No se borran automáticamente los ficheros salvo que el administrador lo haga explícitamente.

Archivos añadidos/actualizados
- `tools/expire_files.php` — script CLI que marca archivos como expirados según la configuración `file_expire_days`.
- `database/migrations/20251210_add_file_expiration_fields.sql` — migración que añade columnas `is_expired`, `expired_at`, `never_expire` y los índices correspondientes en la tabla `files`.
- `classes/File.php` — `getByUser()` ahora tiene parámetro `includeExpired=false` y por defecto oculta archivos expirados.
- `public/admin/expired_files.php` — página administrativa para listar archivos expirados y ejecutar acciones en lote (restaurar, marcar nunca expirar, eliminar).
-- `includes/layout.php` — enlace en la barra lateral para administradores hacia "Archivos Expirados".

Rutas y permisos
- Página admin: `/admin/expired_files.php` (requiere permisos de administrador).
- Script CLI: `/opt/Mimir/tools/expire_files.php` — ejecutar como el usuario que ejecuta PHP (p.ej. `www-data`).

Configuración relevante
- `file_expire_days` — número de días tras los cuales un archivo se considera candidato para expirar. Valor por defecto usado en la herramienta: `180` (configurable).
- `items_per_page` — usado por la paginación en la vista admin.

Instalación de migraciones (recomendado)
1. Hacer copia de seguridad de la base de datos.
2. Ejecutar las migraciones SQL en el servidor de BD (puede hacerse de forma idempotente, ver `scripts/migrate_security_events.sh` como referencia):

```sql
-- ejemplo
mysql -u root -p mydb < database/migrations/20251210_add_file_expiration_fields.sql
```

3. Verificar que las columnas `is_expired`, `expired_at`, `never_expire` existen en `files`.

Ejecución manual del expire script (prueba)
Para probar manualmente el marcado de archivos expirados:

```bash
# Ejecutar como el usuario web (ejemplo: www-data)
sudo -u www-data /usr/bin/php /opt/Mimir/tools/expire_files.php

# O ejecutarlo directamente y ver salida
php /opt/Mimir/tools/expire_files.php
```

Logs de prueba
- Recomendado redirigir la salida a un log específico para revisiones:

```bash
sudo mkdir -p /var/log/mimir
sudo touch /var/log/mimir/expire_files.log
sudo chown www-data:www-data /var/log/mimir/expire_files.log
sudo -u www-data /usr/bin/php /opt/Mimir/tools/expire_files.php >> /var/log/mimir/expire_files.log 2>&1
```

Instalación recomendada de cron (diaria, 03:00)
- Línea de ejemplo para `/etc/cron.d/mimir-expire`:

```
0 3 * * * www-data /usr/bin/php /opt/Mimir/tools/expire_files.php >> /var/log/mimir/expire_files.log 2>&1
```

Para instalar:
```bash
sudo mkdir -p /var/log/mimir
sudo touch /var/log/mimir/expire_files.log
sudo chown www-data:www-data /var/log/mimir/expire_files.log
sudo tee /etc/cron.d/mimir-expire > /dev/null <<'CRON'
0 3 * * * www-data /usr/bin/php /opt/Mimir/tools/expire_files.php >> /var/log/mimir/expire_files.log 2>&1
CRON
sudo chmod 644 /etc/cron.d/mimir-expire
```

Notas operativas y seguridad
- El script y la página asumen que la migración a la base de datos que añade `is_expired` ya fue aplicada.
- El archivo admin exige autenticación y rol de administrador.
- Asegúrate de que los ficheros en `public/admin` son legibles por el servidor web y no son modificables por usuarios no confiables.

Rollback
- Para revertir, restaurar la copia de seguridad de la BD y eliminar los cambios en el código (o restaurar desde tu VCS).

Soporte y pruebas
- Después de instalar las migraciones y cron, ejecutar el script manualmente y revisar `/var/log/mimir/expire_files.log`.
- Probar desde la UI admin la restauración y el marcado "Nunca expirar".

Contacto
- Si quieres que instale el cron en `/etc/cron.d` lo puedo hacer desde aquí (requiere permisos `sudo`).
