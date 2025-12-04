# Guía de Configuración LDAP/Active Directory para Mimir

## Tu Configuración
- **Servidor**: 192.168.1.254
- **Usuario de prueba**: nacho
- **DN del usuario**: CN=nacho,CN=Users,DC=favala,DC=es
- **Dominio**: favala.es

## Paso 1: Probar la Conexión LDAP

Ejecuta el script de prueba que he creado:

```bash
cd /opt/Mimir
php test_ldap.php
```

El script te pedirá la contraseña de 'nacho' y probará:
1. Conexión de red al servidor
2. Conexión LDAP
3. Búsqueda del usuario
4. Autenticación

**El script te dirá exactamente qué valores usar en Mimir.**

## Paso 2: Configurar en Mimir

1. Ve a: **http://192.168.1.16/admin_config.php**
2. Haz clic en la pestaña **"LDAP/AD"**
3. Introduce estos valores:

### Configuración Básica

**Servidor LDAP**: `192.168.1.254`

**Puerto**: `389`
- Usa 389 para LDAP estándar
- Usa 636 si tu AD requiere LDAPS (cifrado SSL)

**Base DN**: `DC=favala,DC=es`

**Patrón User DN**: `CN={username},CN=Users,DC=favala,DC=es`
- Este patrón construye el DN completo del usuario
- `{username}` se reemplaza automáticamente con el nombre de usuario

### Seguridad

**¿Necesitas TLS?**
- **NO** si tu AD está en red local confiable (192.168.x.x)
- **SÍ** si accedes por Internet o necesitas cifrado
- **SÍ** si tu política de seguridad lo requiere

**Usar SSL**: `No` (deja desmarcado)
- Solo marca esto si usas puerto 636 (LDAPS)

**Usar StartTLS**: `No` (deja desmarcado)
- Solo marca esto si tu AD lo requiere
- StartTLS cifra la conexión en puerto 389

### Atributos de Active Directory

**Filtro de Usuario**: `(sAMAccountName={username})`
- Para Active Directory usa siempre este filtro

**Atributo Username**: `sAMAccountName`

**Atributo Email**: `mail`

**Atributo Nombre**: `displayName`

### Credenciales de Administrador (OPCIONAL)

**DN Admin**: *Déjalo vacío*

**Password Admin**: *Déjalo vacío*

⚠️ Solo necesitas esto si:
- El script test_ldap.php dice que anonymous bind falló
- Ves error "Operations error" al intentar login

Si lo necesitas:
```
DN Admin: CN=Administrator,CN=Users,DC=favala,DC=es
Password Admin: [contraseña del administrador de AD]
```

## Paso 3: Probar en Mimir

1. **NO cierres sesión** en tu sesión actual de admin
2. Abre una **ventana privada/incógnito** del navegador
3. Ve a: http://192.168.1.16/login.php
4. Intenta login con:
   - Usuario: `nacho`
   - Contraseña: [la contraseña de AD]

## Diagnóstico de Problemas

### ❌ Error: "No se puede conectar al servidor"
**Causa**: Firewall o servidor apagado
**Solución**:
```bash
# Prueba conectividad
ping 192.168.1.254
telnet 192.168.1.254 389

# En el controlador de dominio, verifica que el servicio esté activo
```

### ❌ Error: "Usuario no encontrado"
**Causa**: Base DN o filtro incorrectos
**Solución**:
1. Verifica en AD que el usuario está en CN=Users
2. Si está en otra OU (Organizational Unit), cambia el patrón:
   ```
   CN={username},OU=TuOU,DC=favala,DC=es
   ```

### ❌ Error: "Autenticación fallida" pero la contraseña es correcta
**Causa**: Falta DN Admin o el usuario está deshabilitado en AD
**Solución**:
1. Verifica en AD que la cuenta NO esté deshabilitada
2. Verifica que NO esté expirada
3. Intenta configurar DN Admin y Password Admin

### ❌ Error: "Operations error"
**Causa**: Necesitas credenciales de admin para búsquedas
**Solución**: Configura DN Admin y Password Admin

### ❌ El login no hace nada / no cambia
**Causa**: PHP LDAP no está instalado (ya lo instalamos)
**Verificar**:
```bash
php -m | grep ldap
```
Debe mostrar "ldap"

## Logs de Diagnóstico

Para ver errores detallados:

```bash
# Ver logs de Apache
tail -f /var/log/apache2/error.log

# Ver logs de PHP
tail -f /var/log/php8.4-fpm.log  # o php-fpm.log según configuración
```

## Pruebas Adicionales

Si el script test_ldap.php falla, prueba con diferentes configuraciones:

### Opción 1: Sin cifrado (LAN local)
```
Puerto: 389
Usar SSL: No
Usar StartTLS: No
```

### Opción 2: Con StartTLS
```
Puerto: 389
Usar SSL: No
Usar StartTLS: Sí
```

### Opción 3: Con LDAPS
```
Puerto: 636
Usar SSL: Sí
Usar StartTLS: No
```

## Ventajas de LDAP/AD

Una vez configurado:
- ✅ Los usuarios usan su contraseña de Windows/AD
- ✅ No necesitas crear cuentas manualmente
- ✅ Los usuarios se crean automáticamente al primer login
- ✅ Cambios de contraseña en AD se reflejan inmediatamente
- ✅ Usuarios deshabilitados en AD no pueden entrar

## Usuarios Locales vs LDAP

- **Admin local** siempre funciona (no usa LDAP)
- Puedes tener usuarios locales Y usuarios LDAP al mismo tiempo
- Los usuarios locales tienen prioridad (se prueban primero)

## ¿Preguntas Frecuentes?

**¿Puedo tener algunos usuarios en LDAP y otros locales?**
Sí, totalmente. El sistema primero intenta autenticar localmente, luego por LDAP.

**¿Qué pasa si el servidor AD se cae?**
Los usuarios LDAP no podrán entrar, pero el admin local sí puede.

**¿Se sincronizan las contraseñas?**
No, LDAP autentica contra AD en cada login. No se almacenan contraseñas LDAP en Mimir.

**¿Los usuarios LDAP pueden compartir archivos?**
Sí, tienen las mismas funciones que usuarios locales.

**¿Puedo hacer a un usuario LDAP administrador?**
Sí, después de su primer login, ve a Admin → Usuarios y cámbialo a rol "admin".
