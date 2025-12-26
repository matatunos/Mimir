# Manual de Usuario — Mimir (usuario sin privilegios)

Última actualización: 25 de diciembre de 2025

Índice
- Introducción
- Requisitos y acceso
- Primera conexión / Iniciar sesión
- Recuperar contraseña
- Interfaz principal (Mi archivos)
- Subir archivos
- Carpetas y organización
- Previsualizar imágenes
- Compartir archivos (enlace privado / enviar por email)
- Publicar en Galería (enlace público, embebible)
- Mis Comparticiones (My Shares)
- Descargar archivos
- Perfil y ajustes básicos
- Seguridad y buenas prácticas
- Capturas de pantalla recomendadas (nombres de archivo)
- Cómo generar las capturas (opcional)

---

**Introducción**

Este documento explica de forma práctica y visual cómo usar la interfaz de Mimir para un usuario sin privilegios: subir, organizar, compartir y publicar imágenes en la galería pública. Está pensado para publicarse como `docs/user_manual.md` junto con capturas en `docs/screenshots/`.


**Requisitos y acceso**

- Navegador moderno: Chrome, Edge, Firefox u Opera.
- URL base de la instalación (ejemplo): `https://doc.favala.es` (sustituye por tu URL).
- Credenciales de usuario (correo/usuario y contraseña) proporcionadas por el administrador.


**1. Primera conexión / Iniciar sesión**

1. Abre la URL base en el navegador.
2. Haz clic en "Sign In" / "Iniciar sesión".
3. Introduce tu usuario y contraseña y pulsa `Sign In`.

(Sugerencia de captura: pantalla de login)

![Login](/docs/screenshots/01-login.png)


**2. Recuperar contraseña**

Si no recuerdas la contraseña, haz clic en "Forgot your password?" (o el enlace de restablecimiento) y sigue las instrucciones por correo.

(Sugerencia de captura: `screenshots/02-password-reset.png`)


**3. Interfaz principal — Mi archivos**

Al iniciar sesión verás "Mi archivos". Componentes principales:
- Barra superior: navegación / usuario.
- Selector de vista (Detallada, Iconos, Iconos XL).
- Lista / cuadrícula de archivos con acciones (descargar, compartir, publicar, eliminar).

(Sugerencia de captura: vista principal)

![Mi archivos](/docs/screenshots/03-my-files.png)


**4. Subir archivos**

1. Pulsa `⬆️ Upload Files` o arrastra y suelta (según configuración).
2. Selecciona uno o varios archivos y confirma.
3. Verás un mensaje de progreso y, al terminar, la ficha con resultados.

Consejos:
- Si trabajas con muchas imágenes, súbelas en lotes pequeños.
- Formatos compatibles: JPEG, PNG, GIF, PDF, etc. (según política del servidor).

(Sugerencia de capturas: `screenshots/04-upload-select.png`, `screenshots/05-upload-result.png`)

![Upload select](/docs/screenshots/04-upload-select.png)

![Upload result](/docs/screenshots/05-upload-result.png)


**5. Carpetas y organización**

- Crear carpeta: `Create folder` → escribe nombre → `Create`.
- Mover archivos: (Si la UI tiene arrastrar) arrastra a la carpeta; si no, utiliza acciones provistas.
- Buscar: usa el campo de búsqueda en la parte superior.

(Sugerencia de captura: `screenshots/06-create-folder.png`)


**6. Previsualizar imágenes**

- En la vista de iconos, haz clic en una miniatura para abrir la previsualización ampliada.
- La previsualización permite ver la imagen en tamaño grande y cerrar con la X.

(Sugerencia de captura: `screenshots/07-image-preview.png`)


**7. Compartir archivos (enlace privado / enviar por email)**

1. En la lista de archivos, pulsa el icono de enlace (Share).
2. Se abre el formulario de compartir:
   - Días de validez (máximo configurado por administradores).
   - Descargas máximas (opcional).
   - Contraseña (opcional).
   - Email destinatario (opcional) y mensaje.
3. Pulsa `Create Share` para generar el enlace. Si especificaste un email, el destinatario recibirá la notificación.

(Sugerencia de captura: `screenshots/08-share-form.png`)


**8. Publicar en Galería (enlace público y embebible)**

Hemos provisto un flujo rápido para publicar imágenes públicamente para incrustar en foros o webs.

1. En la lista de archivos, junto a cada imagen verás un botón de imagen (ícono de foto) coloreado — `Publish to gallery`.
2. Pulsa ese botón y el sistema hace lo siguiente automáticamente:
   - Crea (o reutiliza) un enlace público y un archivo público tokenizado en `public/sfiles/<token>.<ext>` para que la imagen pueda servirse directamente.
   - Reprocesa la imagen para anonimizar (se re-encodifica para eliminar EXIF/metadata).
3. Se abrirá un modal con:
   - El enlace público (por ejemplo `https://doc.favala.es/sfiles/69a0bcc74b6e7ba403cdfdbfd4a284cb.png`).
   - Botón `Copy` para copiar al portapapeles.
   - Botón `Close` para cerrar.

El modal también muestra si se está reutilizando un enlace ya creado.

Ejemplos de uso embed:
- HTML: `<img src="https://tudominio/s/<token>?raw=1" alt="Imagen pública">`
- BBCode: `[img]https://tudominio/s/<token>?raw=1[/img]`

(Sugerencia de captura: modal de publicación en galería)

![Galería - modal](/docs/screenshots/09-gallery-modal.png)


**9. Mis Comparticiones (My Shares)**

- Accede a `My Shares` para ver los enlaces que has creado.
- Verás badge para enlaces de galería, botón `Direct link` para copiar la URL pública tokenizada si existe.
- Desde aquí puedes desactivar o eliminar enlaces.

Nota importante sobre eliminación de comparticiones (para usuarios y administradores):

- La acción "Eliminar" en el panel de administración es ahora una eliminación suave (soft-delete): desactiva el enlace (`is_active = 0`) pero mantiene la entrada en la base de datos y los archivos públicos en `public/sfiles/`. Esto permite al equipo recuperar enlaces o investigar incidentes si un enlace se borra por error.
- Si se requiere borrado irreversible, el administrador puede usar la acción "Purgar permanentemente" desde el panel de administración, la cual eliminará la fila de la tabla `shares` y borrará los artefactos públicos asociados (token y ZIP). Esta acción es irreversible.

Si eres usuario normal, ponte en contacto con un administrador si necesitas que un enlace se restaure después de una eliminación accidental.

(Sugerencia de captura: `screenshots/10-my-shares.png`)


**10. Descargar archivos**

- Para descargar un archivo, pulsa `⬇️ Download` en la lista o en la página pública (si tienes el enlace y no está protegido por contraseña).
- Si el enlace tiene contraseña, primero se pedirá la contraseña.

(Sugerencia de captura: `screenshots/11-download.png`)


**11. Perfil y ajustes básicos**

- Accede a `My Profile` desde el menú de usuario (arriba a la derecha).
- Cambia tu nombre, idioma, y contraseña.

(Sugerencia de captura: `screenshots/12-profile.png`)


**12. Seguridad y buenas prácticas**

- No compartas enlaces privados por canales públicos.
- Para imágenes públicas (galería) usa el botón `Publish to gallery` conscientemente.
- Usa contraseñas en enlaces sensibles.
- Cierra sesión en equipos compartidos.


**13. Capturas de pantalla recomendadas**
Guarda las capturas en `docs/screenshots/` usando los nombres indicados para que el manual muestre imágenes al publicarlo.

- `01-login.png` — Formulario de inicio de sesión.
- `02-password-reset.png` — Página de recuperación de contraseña.
- `03-my-files.png` — Vista principal "Mi archivos" (detallada).
- `04-upload-select.png` — Selector de archivos al subir.
- `05-upload-result.png` — Resultados de subida.
- `06-create-folder.png` — Crear carpeta.
- `07-image-preview.png` — Modal de previsualización de imagen.
- `08-share-form.png` — Formulario de compartir (enlace privado).
- `09-gallery-modal.png` — Modal con enlace público y botón copiar.
- `10-my-shares.png` — Página "Mis comparticiones".
- `11-download.png` — Confirmación / botón de descarga.
- `12-profile.png` — Página de perfil.


**14. Cómo generar las capturas (opcional)**

Manual rápido (herramientas comunes):

- Captura desde el navegador (Windows/Mac/Linux): usar la tecla `Impr Pant` o herramientas como Snipping Tool, Flameshot, o las DevTools para recortar.
- Para crear capturas reproducibles automáticamente:
  - Instala `puppeteer` (Node.js) o `playwright` y crea un script que abra la URL, realice login y guarde capturas de pantalla.

Ejemplo básico con `puppeteer` (local):

```bash
npm init -y
npm i puppeteer
```

Script de ejemplo (headless-screenshot.js):

```js
const puppeteer = require('puppeteer');
(async ()=>{
  const browser = await puppeteer.launch({ args:['--no-sandbox','--disable-setuid-sandbox'] });
  const page = await browser.newPage();
  await page.goto('https://tuinstancia/login');
  // Rellenar credenciales - OJO: no incluyas credenciales en repositorios públicos
  await page.type('input[name=username]','TU_USUARIO');
  await page.type('input[name=password]','TU_CONTRASEÑA');
  await page.click('button[type=submit]');
  await page.waitForNavigation();
  await page.screenshot({ path: 'docs/screenshots/03-my-files.png', fullPage: true });
  await browser.close();
})();
```

Advertencia: automatizar logins con credenciales guardadas en archivos **no** es seguro para repositorios públicos.


---

Si quieres, puedo:
- Generar el fichero `docs/user_manual.md` (ya creado) y además añadir una plantilla `docs/screenshots/README.md` con instrucciones.
- Crear el script de `puppeteer` ejemplo en `tools/` para ayudarte a generar las capturas (no incluirá credenciales).

¿Quieres que añada el script de captura automática como ejemplo y la plantilla de `docs/screenshots/README.md`? 

---

(El manual se ha guardado en `docs/user_manual.md`.)

**15. Crear usuarios desde el servidor (CLI) — herramienta de administración**

Si eres administrador del servidor y necesitas crear una cuenta de usuario sin usar la interfaz web, hay una utilidad CLI incluida en el repositorio:

- Ruta del script: `tools/create_user.php`

Ejemplo de uso desde la raíz del proyecto:

```bash
php tools/create_user.php --username=demo_user --email=demo@example.com --full-name="Demo User"
```

Opcionalmente puedes pasar la contraseña en la línea de comandos (si no se indica, el script genera una contraseña segura aleatoria):

```bash
php tools/create_user.php --username=demo_user --email=demo@example.com --full-name="Demo User" --password="S3cret!"
```

Notas de seguridad y funcionamiento:
- El script usa la configuración de base de datos en `includes/config.php` y debe ejecutarse en el servidor donde la aplicación puede conectarse a la base de datos.
- No incluyas credenciales reales en repositorios públicos. Guarda las contraseñas de forma segura y no las publiques.
- El script carga las clases internas (`User`, `Logger`) y registrará la creación en el log de actividad.
- Tras ejecutar el comando, se imprime por stdout el `User ID` y la contraseña (si fue generada). Copia la contraseña a un lugar seguro y pásala al usuario de forma confidencial.

Si quieres que añadamos una política de rotación de contraseñas o un script para enviar las credenciales por email de forma segura, puedo proponerte una implementación.
