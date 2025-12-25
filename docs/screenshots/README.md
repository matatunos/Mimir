Guía para capturas de pantalla
=============================

Este directorio contiene las capturas usadas por el manual de usuario.

Nombres recomendados y relación con el manual (`docs/user_manual.md`):
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

Recomendaciones de formato:
- Formato: PNG preferido para capturas de interfaz; JPG aceptable para imágenes grandes.
- Tamaño: 1280×720 o 1920×1080 según resolución objetivo; para miniaturas use 800–1200px de ancho.
- Optimización: usa `pngquant` o `mozjpeg` para reducir tamaño sin perder calidad.

Cómo añadir una captura al repositorio:
1. Guarda la imagen con el nombre recomendado en `docs/screenshots/`.
2. Añade/commitea el archivo:

```bash
git add docs/screenshots/03-my-files.png
git commit -m "docs: add screenshot 03-my-files.png"
git push
```

Automatización (opcional)
-------------------------
Puedes usar el ejemplo de `tools/puppeteer/screenshot_example.js` para capturar automáticamente la página de inicio tras login. El script es un ejemplo y no incluye credenciales en el repositorio.

Instalación de dependencias (en la carpeta del proyecto):

```bash
npm init -y
npm install puppeteer
```

Ejecutar el script (proporcionando variables de entorno):

```bash
BASE_URL=https://doc.favala.es USERNAME=demo_user PASSWORD="MiPass123" node tools/puppeteer/screenshot_example.js
```

Advertencias de seguridad
- No incluyas credenciales en el repositorio ni en commits.
- Guarda las imágenes y credenciales en un entorno seguro.

Si necesitas que genere las capturas por ti en un entorno controlado, dímelo y te propongo el proceso seguro (temporal credentials, ejecución en runner aislado, y eliminación posterior).