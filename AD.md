**Active Directory / LDAP — Configuración y mapeo de roles**

Este documento explica cómo funciona la integración con Active Directory (AD) / LDAP en la aplicación, qué opciones de configuración existen, cómo se aplican las restricciones por grupo y cómo se asignan/actualizan los roles locales en función de la pertenencia a grupos AD.

**Resumen**:
- **Propósito:** permitir autenticación contra AD/LDAP, opcionalmente restringir quién puede iniciar sesión según un grupo, y mapear miembros de un grupo AD a la cuenta local `admin`.
- **Archivos relacionados:** `includes/ldap.php`, `includes/auth.php`, `public/admin/test_ldap.php` (diagnóstico), `public/admin/ldap_diag.php` (UI diagnóstica añadida).

**Keys de configuración relevantes (tabla `config`)**
- `ad_host`: Host o IP del servidor AD (ej. `ad.example.com` o `192.168.1.254`).
- `ad_port`: Puerto LDAP (389) o LDAPS (636).
- `ad_use_ssl`: `1` para LDAPS (conexión TLS en el nivel de transporte). Si usas LDAPS, `ad_port` suele ser `636`.
- `ad_use_tls`: `1` para intentar STARTTLS (mejor si el servidor lo soporta y se desea en el puerto 389).
- `ad_bind_dn`: Cuenta de servicio para consultas (ej. `CN=mimirbind,OU=ServiceAccounts,DC=example,DC=com` o una UPN `mimirbind@example.com`).
- `ad_bind_password`: Contraseña del `ad_bind_dn`.
- `ad_base_dn`: Base DN para búsquedas (ej. `DC=favala,DC=es`). Esta base delimita dónde se realizan las búsquedas de usuario/grupo.
- `ad_search_filter`: Filtro para buscar usuarios (ej. `(&(objectClass=user)(sAMAccountName=%s))`). El `%s` se sustituye por el nombre de usuario provisto.
- `ad_username_attribute`: Atributo usado en filtros (`sAMAccountName` por defecto).
- `ad_required_group_dn` (o el key `ad_require_group` en algunas instalaciones): DN completo del grupo cuyos miembros están permitidos para iniciar sesión (ej. `CN=Users,DC=favala,DC=es`). Si está vacío, NO se aplica restricción de grupo.
- `ad_admin_group_dn`: DN del grupo AD cuyos miembros se mapearán a rol local `admin` cuando inician sesión (ej. `CN=Admins,DC=favala,DC=es`). Si está vacío, no hay mapeo automático a admin.

Nota: hay equivalentes `ldap_*` si usas un servidor LDAP diferente; la lógica es análoga.

**Cómo funciona el login con AD/LDAP**
1. El usuario envía credenciales (usuario + contraseña). Dependiendo de la configuración la app puede construir un DN (p.e. usando `user_dn` con `{username}`), o intentar usar un UPN (`username@domain`) — `includes/ldap.php::buildUserDn()` contiene la lógica de fallback.
2. Se intenta `ldap_bind` con el DN/UPN del usuario y la contraseña. Si el bind de usuario es exitoso, la autenticación es válida.
3. Para comprobaciones adicionales (requerir pertenencia a grupo o mapear roles):
   - La app usa `LdapAuth::isMemberOf($username, $groupDn)` para verificar pertenencia a un DN de grupo.
   - `isMemberOf()` intenta primero utilizar la regla de coincidencia en cadena de AD (matching rule in chain): OID `1.2.840.113556.1.4.1941`. Esto permite detectar membresía en grupos anidados en Active Directory.
   - Si la búsqueda con la regla en chain no es soportada o falla, hay una alternativa que lee el atributo `memberOf` del usuario y lo compara con el DN objetivo.
4. Si `ad_required_group_dn` está configurado y el usuario NO es miembro → se rechaza el login (la autenticación LDAP puede ser válida, pero la política de la app bloquea el acceso).
5. Si `ad_admin_group_dn` está configurado y el usuario resulta ser miembro → la app creará o actualizará la cuenta local con `role='admin'`. Si el usuario deja de pertenecer a ese grupo, la app puede revocar el rol `admin` en el siguiente login.

**Comportamiento de creación/actualización de usuarios**
- Si un usuario LDAP/AD no existe en la tabla `users`, la app creará una cuenta local en el primer login exitoso (con datos devueltos por `getUserInfo()` — nombre, email, etc.).
- Al crear, la app establece `role='admin'` si `ad_admin_group_dn` contiene al usuario; en caso contrario `role='user'`.
- Si el usuario ya existe, la app sincroniza el rol en cada login: si entra en el grupo admin se le otorga `admin` (y se registra un evento de log), y si sale del grupo admin se revoca (y también se registra).

**Endpoints y herramientas de diagnóstico**
- `public/admin/test_ldap.php?action=test&type=ad` — Comprueba la conexión, bind (si `bind_dn` existe), y devuelve JSON con `success` y campos `debug` en caso de error (STARTTLS / bind errors).
- `public/admin/test_ldap.php?action=member&type=ad&username=...&group_dn=...` — Comprueba si el usuario es miembro del `group_dn` (acepta GET o POST) y devuelve JSON con `is_member`.
- `public/admin/ldap_diag.php` — Página de interfaz admin para probar membership desde el navegador.

Ejemplo de uso en navegador (GET, con URL-encoding del DN):
```
https://files.favala.es/admin/test_ldap.php?action=member&type=ad&username=jdoe&group_dn=CN%3DUsers%2CDC%3Dfavala%2CDC%3Des
```

**Recomendaciones de despliegue y seguridad**
- Use LDAPS (puerto 636) o STARTTLS con `ad_use_tls=1` y compruebe certificados — no use LDAP sin cifrado en producción.
- Use una cuenta bind (`ad_bind_dn`) con permisos mínimos de lectura necesarios para buscar usuarios y ver `memberOf` / atributos de grupo.
- No guarde ni loguee contraseñas de usuarios: la app nunca debe persistir contraseñas LDAP.
- Configure `ad_base_dn` de forma que incluya los OUs donde viven usuarios y grupos necesarios; una base demasiado restrictiva puede impedir encontrar el usuario o grupo.

**Cómo escribir correctamente un DN (ejemplos)**
- Grupo en el dominio `favala.es`: `CN=Users,DC=favala,DC=es`
- Grupo admins: `CN=Admins,OU=Groups,DC=favala,DC=es`
- Cuenta bind (UPN): `mimirbind@favala.es` o DN completo `CN=mimirbind,OU=Service Accounts,DC=favala,DC=es`

**Causas comunes de problemas y pasos de solución**
- Resultado `is_member: false`:
  - El `group_dn` no está bajo `ad_base_dn` (aleja el ámbito de búsqueda). Asegúrate que la DN pertenece al mismo árbol configurado.
  - Usuario buscado con el atributo equivocado (prueba `sAMAccountName` y `UPN` — p.e. `jdoe` vs `jdoe@favala.es`).
  - La cuenta `ad_bind_dn` no puede ver atributos `memberOf` por permisos o políticas de visibilidad.
  - Membership es indirecta/nested y el servidor no soporta la regla `1.2.840.113556.1.4.1941` (en AD suele soportarse). La app intenta fallback leyendo `memberOf`.

**Pruebas recomendadas**
1. `action=test` para validar transporte/bind (STARTTLS/LDAPS).
2. `action=member` con `username` en forma sAMAccountName y UPN para confirmar detección.
3. Si falla, use `ldapsearch` (o herramientas AD) desde la misma red que la app para:
   - Obtener el `dn` del usuario: `(sAMAccountName=jdoe)` y revisar `memberOf`.
   - Obtener los `member` del grupo: búsqueda sobre `CN=MyGroup,DC=...` devolviendo `member`.

**Notas de implementación**
- La detección de grupos anidados utiliza el matching-rule OID `1.2.840.113556.1.4.1941` que es específico de Active Directory.
- Si `ad_required_group_dn` está vacío, la app NO restringe logins por grupo.
- Si `ad_admin_group_dn` está vacío, la app no mapeará miembros AD a `admin` automáticamente.

**Registro / auditoría**
- La app registra eventos de interés (p.e. `ldap_bind_failed`, `ldap_starttls_failed`, `role_granted_via_ad`, `role_revoked_via_ad`) en el sistema de logs para facilitar auditoría y resolución de problemas.

**Resumen rápido de acciones para habilitar control por grupo**
1. En Admin → Config (o en la tabla `config`) setear `ad_required_group_dn = 'CN=Users,DC=favala,DC=es'`.
2. Setear `ad_admin_group_dn = 'CN=Admins,DC=favala,DC=es'` si quieres mapear miembros a `admin`.
3. Probar con `test_ldap.php?action=test&type=ad` y `test_ldap.php?action=member...`.

Si necesitas que añada más ejemplos o copiar fragmentos de SQL/LDAP para tu AD particular, dímelo y lo incluyo.

Fin del documento.

**Cambios recientes en la aplicación (relacionados con seguridad y auditoría)**

- Se ha añadido la columna `username` en la tabla `security_events` para facilitar consultas exactas sobre intentos de login y auditoría de acciones por usuario.
- El sistema de rate-limiting por IP ahora es configurable via la tabla `config` con las claves:
   - `ip_rate_limit_threshold` (por defecto `5`) — intentos máximos por IP.
   - `ip_rate_limit_window_minutes` (por defecto `15`) — ventana en minutos.

Por qué: antes las consultas buscaban el nombre de usuario dentro de un campo `details` (texto JSON) usando LIKE, lo que podía producir colisiones entre usuarios con nombres parecidos (por ejemplo `nacho` y `nacho_ad`). Con la columna dedicada `username` las comprobaciones son exactas y más eficientes.

Cómo aplicar la migración en producción

1. Ejecutar la migración SQL que añade la columna (archivo incluido en el repo):

```sql
ALTER TABLE `security_events`
   ADD COLUMN `username` VARCHAR(100) NULL AFTER `event_type`;
```

2. Rellenar las filas históricas donde `details` contiene un JSON con `username`.
    - Si tu MySQL soporta `JSON_EXTRACT` y todos los valores `details` son JSON válidos, puedes intentar:

```sql
UPDATE security_events
SET username = JSON_UNQUOTE(JSON_EXTRACT(details, '$.username'))
WHERE username IS NULL AND details IS NOT NULL AND JSON_EXTRACT(details, '$.username') IS NOT NULL;
```

    - Si no todos los valores `details` son JSON válidos (caso común), usa el script PHP incluido en el repositorio (`/tmp/backfill_username.php` como ejemplo) que:
       - Selecciona filas con `details` conteniendo `"username"`.
       - Intenta `json_decode` y actualiza `username` cuando el JSON es válido.

3. (Opcional) Añadir un índice si esperas consultas frecuentes por `username`:

```sql
ALTER TABLE security_events ADD KEY idx_username (username);
```

Notas finales

- Hay commits en el repositorio que ya modifican las inserciones recientes de `security_events` para poblar la columna `username` automáticamente cuando esté disponible.
- Si quieres que aplique la migración en tu base de datos ahora (ejecutarla), dímelo y la ejecuto — necesitaremos acceso al servidor MySQL y preferiblemente un backup previo.
