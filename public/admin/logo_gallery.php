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

$success = '';
$error = '';

// Support embedded mode (no full layout) when ?embed=1 is provided
$isEmbed = isset($_GET['embed']) && $_GET['embed'] === '1';

$brandingDir = BASE_PATH . '/public/uploads/branding';

// Handle selection POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'select_logo') {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token inválido';
    } else {
        $file = basename($_POST['filename'] ?? '');
        $candidate = $brandingDir . '/' . $file;
        if ($file === '' || !file_exists($candidate)) {
            $error = 'Archivo no encontrado';
        } else {
            // Save config to point to the selected branding file (relative path)
            $rel = 'uploads/branding/' . $file;
            $configClass->set('site_logo', $rel);
            $logger->log($user['id'], 'logo_selected', 'system', null, "Logo seleccionado: $file");
            $success = 'Logo seleccionado correctamente.';
            // Redirect to avoid resubmit
            $_SESSION['flash_logo_gallery_success'] = $success;
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
}

// flash from redirect
if (!empty($_SESSION['flash_logo_gallery_success'])) {
    $success = $_SESSION['flash_logo_gallery_success'];
    unset($_SESSION['flash_logo_gallery_success']);
}

// Collect images
$images = [];
if (is_dir($brandingDir)) {
    $files = scandir($brandingDir);
    foreach ($files as $f) {
        if (in_array($f, ['.', '..'])) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg','gif'])) {
            $images[] = $f;
        }
    }
}

// Render page (or only inner content when embedded)
if (!$isEmbed) {
    renderPageStart('Galería de Logos', 'config', true);
    renderHeader('Galería de Logos', $user, $auth);
    renderSidebar('config', true);
    echo "<main class=\"page-content\"><div class=\"container\"><h2>Galería de Logos</h2>";
} else {
    echo "<div class=\"logo-gallery-embed\">";
    echo "<h3>Galería de Logos</h3>";
}
?>
<?php
// Note: the rest of the markup is identical for embed or full page
?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (empty($images)): ?>
            <p>No se han encontrado logos en <code>public/uploads/branding</code>.</p>
        <?php else: ?>
            <div style="display:grid; grid-template-columns: repeat(auto-fill,minmax(180px,1fr)); gap:1rem; align-items:start;">
                <?php foreach ($images as $img): ?>
                    <div style="background:var(--bg-secondary); padding:0.5rem; border-radius:6px; text-align:center;">
                            <div style="height:120px; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                            <img src="/_asset.php?f=uploads/branding/<?php echo rawurlencode($img); ?>" alt="<?php echo htmlspecialchars($img); ?>" style="max-height:110px; max-width:160px;">
                        </div>
                        <div style="margin-top:0.5rem; font-size:0.9rem;">
                            <div style="word-break:break-all;"><?php echo htmlspecialchars($img); ?></div>
                            <div style="display:flex; gap:0.5rem; justify-content:center; margin-top:0.5rem;">
                                <a class="btn btn-outline" href="/admin/logo_preview.php?file=<?php echo rawurlencode($img); ?>&embed=1">Vista previa</a>
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="select_logo">
                                    <input type="hidden" name="filename" value="<?php echo htmlspecialchars($img); ?>">
                                    <button class="btn btn-primary" type="submit">Seleccionar</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php
if (!$isEmbed) {
    echo "</main>";
    // Small helper: close page html
    echo "</body></html>";
} else {
    echo "</div>"; // close embed wrapper
}
