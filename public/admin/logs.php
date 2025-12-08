<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();
$logger = new Logger();

$logs = $logger->getActivityLogs([], 50, 0);

renderPageStart('Registros', 'logs', true);
renderHeader('Registros de Actividad', $user);
?>
<div class="content">
    <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
        <div class="card-header" style="background: linear-gradient(135deg, #fa709a, #fee140); color: white; padding: 1.5rem;">
            <h2 class="card-title" style="color: white; font-weight: 700; font-size: 1.5rem;"><i class="fas fa-clipboard"></i> Actividad del Sistema</h2>
        </div>
        <div class="card-body">
            <?php if (empty($logs)): ?>
                <div style="text-align: center; padding: 4rem; background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%); border-radius: 1rem; border: 2px dashed var(--border-color);">
                    <div style="font-size: 5rem; margin-bottom: 1.5rem; opacity: 0.3;"><i class="fas fa-clipboard"></i></div>
                    <h3 style="font-size: 1.5rem; font-weight: 700; color: var(--text-muted);">Sin actividad registrada</h3>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr><th>Usuario</th><th>Acción</th><th>Descripción</th><th>IP</th><th>Fecha</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($log['username'] ?? 'Sistema'); ?></td>
                            <td><code><?php echo htmlspecialchars($log['action']); ?></code></td>
                            <td><?php echo htmlspecialchars($log['description'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                            <td><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php renderPageEnd(); ?>
