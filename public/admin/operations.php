<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/File.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();

$fileClass = new File();
$db = Database::getInstance()->getConnection();

// Read last lines of smtp log (best-effort)
$smtpTail = '';
$smtpLog = __DIR__ . '/../../storage/logs/smtp_debug.log';
if (file_exists($smtpLog) && is_readable($smtpLog)) {
    $lines = 200;
    $cmd = "tail -n " . intval($lines) . " " . escapeshellarg($smtpLog) . " 2>/dev/null";
    $smtpTail = trim(shell_exec($cmd));
}

// Get recent failed login IPs
$failedLogins = [];
try {
    $stmt = $db->prepare("SELECT ip_address, COUNT(*) as attempts FROM security_events WHERE event_type = 'failed_login' AND created_at >= (NOW() - INTERVAL 24 HOUR) GROUP BY ip_address ORDER BY attempts DESC LIMIT 20");
    $stmt->execute();
    $failedLogins = $stmt->fetchAll();
} catch (Exception $e) {
    $failedLogins = [];
}

// Get recent notification jobs
$notifJobs = [];
try {
    $stmt = $db->prepare("SELECT id, job_type, status, attempts, last_error, created_at, updated_at FROM notification_jobs ORDER BY created_at DESC LIMIT 200");
    $stmt->execute();
    $notifJobs = $stmt->fetchAll();
} catch (Exception $e) {
    $notifJobs = [];
}

// Orphan files count/link
$orphanCount = 0;
try {
    $orphanCount = (int)$fileClass->countOrphans();
} catch (Exception $e) {
    $orphanCount = 0;
}

renderPageStart('Operaciones', 'operations', true);
renderHeader('Operaciones', $user, $auth);
?>
<div class="content">
    <h2>Operaciones</h2>
    <div style="display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1rem;">
        <a href="<?php echo BASE_URL; ?>/admin/orphan_files.php" class="btn btn-outline">Ver Archivos Huérfanos (<?php echo $orphanCount; ?>)</a>
        <a href="<?php echo BASE_URL; ?>/admin/logs.php" class="btn btn-outline">Ver Registros</a>
        <a href="?view=smtp" class="btn btn-outline">Ver SMTP Log</a>
        <a href="?view=notifications" class="btn btn-outline">Ver Notification Jobs</a>
        <a href="?view=failed_logins" class="btn btn-outline">Ver Fallos de Login (24h)</a>
    </div>

    <?php if (isset($_GET['view']) && $_GET['view'] === 'smtp'): ?>
        <div class="card">
            <div class="card-header"><h3>SMTP Debug Log (últimas líneas)</h3></div>
            <div class="card-body"><pre style="white-space:pre-wrap; max-height:480px; overflow:auto; background:#0b0b0b; color:#e6e6e6; padding:1rem;"><?php echo htmlspecialchars($smtpTail ?: "(no disponible)"); ?></pre></div>
        </div>
    <?php elseif (isset($_GET['view']) && $_GET['view'] === 'notifications'): ?>
        <div class="card">
            <div class="card-header"><h3>Notification Jobs (últimos 200)</h3></div>
            <div class="card-body">
                <?php if (empty($notifJobs)): ?>
                    <p class="text-muted">No hay jobs o la tabla no existe.</p>
                <?php else: ?>
                    <div style="max-height:520px; overflow:auto;"><table class="table table-sm"><thead><tr><th>ID</th><th>Tipo</th><th>Estado</th><th>Intentos</th><th>Último Error</th><th>Creado</th><th>Actualizado</th></tr></thead><tbody>
                        <?php foreach ($notifJobs as $j): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($j['id']); ?></td>
                            <td><?php echo htmlspecialchars($j['job_type']); ?></td>
                            <td><?php echo htmlspecialchars($j['status']); ?></td>
                            <td><?php echo htmlspecialchars($j['attempts']); ?></td>
                            <td style="max-width:360px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo htmlspecialchars($j['last_error']); ?></td>
                            <td><?php echo htmlspecialchars($j['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($j['updated_at']); ?></td>
                        </tr>
                        <?php endforeach; ?></tbody></table></div>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif (isset($_GET['view']) && $_GET['view'] === 'failed_logins'): ?>
        <div class="card">
            <div class="card-header"><h3>Intentos de Login Fallidos (24h)</h3></div>
            <div class="card-body">
                <?php if (empty($failedLogins)): ?>
                    <p class="text-muted">No hay registros.</p>
                <?php else: ?>
                    <table class="table table-sm"><thead><tr><th>IP</th><th>Intentos</th></tr></thead><tbody>
                    <?php foreach ($failedLogins as $f): ?>
                        <tr><td><?php echo htmlspecialchars($f['ip_address']); ?></td><td><?php echo htmlspecialchars($f['attempts']); ?></td></tr>
                    <?php endforeach; ?></tbody></table>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-header"><h3>Resumen rápido</h3></div>
            <div class="card-body">
                <p>Mira los diferentes logs y colas con los botones arriba. Selecciona "Ver SMTP Log" para inspeccionar errores de autenticación, "Ver Notification Jobs" para revisar jobs fallidos, y "Ver Fallos de Login" para ver IPs sospechosas.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php renderPageEnd(); ?>
