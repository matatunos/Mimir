# Instrucciones para aplicar la migración en el servidor

## Ejecutar en el servidor de producción:

```bash
cd /opt/GestionSocios
git pull origin devel
mysql -u root -p gestion_socios < database/schema.sql
```

Nota: El schema.sql ahora incluye las migraciones necesarias con IF NOT EXISTS,
por lo que es seguro ejecutarlo sin riesgo de duplicar columnas o índices.

````