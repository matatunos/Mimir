<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/Config.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/Share.php';

$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();
$config = new Config();
$fileClass = new File();
$shareClass = new Share();
$logger = new Logger();

$fileId = intval($_GET['file_id'] ?? 0);
$error = '';
$success = '';

$file = $fileClass->getById($fileId);
if (!$file || $file['user_id'] != $user['id']) {
    header('Location: ' . BASE_URL . '/user/files.php?error=' . urlencode('Archivo no encontrado'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
    } else {
        try {
            $defaultMaxDays = $config->get('default_max_share_days', DEFAULT_MAX_SHARE_DAYS);
            $maxDays = min(intval($_POST['max_days'] ?? $defaultMaxDays), $defaultMaxDays);
            $maxDownloads = intval($_POST['max_downloads'] ?? 0) ?: null;
            $password = !empty($_POST['password']) ? $_POST['password'] : null;
            
            $result = $shareClass->create($fileId, $user['id'], [
                'max_days' => $maxDays,
                'max_downloads' => $maxDownloads,
                'password' => $password
            ]);
            
            $logger->log($user['id'], 'share_create', 'share', $result['id'], 'Usuario compartió archivo', [
                'file_id' => $fileId,
                'max_days' => $maxDays
            ]);
            
            header('Location: ' . BASE_URL . '/user/shares.php?success=' . urlencode('Enlace creado correctamente'));
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$isAdmin = ($user['role'] === 'admin');
renderPageStart('Compartir Archivo', 'files', $isAdmin);
renderHeader('Compartir Archivo: ' . htmlspecialchars($file['original_name']), $user);
?>

<div class="content">
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card">

    <div class="card-header" style="background: linear-gradient(135deg, #e9b149, #444e52); color: white; padding: 1.5rem;">
            <h2 class="card-title" style="color: white; margin: 0;">Crear Enlace de Compartición</h2>
        </div>
        <div class="card-body">
            <div class="mb-3" style="background: var(--bg-secondary); padding: 1rem; border-radius: 0.5rem;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div style="font-size: 2rem;"><i class="fas fa-file"></i></div>
                    <div style="flex: 1;">
                        <div style="font-weight: 500;"><?php echo htmlspecialchars($file['original_name']); ?></div>
                        <div style="font-size: 0.8125rem; color: var(--text-muted);">
                            <?php echo number_format($file['file_size'] / 1024 / 1024, 2); ?> MB
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                
                <div class="form-group">
                    <label>Días válido *</label>
                    <?php $defaultMaxDays = $config->get('default_max_share_days', DEFAULT_MAX_SHARE_DAYS); ?>
                    <input type="number" name="max_days" class="form-control" value="<?php echo $defaultMaxDays; ?>" min="1" max="<?php echo $defaultMaxDays; ?>" required>
                    <small style="color: var(--text-muted);">Máximo: <?php echo $defaultMaxDays; ?> días</small>
                </div>

                <div class="form-group">
                    <label>Descargas máximas</label>
                    <input type="number" name="max_downloads" class="form-control" placeholder="Ilimitado" min="1">
                    <small style="color: var(--text-muted);">Deja en blanco para ilimitado</small>
                </div>

                <div class="form-group">
                    <label>Contraseña (opcional)</label>
                    <input type="password" name="password" class="form-control" placeholder="Proteger con contraseña">
                    <small style="color: var(--text-muted);">Si está vacío, no requerirá contraseña</small>
                </div>

                <div style="display: flex; gap: 0.75rem;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-link"></i> Crear Enlace</button>
                    <a href="<?php echo BASE_URL; ?>/user/files.php" class="btn btn-outline">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php renderPageEnd(); ?>
