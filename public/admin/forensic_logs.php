<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/ForensicLogger.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();

$forensicLogger = new ForensicLogger();
$db = Database::getInstance()->getConnection();

// Pagination and filters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = isset($_GET['per_page']) ? max(10, min(100, intval($_GET['per_page']))) : 25;
$offset = ($page - 1) * $perPage;

$filterDays = isset($_GET['days']) ? intval($_GET['days']) : 7;
$filterIP = $_GET['ip'] ?? '';
$filterBot = $_GET['bot'] ?? '';

// Build query
$where = ["download_started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"];
$params = [$filterDays];

if ($filterIP) {
    $where[] = "ip_address LIKE ?";
    $params[] = "%$filterIP%";
}

if ($filterBot === 'only') {
    $where[] = "is_bot = 1";
} elseif ($filterBot === 'exclude') {
    $where[] = "is_bot = 0";
}

$whereClause = implode(' AND ', $where);

// Get download logs
$stmt = $db->prepare("
    SELECT 
        dl.*,
        f.original_name,
        f.mime_type,
        u.username,
        s.share_token
    FROM download_log dl
    LEFT JOIN files f ON dl.file_id = f.id
    LEFT JOIN users u ON dl.user_id = u.id
    LEFT JOIN shares s ON dl.share_id = s.id
    WHERE $whereClause
    ORDER BY dl.download_started_at DESC
    LIMIT ? OFFSET ?
");
$params[] = $perPage;
$params[] = $offset;
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Count total
$stmt = $db->prepare("SELECT COUNT(*) FROM download_log WHERE $whereClause");
$stmt->execute(array_slice($params, 0, -2));
$totalLogs = $stmt->fetchColumn();
$totalPages = ceil($totalLogs / $perPage);

// Statistics
$stats = $forensicLogger->getDownloadStats(null, $filterDays);

// Top IPs
$stmt = $db->prepare("
    SELECT 
        ip_address,
        COUNT(*) as download_count,
        COUNT(DISTINCT file_id) as unique_files,
        SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) as bot_count
    FROM download_log
    WHERE download_started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY ip_address
    ORDER BY download_count DESC
    LIMIT 10
");
$stmt->execute([$filterDays]);
$topIPs = $stmt->fetchAll();

// Browser distribution
$stmt = $db->prepare("
    SELECT 
        browser,
        COUNT(*) as count
    FROM download_log
    WHERE download_started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        AND browser IS NOT NULL
        AND is_bot = 0
    GROUP BY browser
    ORDER BY count DESC
    LIMIT 10
");
$stmt->execute([$filterDays]);
$browsers = $stmt->fetchAll();

// Device types
$stmt = $db->prepare("
    SELECT 
        device_type,
        COUNT(*) as count
    FROM download_log
    WHERE download_started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY device_type
    ORDER BY count DESC
");
$stmt->execute([$filterDays]);
$deviceTypes = $stmt->fetchAll();

renderPageStart('Logs Forenses', 'forensic-logs', true);
renderHeader('Análisis Forense de Descargas', $user);
?>

<style>
.forensic-stat-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    padding: 1.5rem;
    text-align: center;
}
.forensic-stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--primary);
    margin: 0.5rem 0;
}
.forensic-stat-label {
    color: var(--text-muted);
    font-size: 0.875rem;
}
.log-table {
    font-size: 0.875rem;
}
.log-table td {
    padding: 0.5rem;
    border-bottom: 1px solid var(--border-color);
}
.badge-bot {
    background: #E24A90;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.75rem;
}
.badge-device {
    background: var(--primary);
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.75rem;
}
.ip-link {
    font-family: monospace;
    color: var(--primary);
    text-decoration: none;
}
.ip-link:hover {
    text-decoration: underline;
}
</style>

<div class="content">
    <!-- Statistics Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
        <div class="forensic-stat-card">
            <div class="forensic-stat-label">Total Descargas</div>
            <div class="forensic-stat-value"><?php echo number_format($stats['total_downloads'] ?? 0); ?></div>
        </div>
        <div class="forensic-stat-card">
            <div class="forensic-stat-label">IPs Únicas</div>
            <div class="forensic-stat-value"><?php echo number_format($stats['unique_ips'] ?? 0); ?></div>
        </div>
        <div class="forensic-stat-card">
            <div class="forensic-stat-label">Descargas por Bots</div>
            <div class="forensic-stat-value"><?php echo number_format($stats['bot_downloads'] ?? 0); ?></div>
        </div>
        <div class="forensic-stat-card">
            <div class="forensic-stat-label">Móvil</div>
            <div class="forensic-stat-value"><?php echo number_format($stats['mobile_downloads'] ?? 0); ?></div>
        </div>
        <div class="forensic-stat-card">
            <div class="forensic-stat-label">Desktop</div>
            <div class="forensic-stat-value"><?php echo number_format($stats['desktop_downloads'] ?? 0); ?></div>
        </div>
        <div class="forensic-stat-card">
            <div class="forensic-stat-label">Exitosas</div>
            <div class="forensic-stat-value" style="color: var(--success);"><?php echo number_format($stats['successful_downloads'] ?? 0); ?></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card" style="margin-bottom: 2rem;">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-filter"></i> Filtros</h3>
        </div>
        <div class="card-body">
            <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div>
                    <label>Período</label>
                    <select name="days" class="form-control">
                        <option value="1" <?php echo $filterDays === 1 ? 'selected' : ''; ?>>Último día</option>
                        <option value="7" <?php echo $filterDays === 7 ? 'selected' : ''; ?>>Últimos 7 días</option>
                        <option value="30" <?php echo $filterDays === 30 ? 'selected' : ''; ?>>Últimos 30 días</option>
                        <option value="90" <?php echo $filterDays === 90 ? 'selected' : ''; ?>>Últimos 90 días</option>
                    </select>
                </div>
                <div>
                    <label>IP Address</label>
                    <input type="text" name="ip" value="<?php echo htmlspecialchars($filterIP); ?>" class="form-control" placeholder="ej: 192.168">
                </div>
                <div>
                    <label>Bots</label>
                    <select name="bot" class="form-control">
                        <option value="">Todos</option>
                        <option value="only" <?php echo $filterBot === 'only' ? 'selected' : ''; ?>>Solo Bots</option>
                        <option value="exclude" <?php echo $filterBot === 'exclude' ? 'selected' : ''; ?>>Excluir Bots</option>
                    </select>
                </div>
                <div style="display: flex; align-items: flex-end; gap: 0.5rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">Aplicar Filtros</button>
                    <div>
                        <label>Por página</label>
                        <select name="per_page" class="form-control" onchange="this.form.submit()">
                            <option value="10" <?php echo $perPage === 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $perPage === 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $perPage === 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $perPage === 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Analytics Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <!-- Top IPs -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-network-wired"></i> Top IPs</h3>
            </div>
            <div class="card-body">
                <?php if (empty($topIPs)): ?>
                    <p style="text-align: center; color: var(--text-muted); padding: 2rem;">
                        No hay datos de descargas aún.<br>
                        <small>Puedes ejecutar: <code>php simulate_forensic_downloads.php</code></small>
                    </p>
                <?php else: ?>
                <table class="log-table" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>IP Address</th>
                            <th>Descargas</th>
                            <th>Archivos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topIPs as $ip): ?>
                        <tr>
                            <td>
                                <a href="?ip=<?php echo urlencode($ip['ip_address']); ?>&days=<?php echo $filterDays; ?>" class="ip-link">
                                    <?php echo htmlspecialchars($ip['ip_address']); ?>
                                </a>
                                <?php if ($ip['bot_count'] > 0): ?>
                                    <span class="badge-bot">🤖 Bot</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $ip['download_count']; ?></td>
                            <td><?php echo $ip['unique_files']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Browsers -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-browser"></i> Navegadores</h3>
            </div>
            <div class="card-body">
                <?php if (empty($browsers)): ?>
                    <p style="text-align: center; color: var(--text-muted); padding: 2rem;">Sin datos</p>
                <?php else: ?>
                    <?php foreach ($browsers as $browser): ?>
                    <div style="margin-bottom: 1rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                            <span><?php echo htmlspecialchars($browser['browser']); ?></span>
                            <span><strong><?php echo $browser['count']; ?></strong></span>
                        </div>
                        <div style="background: var(--bg-main); height: 0.5rem; border-radius: 0.25rem; overflow: hidden;">
                            <div style="width: <?php echo ($browser['count'] / $stats['total_downloads']) * 100; ?>%; height: 100%; background: var(--primary);"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Device Types -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-devices"></i> Tipos de Dispositivo</h3>
            </div>
            <div class="card-body">
                <?php if (empty($deviceTypes)): ?>
                    <p style="text-align: center; color: var(--text-muted); padding: 2rem;">Sin datos</p>
                <?php else: ?>
                    <?php foreach ($deviceTypes as $device): ?>
                    <div style="display: flex; justify-content: space-between; padding: 0.75rem; background: var(--bg-main); margin-bottom: 0.5rem; border-radius: 0.5rem;">
                        <span>
                            <?php 
                            $icons = ['desktop' => '🖥️', 'mobile' => '��', 'tablet' => '📲', 'bot' => '🤖', 'unknown' => '❓'];
                            echo $icons[$device['device_type']] ?? '❓';
                            ?>
                            <?php echo ucfirst($device['device_type']); ?>
                        </span>
                        <strong><?php echo $device['count']; ?></strong>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Download Logs Table -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-list"></i> Registro de Descargas<?php echo $totalLogs > 0 ? " ($totalLogs)" : ''; ?></h3>
        </div>
        <div class="card-body">
            <?php if (empty($logs)): ?>
                <div style="text-align: center; padding: 3rem;">
                    <div style="font-size: 4rem; margin-bottom: 1rem;">📊</div>
                    <h3>No hay registros de descargas</h3>
                    <p style="color: var(--text-muted); margin-top: 1rem;">
                        Los registros forenses aparecerán aquí cuando se realicen descargas.<br>
                        Para generar datos de prueba, ejecuta: <code>php simulate_forensic_downloads.php</code>
                    </p>
                </div>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="log-table" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Fecha/Hora</th>
                            <th>Archivo</th>
                            <th>IP Address</th>
                            <th>Dispositivo</th>
                            <th>Navegador</th>
                            <th>OS</th>
                            <th>Usuario</th>
                            <th>Duración</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td style="white-space: nowrap;">
                                <?php echo date('d/m/Y H:i:s', strtotime($log['download_started_at'])); ?>
                            </td>
                            <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                                <?php echo htmlspecialchars($log['original_name']); ?>
                                <?php if ($log['share_token']): ?>
                                    <br><small style="color: var(--text-muted);">🔗 Compartido</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code class="ip-link"><?php echo htmlspecialchars($log['ip_address']); ?></code>
                            </td>
                            <td>
                                <?php if ($log['is_bot']): ?>
                                    <span class="badge-bot"><?php echo htmlspecialchars($log['bot_name']); ?></span>
                                <?php else: ?>
                                    <span class="badge-device"><?php echo htmlspecialchars($log['device_type']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($log['browser'] . ($log['browser_version'] ? ' ' . $log['browser_version'] : '')); ?></td>
                            <td><?php echo htmlspecialchars($log['os'] . ($log['os_version'] ? ' ' . $log['os_version'] : '')); ?></td>
                            <td><?php echo $log['username'] ? htmlspecialchars($log['username']) : '<em>Anónimo</em>'; ?></td>
                            <td><?php echo $log['download_duration'] ? $log['download_duration'] . 's' : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 1rem;">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&days=<?php echo $filterDays; ?>&ip=<?php echo urlencode($filterIP); ?>&bot=<?php echo $filterBot; ?>" class="btn btn-secondary">← Anterior</a>
                <?php endif; ?>
                
                <span style="padding: 0.5rem 1rem;">Página <?php echo $page; ?> de <?php echo $totalPages; ?></span>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&days=<?php echo $filterDays; ?>&ip=<?php echo urlencode($filterIP); ?>&bot=<?php echo $filterBot; ?>" class="btn btn-secondary">Siguiente →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php renderPageEnd(); ?>
