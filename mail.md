**Correo: Manual de Usuario**

Este documento explica, en lenguaje de usuario (no administrador), en qué situaciones recibirás correos electrónicos desde la aplicación y qué hacer si no los recibes.

**Resumen rápido**
- Tipo de correos: los correos que envía la aplicación son transaccionales (no spam): restablecer contraseña, notificaciones de compartición, invitaciones, avisos de caducidad, y mensajes del sistema.
- Frecuencia: se envían inmediatamente cuando se produce el evento correspondiente.
- Si no recibes un correo: revisa la carpeta de spam, confirma tu dirección en tu perfil y contacta al administrador si continúa el problema.

---

## 1. ¿Cuándo recibirás correos?
A continuación se listan las situaciones habituales en las que la aplicación envía un correo a usuarios finales.

1.1. Restablecimiento de contraseña
- Cuándo: cuando solicitas "¿Has olvidado tu contraseña?" desde la pantalla de inicio de sesión.
- Qué contiene: un enlace único y temporal para crear una nueva contraseña.
- Vigencia: el enlace suele expirar (por seguridad). Sigue las instrucciones del correo.

1.2. Invitación / Creación de cuenta por un administrador
- Cuándo: si un administrador crea una cuenta para ti o te envía una invitación para unirte al sistema.
- Qué contiene: instrucciones para completar tu perfil y establecer una contraseña.

1.3. Notificaciones de compartición (cuando alguien te comparte un archivo o carpeta)
- Cuándo: cuando otro usuario crea una compartición dirigida a ti (por e-mail) o genera un enlace público y te lo envía a tu correo.
- Qué contiene: enlace para acceder al archivo o carpeta, remitente, permisos (lectura/descarga/edición) y opción de contraseña si la compartición está protegida.

1.4. Avisos de caducidad de compartición
- Cuándo: si la compartición que recibiste tiene fecha de caducidad y el sistema está configurado para avisar antes de que expire.
- Qué contiene: aviso de que el enlace/compartición expirará en X días y cómo descargar o renovar el acceso si procede.

1.5. Revocación / eliminación de compartición
- Cuándo: cuando el remitente o un administrador revoca el acceso o elimina la compartición.
- Qué contiene: información de qué compartición fue eliminada y quién lo realizó.

1.6. Notificaciones de actividad (opcional según configuración)
- Cuándo: si el sistema está configurado para avisar cuando alguien descarga un archivo que compartiste o realiza otras acciones importantes.
- Qué contiene: normalmente nombre del archivo, quién lo descargó y cuándo.

1.7. Mensajes del sistema / comunicación administrativa
- Cuándo: el administrador puede enviar mensajes o avisos (mantenimiento, cambios importantes, actualizaciones de política).
- Qué contiene: texto del aviso y acciones recomendadas (p. ej. mantenimiento programado).

1.8. Autenticación de dos factores (2FA)
- Notas: Si usas 2FA, este sistema tiene soporte para TOTP (aplicaciones como Google Authenticator) y Duo (push). Normalmente no se usan correos para enviar códigos de 2FA en esta aplicación; revisa las instrucciones de 2FA en tu perfil.

---

## 2. Ejemplos de asuntos que podrías ver
- "[Files] Restablece tu contraseña"
- "[Files] Has recibido un archivo de Juan Pérez"
- "[Files] Invitación para unirte a Favala Files"
- "[Files] Tu enlace de descarga expirará en 2 días"
- "[Files] Aviso del administrador: mantenimiento programado"

(El prefijo puede variar según la instalación.)

---

## 3. Qué hacer si no recibes un correo
1. Revisa la carpeta de correo no deseado / spam.
2. Confirma que la dirección de correo asociada a tu cuenta es correcta: ve a tu perfil y revisa el campo de email.
3. Si el correo tiene un enlace, comprueba que no haya sido truncado por el cliente de correo (a veces se rompen enlaces largos). Copia y pega la URL completa en el navegador si es necesario.
4. Pide al remitente que te reenvíe la notificación (si aplica) o que genere nuevamente la compartición.
5. Contacta con el administrador del sitio y proporciona la hora aproximada y tu dirección de correo; el administrador puede revisar los registros y probar el envío (tiene un botón "TEST correo" en la configuración).

---

## 4. Seguridad y buenas prácticas
- No compartas enlaces de restablecimiento de contraseña con nadie.
- Si recibes un correo sospechoso (p.ej. te pide credenciales en un sitio distinto), contacta con el administrador antes de seguir enlaces.
- Los correos transaccionales son necesarios para el funcionamiento (no suelen incluir publicidad). Si quieres reducir notificaciones, consulta al administrador si hay opciones para desactivar notificaciones de actividad.

---

## 5. Pruebas y diagnóstico (qué pasa por debajo, para entender los tiempos)
- Los correos se envían en cuanto ocurre el evento: p. ej. compartir un archivo dispara el envío inmediatamente.
- Si el administrador ha configurado el servidor de correo incorrectamente, los correos pueden tardar, fallar o no enviarse; en ese caso el administrador usará herramientas de diagnóstico y el botón "TEST correo".

---

## 6. Contacto para problemas
Si no recibes correos importantes después de comprobar spam y tu dirección en el perfil, contacta con el administrador del sitio y facilita:
- Tu dirección de correo afectada
- Fecha y hora aproximada del evento
- Captura de pantalla del error si existe

---

Si quieres, puedo añadir una versión corta para mostrar en la interfaz de ayuda (tooltip) o una versión en inglés. ¿La quieres también en inglés o preferirías que la ponga en `docs/` con formato adicional (por ejemplo, `mail.md` + `mail_es.md`)?
