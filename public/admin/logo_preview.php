<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/Config.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();
$configClass = new Config();
$logger = new Logger();

$brandingDir = BASE_PATH . '/public/uploads/branding';

// support embedded mode
$isEmbed = isset($_GET['embed']) && $_GET['embed'] === '1';

$file = basename($_GET['file'] ?? '');
if ($file === '' || !preg_match('/^[A-Za-z0-9_\-\.]+$/', $file)) {
    http_response_code(400);
    echo "Archivo no válido";
    exit;
}

$origPath = $brandingDir . '/' . $file;
if (!file_exists($origPath)) {
    http_response_code(404);
    echo "Archivo no encontrado: " . htmlspecialchars($file);
    exit;
}

// Handle selection from preview (allow selecting original or any variant)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_file'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token inválido';
    } else {
        $sel = basename($_POST['select_file']);
        $candidate = $brandingDir . '/' . $sel;
        if (!file_exists($candidate)) {
            $error = 'Archivo seleccionado no encontrado';
        } else {
            $rel = 'uploads/branding/' . $sel;
            $configClass->set('site_logo', $rel);
            $logger->log($user['id'], 'logo_selected_preview', 'system', null, "Logo seleccionado desde preview: $sel");
            $_SESSION['flash_logo_gallery_success'] = 'Logo seleccionado: ' . $sel;
            header('Location: /admin/logo_gallery.php');
            exit;
        }
    }
}

// Find variants: look in processed, processed/grid, selected
$variants = [];
$dirs = [
    $brandingDir,
    $brandingDir . '/processed',
    $brandingDir . '/processed/grid',
    $brandingDir . '/selected'
];
$baseNoExt = pathinfo($file, PATHINFO_FILENAME);
foreach ($dirs as $d) {
    if (!is_dir($d)) continue;
    $files = scandir($d);
    foreach ($files as $f) {
        if (in_array($f, ['.', '..'])) continue;
        if (stripos($f, $baseNoExt) !== false) {
            $variants[] = ['path' => 'uploads/branding' . str_replace($brandingDir, '', $d) . '/' . $f, 'file' => $f, 'dir' => $d];
        }
    }
}

// Always add the original as first option
array_unshift($variants, ['path' => 'uploads/branding/' . $file, 'file' => $file, 'dir' => $brandingDir]);

if (!$isEmbed) {
    renderPageStart('Vista previa logo', 'config', true);
    renderHeader('Vista previa logo', $user, $auth);
    renderSidebar('config', true);
    echo "<main class=\"page-content\"><div class=\"container\"><h2>Vista previa: " . htmlspecialchars($file) . "</h2>";
} else {
    echo "<div class=\"logo-preview-embed\"><h3>Vista previa: " . htmlspecialchars($file) . "</h3>";
}
?>
  <div class="container">
    <h2 style="display:none"><?php echo htmlspecialchars($file); ?></h2>
    <p>Selecciona la variante que desees establecer como logo del sitio.</p>

    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
      <?php foreach ($variants as $v): ?>
        <div style="width:320px; border:1px solid var(--border-color); padding:0.5rem; border-radius:6px; background:var(--bg-secondary);">
          <div style="height:180px; display:flex; align-items:center; justify-content:center; overflow:hidden; background:white;">
            <img src="/_asset.php?f=<?php echo rawurlencode($v['path']); ?>" alt="<?php echo htmlspecialchars($v['file']); ?>" style="max-width:100%; max-height:100%;">
          </div>
          <div style="margin-top:0.5rem; display:flex; justify-content:space-between; align-items:center;">
            <div style="font-size:0.9rem; word-break:break-all; max-width:180px"><?php echo htmlspecialchars($v['file']); ?></div>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
              <input type="hidden" name="select_file" value="<?php echo htmlspecialchars($v['file']); ?>">
              <button class="btn btn-primary" type="submit">Seleccionar</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:1rem;"><a class="btn" href="/admin/logo_gallery.php?embed=1">Volver a galería</a></div>
  </div>
<?php
if (!$isEmbed) {
    echo "</main>";
    echo "</body></html>";
} else {
    echo "</div>";
}
