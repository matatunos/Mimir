Descripción de Configuración

Dónde están las descripciones:

- En el código: `public/admin/config.php` contiene el arreglo `$descs` que actúa como "fuente de verdad" durante el desarrollo y para generar defaults.
- En la base de datos: la tabla `config` almacena la columna `description` que la UI lee en tiempo de ejecución.

Por qué la duplicación:

Esto permite que instalaciones nuevas obtengan descripciones sin ejecutar SQL manualmente, y que administradores editen textos en producción sin desplegar código. Es intencional, pero requiere sincronización cuando se actualizan textos.

Cómo sincronizar (recomendado):

1. Mantener las descripciones en `public/admin/config.php` como fuente de verdad.
2. Ejecutar el script de sincronización durante despliegues para persistirlas en la BD:
   - Generar el SQL: `php scripts/generate_config_descs_sql.php` (escribe `tmp/update_config_desc.sql`).
   - Aplicar el SQL: `php scripts/apply_config_descs.php` o `mysql < tmp/update_config_desc.sql`.
3. Alternativamente, las migraciones pueden incluir sentencias `UPDATE` idempotentes en `database/schema.sql` (ya hay un bloque añadido para este propósito).

Buenas prácticas:

- Edita la descripción en `public/admin/config.php` y añade una migración o ejecuta el script de sincronización en el pipeline de CI/CD.
- Evita editar únicamente la BD si quieres que los cambios persistan en el control de versiones; sincroniza el cambio hacia el repositorio.
- Si prefieres un único origen de verdad, podemos consolidar: 1) mover todo a `database/schema.sql` y eliminar el bloque `$descs`, o 2) dejar solo el código y ejecutar scripts en deploys. Dime si quieres que normalice esto.
