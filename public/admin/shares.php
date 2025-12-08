<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Config.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/Share.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();
$shareClass = new Share();
$shares = $shareClass->getAll([], 50, 0);

renderPageStart('Comparticiones', 'shares', true);
renderHeader('Comparticiones del Sistema', $user);
?>
<div class="content">
    <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
        <div class="card-header" style="background: linear-gradient(135deg, #9b59b6, #e74c3c); color: white; padding: 1.5rem;">
            <h2 class="card-title" style="color: white; font-weight: 700; font-size: 1.5rem;"><i class="fas fa-link"></i> Todas las Comparticiones</h2>
        </div>
        <div class="card-body">
            <?php if (empty($shares)): ?>
                <div style="text-align: center; padding: 4rem; background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%); border-radius: 1rem; border: 2px dashed var(--border-color);">
                    <div style="font-size: 5rem; margin-bottom: 1.5rem; opacity: 0.3;"><i class="fas fa-link"></i></div>
                    <h3 style="font-size: 1.5rem; font-weight: 700; color: var(--text-muted);">No hay comparticiones en el sistema</h3>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr><th>Archivo</th><th>Usuario</th><th>Estado</th><th>Descargas</th><th>Creado</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shares as $share): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($share['original_name']); ?></td>
                            <td><?php echo htmlspecialchars($share['owner_username']); ?></td>
                            <td><span class="badge badge-<?php echo $share['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $share['is_active'] ? 'Activo' : 'Inactivo'; ?></span></td>
                            <td><?php echo $share['download_count']; ?></td>
                            <td><?php echo date('d/m/Y', strtotime($share['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php renderPageEnd(); ?>
