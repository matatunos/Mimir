<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireLogin();

$userId = Auth::getUserId();
$shareManager = new ShareManager();

// Handle share deletion
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_share') {
        $shareId = $_POST['share_id'] ?? null;
        if ($shareId) {
            try {
                $shareManager->deleteShare($shareId, $userId);
                $message = 'Share deleted successfully';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Failed to delete share: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($_POST['action'] === 'deactivate_share') {
        $shareId = $_POST['share_id'] ?? null;
        if ($shareId) {
            try {
                $shareManager->deactivateShare($shareId, $userId);
                $message = 'Share deactivated successfully';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Failed to deactivate share: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

$shares = $shareManager->getUserShares($userId);

$siteName = SystemConfig::get('site_name', APP_NAME);
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] == '1';
ob_start();
?>
<div class="page-header">
    <h1>
        <i class="fas fa-share-nodes"></i>
        Mis Enlaces Compartidos
    </h1>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo escapeHtml($message); ?>
    </div>
<?php endif; ?>

<div class="content-card">
    <?php if (empty($shares)): ?>
        <div class="empty-message">
            <i class="fas fa-share-nodes"></i>
            <p>Aún no tienes enlaces compartidos.</p>
            <p><a href="dashboard.php">Sube y comparte archivos</a></p>
        </div>
    <?php else: ?>
        <table class="file-table">
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Tipo de Enlace</th>
                    <th>Estado</th>
                    <th>Creado</th>
                    <th>Enlace</th>
                    <th style="width: 180px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shares as $share): ?>
                <tr>
                    <td>
                        <i class="fas fa-file-alt" style="color: #94a3b8; margin-right: 0.5rem;"></i>
                        <?php echo escapeHtml($share['original_filename']); ?>
                    </td>
                            <td>
                                <div class="share-type">
                                    <?php if ($share['share_type'] === 'time'): ?>
                                        <span class="share-type-label">
                                            <i class="fas fa-clock"></i> Temporal
                                        </span>
                                        <span class="share-type-details">
                                            Expira: <?php echo date('d M Y', strtotime($share['expires_at'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="share-type-label">
                                            <i class="fas fa-download"></i> Por Descargas
                                        </span>
                                        <span class="share-type-details">
                                            <?php echo $share['current_downloads']; ?>/<?php echo $share['max_downloads']; ?> descargas
                                        </span>
                                    <?php endif; ?>
                                    <span class="badge" style="background: #dbeafe; color: #1e40af; display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.25rem 0.5rem; font-size: 0.75rem; margin-top:0.3em;">
                                        <i class="fas fa-user" style="font-size: 0.7rem;"></i> <?php echo (int)($share['public_downloads'] ?? 0); ?><?php if (!empty($share['max_downloads'])): ?>/<?php echo $share['max_downloads']; ?><?php endif; ?>
                                    </span>
                                    <?php if (!empty($share['max_downloads']) && $share['public_downloads'] >= $share['max_downloads']): ?>
                                        <span class="badge badge-danger" style="margin-top:0.3em;"><i class="fas fa-exclamation-triangle"></i> Límite alcanzado</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                    <td>
                        <?php if ($share['is_active']): ?>
                            <span class="badge badge-success">
                                <i class="fas fa-check"></i> Activo
                            </span>
                        <?php else: ?>
                            <span class="badge badge-secondary">
                                <i class="fas fa-ban"></i> Inactivo
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="color: #64748b;"><?php echo timeAgo($share['created_at']); ?></td>
                    <td>
                        <?php if ($share['is_active']): ?>
                            <input type="text" 
                                   class="share-link-input" 
                                   value="<?php echo BASE_URL . '/share.php?token=' . escapeHtml($share['share_token']); ?>" 
                                   readonly 
                                   onclick="this.select(); navigator.clipboard.writeText(this.value);"
                                   title="Click para copiar">
                        <?php else: ?>
                            <span class="text-muted">Enlace inactivo</span>
                        <?php endif; ?>
                    </td>
                            <td>
                                <div class="btn-group">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_share">
                                        <input type="hidden" name="share_id" value="<?php echo $share['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar este enlace?')" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
if ($isAjax) {
    echo $content;
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml($siteName); ?> - Compartidos</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
    <div class="navbar">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-cloud"></i>
                <?php echo escapeHtml($siteName); ?>
            </div>
            <div class="navbar-menu">
                <a href="dashboard.php">
                    <i class="fas fa-folder"></i> Mis Archivos
                </a>
                <a href="shares.php" class="active">
                    <i class="fas fa-share-alt"></i> Compartidos
                </a>
                <?php if (Auth::isAdmin()): ?>
                <a href="admin_dashboard.php">
                    <i class="fas fa-shield-alt"></i> Administración
                </a>
                <?php endif; ?>
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                </a>
            </div>
        </div>
    </div>
    <div class="container">
        <?php echo $content; ?>
    </div>
</body>
</html>
