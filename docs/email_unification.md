# Unificación del envío de correo en Mimir

Resumen
- Se ha estandarizado el envío de correo usando la clase `Notification` como API pública.
- `Notification` actúa como un wrapper y delega la implementación SMTP a `classes/Email.php`.

Por qué
- Evita que distintos puntos del código usen mecanismos diferentes (directo a `mail()`, instancias de `Email`, PHPMailer, etc.).
- Facilita cambios futuros: solo hay que actualizar `classes/Email.php` o el wrapper `Notification`.

Qué se cambió
- Se reemplazaron las instanciaciones directas de `Email` por `Notification` en los siguientes ficheros clave:
  - `public/admin/user_actions.php`
  - `public/admin/2fa_management.php`
  - `public/invite.php`
  - `classes/Share.php`
  - `classes/Invitation.php`
  - `tools/test_email_send.php`
  - `tools/notification_worker.php`
  - `tools/resend_share_notification.php`
  - `tools/add_notify_and_send.php`

Cómo rotar la contraseña SMTP
1. En el servidor, ejecuta el helper interactivo (guarda la contraseña, opcionalmente la cifra):

```bash
php tools/set_smtp_config.php --host=smtp.example.com --port=587 --encryption=tls --username=noreply@example.com
```

Este script solicitará la contraseña y la guardará en la tabla `config`. Si la instalación está configurada para cifrar la contraseña, el valor se guardará como `ENC:<base64_iv>:<base64_cipher>` y la clave de descifrado se leerá desde `/opt/Mimir/.secrets/smtp_key`.

2. Prueba el envío con:

```bash
php tools/test_email_send.php you@example.com --verbose
```

3. Revisa los logs en `storage/logs/smtp_debug.log` y `storage/logs/invite_debug.log` para diagnósticos.

Notas para desarrolladores
- Si necesitas cambiar el mecanismo de envío (por ejemplo, usar una librería externa como PHPMailer o una API de terceros), implementa el nuevo transport en `classes/Email.php` o añade un nuevo `EmailTransport` y actualiza `Notification` para permitir inyección de dependencias.
- Evitar usar `mail()`/sendmail a menos que se necesite explícitamente y se tenga un MTA local confiable instalado.

Contacto
- Si quieres que yo aplique la rotación o verifique la conversión a cifrado, proporciona la contraseña mediante un canal seguro o ejecútalo en el servidor con el helper anterior.
