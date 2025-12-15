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
$actions = $logger->getDistinctActions();

// Get filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$action = $_GET['action'] ?? '';

$filters = [
    'date_from' => $dateFrom . ' 00:00:00',
    'date_to' => $dateTo . ' 23:59:59'
];

if ($action) {
    $filters['action'] = $action;
}

$logs = $logger->getActivityLogs($filters, 100, 0);

renderPageStart('Registros', 'logs', true);
renderHeader('Registros de Actividad', $user);
?>
<div class="content">
    <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
        <div class="card-header" style="padding: 1.5rem;">
            <h2 class="card-title" style="font-weight: 700; font-size: 1.5rem;"><i class="fas fa-clipboard"></i> Actividad del Sistema</h2>
        </div>
        <div class="card-body">
            <!-- Filters -->
            <form method="GET" class="mb-3" style="background: var(--bg-secondary); padding: 1rem; border-radius: 0.5rem;">
                <div class="row">
                    <div class="col-md-3">
                        <label>Desde:</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>">
                    </div>
                    <div class="col-md-3">
                        <label>Hasta:</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>">
                    </div>
                    <div class="col-md-3">
                        <label>Acción:</label>
                        <select name="action" class="form-control">
                            <option value="">Todas</option>
                            <?php foreach ($actions as $act): ?>
                                <option value="<?php echo htmlspecialchars($act ?? ''); ?>" <?php echo $action === $act ? 'selected' : ''; ?>><?php echo htmlspecialchars($act ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small style="display:block; margin-top:0.25rem; color:var(--text-muted);">Selecciona una acción para filtrar los registros.</small>
                    </div>
                    <div class="col-md-3" style="display: flex; align-items: flex-end; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-filter"></i> Filtrar
                        </button>
                        <a href="<?php echo BASE_URL; ?>/admin/export_activity_log.php?date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&action=<?php echo urlencode($action); ?>" 
                           class="btn btn-success" title="Exportar a Excel" style="padding:0.55rem 1.25rem; font-size:1rem; margin-left:0.6rem; display:inline-flex; align-items:center; gap:0.5rem; min-width:120px; min-height:42px;">
                            <i class="fas fa-file-excel fa-lg" aria-hidden="true"></i>
                            <span style="font-weight:600;">Exportar</span>
                        </a>
                        <!-- Compact view removed: always show full logs table for admins -->
                    </div>
                </div>
            </form>
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
                            <td><code><?php echo htmlspecialchars($log['action'] ?? ''); ?></code></td>
                            <td><?php echo htmlspecialchars($log['description'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></td>
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
