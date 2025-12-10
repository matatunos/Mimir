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
            <h2 class="card-title" style="color: white; font-weight: 700; font-size: 1.5rem;"><i class="fas fa-clipboard"></i> Actividad del Sistema</h2>
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
                            <optgroup label="Autenticación">
                                <option value="login" <?php echo $action === 'login' ? 'selected' : ''; ?>>Login</option>
                                <option value="logout" <?php echo $action === 'logout' ? 'selected' : ''; ?>>Logout</option>
                            </optgroup>
                            <optgroup label="Archivos">
                                <option value="file_uploaded" <?php echo $action === 'file_uploaded' ? 'selected' : ''; ?>>Archivo Subido</option>
                                <option value="file_downloaded" <?php echo $action === 'file_downloaded' ? 'selected' : ''; ?>>Archivo Descargado</option>
                            </optgroup>
                            <optgroup label="Comparticiones">
                                <option value="share_created" <?php echo $action === 'share_created' ? 'selected' : ''; ?>>Share Creado</option>
                                <option value="share_downloaded" <?php echo $action === 'share_downloaded' ? 'selected' : ''; ?>>Share Descargado</option>
                            </optgroup>
                            <optgroup label="Active Directory / LDAP">
                                <option value="role_granted_via_ad" <?php echo $action === 'role_granted_via_ad' ? 'selected' : ''; ?>>Rol concedido vía AD</option>
                                <option value="role_revoked_via_ad" <?php echo $action === 'role_revoked_via_ad' ? 'selected' : ''; ?>>Rol revocado vía AD</option>
                                <option value="ldap_bind_failed" <?php echo $action === 'ldap_bind_failed' ? 'selected' : ''; ?>>LDAP bind fallido</option>
                                <option value="ldap_starttls_failed" <?php echo $action === 'ldap_starttls_failed' ? 'selected' : ''; ?>>LDAP STARTTLS fallido</option>
                            </optgroup>
                        </select>
                        <small style="display:block; margin-top:0.25rem; color:var(--text-muted);">Selecciona una acción para filtrar los registros.</small>
                    </div>
                    <div class="col-md-3" style="display: flex; align-items: flex-end; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-filter"></i> Filtrar
                        </button>
                        <a href="<?php echo BASE_URL; ?>/admin/export_activity_log.php?date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&action=<?php echo urlencode($action); ?>" 
                           class="btn btn-success" title="Exportar a Excel">
                            <i class="fas fa-file-excel"></i>
                        </a>
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
