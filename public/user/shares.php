<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/Share.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();
$shareClass = new Share();
$logger = new Logger();

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = t('error_invalid_csrf');
    } else {
                try {
            $shareId = intval($_POST['share_id'] ?? 0);
            $share = $shareClass->getById($shareId);
            
            if (!$share || $share['user_id'] != $user['id']) {
                throw new Exception(t('error_link_not_found'));
            }
            
            if ($_POST['action'] === 'deactivate') {
                $shareClass->deactivate($shareId, $user['id']);
                $logger->log($user['id'], 'share_deactivate', 'share', $shareId, 'Usuario desactivó enlace');
                header('Location: ' . BASE_URL . '/user/shares.php?success=' . urlencode(t('link_deactivated')));
                exit;
            } elseif ($_POST['action'] === 'delete') {
                $shareClass->delete($shareId, $user['id']);
                $logger->log($user['id'], 'share_delete', 'share', $shareId, 'Usuario eliminó enlace');
                header('Location: ' . BASE_URL . '/user/shares.php?success=' . urlencode(t('link_deleted')));
                exit;
            }
            } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Obtener solo comparticiones activas
$allShares = $shareClass->getByUser($user['id']);

// Filtrar solo las comparticiones actualmente activas (activas, no expiradas, no alcanzado límite)
$shares = array_filter($allShares, function($share) {
    $isExpired = false;
    if (!empty($share['expires_at'])) {
        $expiresTs = strtotime($share['expires_at']);
        if ($expiresTs !== false && $expiresTs < time()) {
            $isExpired = true;
        }
    }
    $isMaxed = $share['max_downloads'] && $share['download_count'] >= $share['max_downloads'];
    return !empty($share['is_active']) && !$isExpired && !$isMaxed;
});

$isAdmin = ($user['role'] === 'admin');
renderPageStart(t('my_shares'), 'user-shares', $isAdmin);
renderHeader(t('my_shared_links'), $user);
?>

<div class="content">
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
        <div class="card-header" style="padding: 1.5rem;">
            <h2 class="card-title" style="font-weight: 700; font-size: 1.5rem; margin: 0;"><i class="fas fa-link"></i> <?php echo t('active_links'); ?> (<?php echo count($shares); ?>)</h2>
            <a href="<?php echo BASE_URL; ?>/user/files.php" class="btn btn-success" style="background: white; color: #e9b149; border: none; font-weight: 600;"><?php echo t('view_my_files'); ?></a>
        </div>
        <div class="card-body">
            <?php if (empty($shares)): ?>
                <div style="text-align: center; padding: 4rem; background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%); border-radius: 1rem; border: 2px dashed var(--border-color);">
                    <div style="font-size: 5rem; margin-bottom: 1.5rem; opacity: 0.3;"><i class="fas fa-link"></i></div>
                    <h3 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem;"><?php echo t('no_active_shares'); ?></h3>
                    <p style="color: var(--text-muted); margin-bottom: 2rem;"><?php echo t('share_files_from_list'); ?></p>
                    <a href="<?php echo BASE_URL; ?>/user/files.php" class="btn btn-primary" style="padding: 0.75rem 2rem; font-size: 1.0625rem; font-weight: 600; box-shadow: 0 4px 12px rgba(155, 89, 182, 0.3);"><?php echo t('go_to_files'); ?></a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><?php echo t('file'); ?></th>
                                <th><?php echo t('table_downloads'); ?></th>
                                <th><?php echo t('table_expires_in'); ?></th>
                                <th><?php echo t('table_created'); ?></th>
                                <th><?php echo t('table_actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shares as $share): ?>
                            <?php 
                                $shareUrl = BASE_URL . '/s/' . $share['token'];
                                // detect gallery image: image MIME, no expiry, unlimited downloads
                                $isGalleryImage = false;
                                if (!empty($share['mime_type']) && strpos($share['mime_type'], 'image/') === 0) {
                                    $noExpiry = empty($share['expires_at']);
                                    $unlimited = empty($share['max_downloads']);
                                    if ($noExpiry && $unlimited) $isGalleryImage = true;
                                }
                                $shareRawUrl = $shareUrl . '/' . rawurlencode($share['original_name']);
                            ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <span style="font-size: 1.5rem;"><i class="fas fa-file"></i></span>
                                        <div>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($share['original_name']); ?></div>
                                            <div style="font-size: 0.8125rem; color: var(--text-muted);">
                                                <?php echo number_format($share['file_size'] / 1024 / 1024, 2); ?> MB
                                                <?php if ($share['password_hash']): ?>
                                                        <span style="margin-left: 0.5rem;"><i class="fas fa-lock"></i> <?php echo t('protected'); ?></span>
                                                <?php endif; ?>
                                                <?php if ($isGalleryImage): ?>
                                                    <span style="margin-left:0.5rem; background:#f5e6b8; color:#7a5a0a; padding:2px 6px; border-radius:4px; font-weight:600; font-size:0.75rem;"><?php echo htmlspecialchars(t('gallery')) ?? 'Galería'; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--primary);">
                                        <?php echo $share['download_count']; ?>
                                            <?php if ($share['max_downloads']): ?>
                                            / <?php echo $share['max_downloads']; ?>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);"><?php echo t('unlimited_symbol'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                        $now = time();
                                        $expires = strtotime($share['expires_at']);
                                        $diff = $expires - $now;
                                        $color = 'var(--success)';
                                        if ($diff < 86400) $color = 'var(--warning)'; // Menos de 1 día
                                        if ($diff < 3600) $color = 'var(--danger)'; // Menos de 1 hora
                                        
                                        if ($diff > 86400) {
                                            $timeText = ceil($diff / 86400) . ' días';
                                        } elseif ($diff > 3600) {
                                            $timeText = ceil($diff / 3600) . ' horas';
                                        } else {
                                            $timeText = ceil($diff / 60) . ' minutos';
                                        }
                                    ?>
                                    <span style="font-weight: 600; color: <?php echo $color; ?>;">
                                        <i class="fas fa-clock"></i> <?php echo $timeText; ?>
                                    </span>
                                </td>
                                <td style="color: var(--text-muted);">
                                    <?php echo date('d/m/Y H:i', strtotime($share['created_at'])); ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                        <button type="button" class="btn btn-sm btn-primary copy-link-btn" data-url="<?php echo htmlspecialchars($isGalleryImage ? $shareRawUrl : $shareUrl); ?>" title="<?php echo t('copy'); ?>"><i class="fas fa-clipboard"></i> <?php echo t('copy'); ?></button>
                                        <a href="<?php echo $shareUrl; ?>" target="_blank" class="btn btn-sm btn-success" title="<?php echo t('open_link'); ?>"><i class="fas fa-link"></i> <?php echo t('open_link'); ?></a>
                                        <button type="button" class="btn btn-sm btn-secondary copy-raw-btn" data-url="<?php echo htmlspecialchars($shareRawUrl); ?>" title="<?php echo t('direct_link'); ?>"><i class="fas fa-external-link-alt"></i> <?php echo t('direct_link'); ?></button>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                                            <input type="hidden" name="share_id" value="<?php echo $share['id']; ?>">
                                            <input type="hidden" name="action" value="deactivate">
                                                <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm(<?php echo json_encode(t('confirm_disable_link')); ?>)" title="<?php echo t('disable'); ?>"><i class="fas fa-pause"></i></button>
                                        </form>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                                            <input type="hidden" name="share_id" value="<?php echo $share['id']; ?>">
                                            <input type="hidden" name="action" value="delete">
                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm(<?php echo json_encode(t('confirm_delete_link_permanent')); ?>)" title="<?php echo t('delete'); ?>"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const copyButtons = document.querySelectorAll('.copy-link-btn');
    
    copyButtons.forEach(button => {
        button.addEventListener('click', function() {
            const url = this.getAttribute('data-url');
            
            const textarea = document.createElement('textarea');
            textarea.value = url;
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            textarea.style.top = '0';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            
            try {
                document.execCommand('copy');
                Mimir.showAlert(<?php echo json_encode(t('copied_to_clipboard')); ?>, 'success');
            } catch (err) {
                prompt('Copia este enlace:', url);
            }
            
            document.body.removeChild(textarea);
        });
    });
    const copyRawButtons = document.querySelectorAll('.copy-raw-btn');
    copyRawButtons.forEach(button => {
        button.addEventListener('click', function() {
            const url = this.getAttribute('data-url');
            const textarea = document.createElement('textarea');
            textarea.value = url;
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            textarea.style.top = '0';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            try {
                document.execCommand('copy');
                Mimir.showAlert(<?php echo json_encode(t('copied_to_clipboard')); ?>, 'success');
            } catch (err) {
                prompt('Copia este enlace:', url);
            }
            document.body.removeChild(textarea);
        });
    });
});
</script>

<?php renderPageEnd(); ?>
