# Documentación: Logo y procesamiento de branding

Este documento resume el funcionamiento, ubicación y procedimientos relacionados con los logos (branding) en la aplicación.

## Resumen

La aplicación permite subir logos desde la interfaz de administración, intentar eliminar/transparentar el fondo automáticamente (con `Imagick` o `GD`), generar variantes procesadas y ofrecer una galería para que un administrador previsualice y seleccione la versión que se usará como `site_logo`.

## Rutas y ficheros relevantes

- Directorio de uploads de branding: `public/uploads/branding/`
  - `public/uploads/branding/<archivo>`: ficheros originales subidos.
  - `public/uploads/branding/processed/`: variantes re-procesadas (pipeline automático/experimental).
  - `public/uploads/branding/processed/grid/`: variantes generadas por búsqueda de parámetros (fuzz/blur/niveles).
  - `public/uploads/branding/selected/`: copia de la variante elegida por el heurístico automático.
- UI/admin:
  - `public/admin/config.php` — página de configuración de branding. Añadido botón "Galería de logos" y el JS que carga la galería embebida en un modal.
  - `public/admin/logo_gallery.php` — lista los logos disponibles y ofrece botones de "Vista previa" / "Seleccionar". Soporta `?embed=1` para render solo el contenido interno (sin layout completo).
  - `public/admin/logo_preview.php` — muestra el original y variantes detectadas y permite seleccionar cualquiera. Soporta `?embed=1`.
- Helpers/serving:
  - `/_asset.php?f=uploads/branding/...` — sirve las imágenes desde `public/uploads/branding` respetando rutas relativas.

## Flujo general

1. Un administrador sube un logo desde la página de configuración o mediante otro mecanismo de upload.
2. Tras la subida, el código intenta aplicar limpieza/transparentado automático llamando a `makeBackgroundTransparentIfPossible()` dentro de `config.php` (usa `Imagick` si está disponible, y `GD` como fallback).
3. Se generan variantes en `processed/` y, en pruebas más agresivas, en `processed/grid/`. El script de grid calcula estadísticas alfa y copia la mejor variante a `selected/`.
4. El administrador abre la `Galería de Logos` (`logo_gallery.php`) — puede previsualizar variantes (`logo_preview.php`) dentro del modal embebido y elegir la que prefiera.
5. Al seleccionar, la app guarda la ruta relativa en la configuración (`site_logo` → `uploads/branding/<file>`) y escribe un log de actividad.

## Selección y efectos

- Seleccionar un logo actualiza la clave `site_logo` en la configuración (ruta relativa `uploads/branding/<file>`).
- La selección se registra en el log con eventos como `logo_selected` o `logo_selected_preview`.
- Actualmente la selección no sobrescribe automáticamente un `logo.png` canónico en el directorio; si se desea copiar a `public/uploads/branding/logo.png` al seleccionar, se puede añadir esa copia en el handler de selección (se puede implementar on-request).

## Implementación técnica del preview embebido

- Para evitar duplicar el layout, las páginas `logo_gallery.php` y `logo_preview.php` aceptan `?embed=1` y devuelven solo el fragmento HTML interior.
- `public/admin/config.php` incluye JS que hace `fetch('/admin/logo_gallery.php?embed=1', {credentials:'same-origin'})` y carga la respuesta en un modal. También se añadió la función `bindGalleryModalControls()` que intercepta los enlaces de vista previa y carga la preview en el mismo modal, manteniendo un historial local y añadiendo un botón "Volver a galería".

## Procesamiento de imagen (detalles y comandos usados)

- Herramientas empleadas:
  - ImageMagick CLI (`convert`, `identify`) para experimentación y generación de variantes.
  - PHP `Imagick` cuando está disponible (se fuerza `png32`/TrueColorAlpha y se activan canales alfa).
  - GD como fallback con máscara y composición manual.

- Ejemplo de pipeline usado con ImageMagick CLI (conceptual):

  convert input.png \
    -alpha extract \
    -blur 0x2 \
    -level 10%,90% \
    -compose CopyOpacity -composite \
    -background none png32:out.png

- Flags adicionales intentadas para forzar alfa de 8-bit:
  - `png32:out.png`, `-define png:color-type=6`, `-depth 8`

- Observación: en muchos casos `identify -verbose` mostraba `Alpha: 1-bit` aun cuando el fichero se declaraba `TrueColorAlpha` con `Depth: 8-bit`. Esto indica que la máscara final era binaria (1-bit) — produce bordes duros/artefactos. En imágenes con fondos ruidosos el resultado automático no es fiable.

## Heurísticos y criterios

- Antes de aplicar una transparencia automática se intenta detectar si la imagen tiene un fondo suficientemente uniforme; si no, el algoritmo evita transformar para no producir un logo invisible o con artefactos.
- Durante la búsqueda de variantes (grid), se calcula la media/desviación estándar del canal alfa para elegir la variante con mayor continuidad de transparencia (valores alfa mixtos en lugar de 0/1 puros).

## Problemas conocidos y cómo depurarlos

- Si aparece un logo con borde gris o artefactos:
  - Ejecutar `identify -verbose public/uploads/branding/<file>` y comprobar la sección `Alpha` y las estadísticas de canal.
  - Verificar que `Imagick` esté activo: `php -m | grep imagick`.
  - Probar una re-procesación manual usando el pipeline CLI anterior y ajustar `-blur` / `-level` / `-fuzz`.

- Si la preview embebida devuelve errores:
  - Revisar que la URL de fetch sea relativa y use `?embed=1` (ya está implementado).
  - Comprobar permisos de fichero y que `/_asset.php` pueda servir la ruta.

## Logs y eventos de auditoría

- Eventos registrados:
  - `logo_transparency` / `logo_transparency_failed` — resultados de intentos automáticos de transparentado.
  - `logo_selected` — selección desde la galería.
  - `logo_selected_preview` — selección desde la vista previa.

## Operaciones de mantenimiento / scripts útiles

- Para regenerar variantes manualmente se usan scripts temporales (en pruebas se ejecutaron desde `/tmp`) que recorren combinaciones de `fuzz/blur/level` y escriben en `processed/grid/`.
- Un operador puede copiar manualmente la variante deseada a `selected/` o al `branding` raíz para probarla en vivo.

## Recomendaciones

- Para logos con fondos no uniformes, favorezca la selección manual desde la galería en lugar de confiar en el proceso automático.
- Si se desea un logo canónico (por ejemplo `/uploads/branding/logo.png`), implementar la copia al seleccionar y actualizar `layout.php` para preferir esa ruta si existe.

## Preguntas frecuentes rápidas

- ¿Dónde se guarda lo seleccionado? — En `site_logo` (valor string) apuntando a `uploads/branding/<file>`.
- ¿Se sobrescribe el original al seleccionar? — No, la selección solo actualiza la configuración. No se hacen sobrescrituras por defecto.

---
Documento generado automáticamente por el asistente; mantenga este fichero actualizado cuando cambien rutas o comportamientos.
