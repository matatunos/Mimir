<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/File.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();

$logger = new Logger();

// Handle admin POST actions (CSRF protected)
$actionResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!$auth->validateCsrfToken($token)) {
        $actionResult = ['type' => 'error', 'msg' => 'CSRF token inválido'];
    } else {
        $act = $_POST['action'];
        try {
            if ($act === 'retry_failed') {
                $stmt = $db->prepare("UPDATE notification_jobs SET status = 'pending', attempts = 0, last_error = NULL WHERE status = 'failed'");
                $stmt->execute();
                $count = $stmt->rowCount();
                $logger->log($user['id'], 'admin_retry_failed_notifications', 'operations', null, "Requeued $count failed notification jobs");
                $actionResult = ['type' => 'success', 'msg' => "Reencolados $count jobs fallidos"];
            } elseif ($act === 'clear_failed') {
                $stmt = $db->prepare("DELETE FROM notification_jobs WHERE status = 'failed'");
                $stmt->execute();
                $count = $stmt->rowCount();
                $logger->log($user['id'], 'admin_clear_failed_notifications', 'operations', null, "Deleted $count failed notification jobs");
                $actionResult = ['type' => 'success', 'msg' => "Eliminados $count jobs fallidos"];
            } elseif ($act === 'restart_worker') {
                // Best-effort restart via shell; requires sudoers or suitable permissions
                $cmd = "sudo pkill -f notification_worker.php || true; sudo -u www-data nohup php /opt/Mimir/tools/notification_worker.php > /var/log/mimir_notification_worker.log 2>&1 & echo restarted";
                $out = shell_exec($cmd . " 2>&1");
                $logger->log($user['id'], 'admin_restart_worker', 'operations', null, "Restart worker: " . trim($out));
                $actionResult = ['type' => 'success', 'msg' => 'Intentado reiniciar worker: ' . trim($out)];
            } else {
                $actionResult = ['type' => 'error', 'msg' => 'Acción desconocida'];
            }
        } catch (Exception $e) {
            $actionResult = ['type' => 'error', 'msg' => 'Error al ejecutar la acción: ' . $e->getMessage()];
        }
        // Refresh some metrics after action
        try { $queuedNotifications = (int)$db->query("SELECT COUNT(*) FROM notification_jobs WHERE status = 'pending'")->fetchColumn(); } catch (Exception $e) {}
        try { $failedNotifications = (int)$db->query("SELECT COUNT(*) FROM notification_jobs WHERE status = 'failed'")->fetchColumn(); } catch (Exception $e) {}
    }
}

$fileClass = new File();
$db = Database::getInstance()->getConnection();

// Operational metrics (same as dashboard)
$queuedNotifications = 0;
$failedNotifications = 0;
$processingNotifications = 0;
$smtpFailures24 = 0;
$failedLogins24 = 0;
$topFailedIpsSimple = [];
$workerCount = 0;
try {
    $queuedNotifications = (int)$db->query("SELECT COUNT(*) FROM notification_jobs WHERE status = 'pending'")->fetchColumn();
    $failedNotifications = (int)$db->query("SELECT COUNT(*) FROM notification_jobs WHERE status = 'failed'")->fetchColumn();
    $processingNotifications = (int)$db->query("SELECT COUNT(*) FROM notification_jobs WHERE status = 'processing'")->fetchColumn();
} catch (Exception $e) {
    // ignore if table missing
}

// SMTP failures (parse last ~1000 lines, best-effort)
$smtpLogPath = __DIR__ . '/../../storage/logs/smtp_debug.log';
if (file_exists($smtpLogPath) && is_readable($smtpLogPath)) {
    $out = trim(shell_exec("tail -n 1000 " . escapeshellarg($smtpLogPath) . " 2>/dev/null"));
    if ($out !== '') {
        $lines = explode("\n", $out);
        foreach ($lines as $line) {
            if (strpos($line, '535') !== false) {
                if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $line, $m)) {
                    $ts = strtotime($m[1]);
                    if ($ts !== false && $ts >= time() - 86400) $smtpFailures24++;
                } else {
                    $smtpFailures24++;
                }
            }
        }
    }
}

try {
    $failedLogins24 = (int)$db->query("SELECT COUNT(*) FROM security_events WHERE event_type = 'failed_login' AND created_at >= (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
    $stmt = $db->prepare("SELECT ip_address, COUNT(*) as attempts FROM security_events WHERE event_type = 'failed_login' AND created_at >= (NOW() - INTERVAL 24 HOUR) GROUP BY ip_address ORDER BY attempts DESC LIMIT 5");
    $stmt->execute();
    $topFailedIpsSimple = $stmt->fetchAll();
} catch (Exception $e) {
    $failedLogins24 = 0;
}

// worker count via pgrep
$wc = trim(shell_exec("pgrep -af notification_worker.php | wc -l 2>/dev/null"));
if (is_numeric($wc)) $workerCount = (int)$wc;

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
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; margin-bottom:1rem;">
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Cola de Notificaciones</div>
                <div class="admin-stat-value"><?php echo number_format($queuedNotifications); ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-hourglass-start"></i> En cola</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-bell"></i></div>
        </div>

        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Notificaciones Fallidas (24h)</div>
                <div class="admin-stat-value"><?php echo number_format($failedNotifications); ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-exclamation-triangle"></i> Intentos fallidos</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-bell-slash"></i></div>
        </div>

        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Errores SMTP (24h)</div>
                <div class="admin-stat-value"><?php echo number_format($smtpFailures24); ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-envelope-open-text"></i> Autenticación</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-envelope"></i></div>
        </div>

        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Intentos Login Fallidos (24h)</div>
                <div class="admin-stat-value"><?php echo number_format($failedLogins24); ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-user-lock"></i> Seguridad</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-user-shield"></i></div>
        </div>

        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Archivos Huérfanos</div>
                <div class="admin-stat-value"><?php echo number_format($orphanCount); ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-question-circle"></i> Revisar</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-file-alt"></i></div>
        </div>

        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Workers</div>
                <div class="admin-stat-value"><?php echo number_format($workerCount); ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-play-circle"></i> notification_worker</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-server"></i></div>
        </div>
    </div>

    <div style="display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; align-items:center;">
        <a href="<?php echo BASE_URL; ?>/admin/orphan_files.php" class="btn btn-outline">Ver Archivos Huérfanos (<?php echo $orphanCount; ?>)</a>
        <a href="<?php echo BASE_URL; ?>/admin/logs.php" class="btn btn-outline">Ver Registros</a>
        <a href="?view=smtp" class="btn btn-outline">Ver SMTP Log</a>
        <a href="?view=notifications" class="btn btn-outline">Ver Notification Jobs</a>
        <a href="?view=failed_logins" class="btn btn-outline">Ver Fallos de Login (24h)</a>

        <form method="POST" style="display:inline-block; margin-left:1rem;" onsubmit="return confirm('¿Confirmas reintentar todos los jobs fallidos?');">
            <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="retry_failed">
            <button class="btn btn-primary" type="submit">Reintentar jobs fallidos</button>
        </form>

        <form method="POST" style="display:inline-block;" onsubmit="return confirm('¿Confirmas eliminar todos los jobs fallidos? Esta acción es irreversible.');">
            <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="clear_failed">
            <button class="btn btn-danger" type="submit">Eliminar jobs fallidos</button>
        </form>

        <form method="POST" style="display:inline-block;" onsubmit="return confirm('¿Confirmas reiniciar el worker de notificaciones?');">
            <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="restart_worker">
            <button class="btn btn-accent" type="submit">Reiniciar worker</button>
        </form>
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
