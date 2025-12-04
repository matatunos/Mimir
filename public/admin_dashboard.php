<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireAdmin();

$db = Database::getInstance()->getConnection();

// Get comprehensive statistics
$stats = [];

// Total users
$stmt = $db->query("SELECT COUNT(*) as total FROM users");
$stats['total_users'] = $stmt->fetch()['total'];

// Active users (logged in last 30 days)
$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$stats['active_users_30d'] = $stmt->fetch()['total'];

// Total files
$stmt = $db->query("SELECT COUNT(*) as total FROM files");
$stats['total_files'] = $stmt->fetch()['total'];

// Total storage used
$stmt = $db->query("SELECT COALESCE(SUM(file_size), 0) as total FROM files");
$stats['total_storage'] = $stmt->fetch()['total'];

// Active shares
$stmt = $db->query("SELECT COUNT(*) as total FROM public_shares WHERE is_active = 1");
$stats['active_shares'] = $stmt->fetch()['total'];

// Files uploaded today
$stmt = $db->query("SELECT COUNT(*) as total FROM files WHERE DATE(created_at) = CURDATE()");
$stats['files_today'] = $stmt->fetch()['total'];

// Storage quota utilization
$stmt = $db->query("SELECT SUM(storage_quota) as total_quota, SUM(storage_used) as total_used FROM users");
$quotaData = $stmt->fetch();
$stats['total_quota'] = $quotaData['total_quota'];
$stats['total_used'] = $quotaData['total_used'];
$stats['quota_percentage'] = $stats['total_quota'] > 0 ? round(($stats['total_used'] / $stats['total_quota']) * 100, 1) : 0;

// Get activity for last 7 days (for chart)
$stmt = $db->query("
    SELECT DATE(created_at) as date, COUNT(*) as count 
    FROM files 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$activityData = $stmt->fetchAll();

// Get recent audit logs
$recentLogs = AuditLog::getLogs([], 10, 0);

// Top 10 users by storage
$stmt = $db->query("
    SELECT u.id, u.username, u.role, u.storage_used, u.storage_quota
    FROM users u
    ORDER BY u.storage_used DESC
    LIMIT 10
");
$topStorageUsers = $stmt->fetchAll();

// Top 10 users by file count
$stmt = $db->query("
    SELECT u.id, u.username, u.role, COUNT(f.id) as file_count
    FROM users u
    LEFT JOIN files f ON u.id = f.user_id
    GROUP BY u.id
    ORDER BY file_count DESC
    LIMIT 10
");
$topFileUsers = $stmt->fetchAll();

// Inactive users (no login in last 30 days)
$stmt = $db->query("
    SELECT u.id, u.username, u.email, u.role, u.last_login, u.created_at
    FROM users u
    WHERE (u.last_login IS NULL OR u.last_login < DATE_SUB(NOW(), INTERVAL 30 DAY))
    AND u.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY u.last_login ASC
    LIMIT 20
");
$inactiveUsers30d = $stmt->fetchAll();

// Inactive users (no login in last year)
$stmt = $db->query("
    SELECT COUNT(*) as total
    FROM users u
    WHERE (u.last_login IS NULL OR u.last_login < DATE_SUB(NOW(), INTERVAL 1 YEAR))
    AND u.created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)
");
$inactiveUsers1y = $stmt->fetch()['total'];

// Most active users (by recent activity)
$stmt = $db->query("
    SELECT u.id, u.username, u.role, COUNT(a.id) as action_count, MAX(a.created_at) as last_action
    FROM users u
    LEFT JOIN audit_logs a ON u.id = a.user_id AND a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY u.id
    ORDER BY action_count DESC
    LIMIT 10
");
$mostActiveUsers = $stmt->fetchAll();

$siteName = SystemConfig::get('site_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración - <?php echo escapeHtml($siteName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #1e293b;
        }
        
        .navbar {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1rem 0;
        }
        
        .navbar-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .navbar-brand {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .navbar-menu {
            display: flex;
            gap: 1rem;
        }
        
        .navbar-menu a {
            color: #64748b;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .navbar-menu a:hover,
        .navbar-menu a.active {
            background: #f1f5f9;
            color: #667eea;
        }
        
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            font-size: 2rem;
            color: #1e293b;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .page-header p {
            color: #64748b;
            font-size: 1rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
        }
        
        .stat-card.warning {
            border-left-color: #f59e0b;
        }
        
        .stat-card.success {
            border-left-color: #10b981;
        }
        
        .stat-card.info {
            border-left-color: #3b82f6;
        }
        
        .stat-card h3 {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .stat-subtitle {
            font-size: 0.875rem;
            color: #94a3b8;
        }
        
        .alert-box {
            background: #fef3c7;
            border: 2px solid #fbbf24;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: #92400e;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .card h3 {
            font-size: 1.25rem;
            color: #1e293b;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .chart-wrapper {
            height: 300px;
            position: relative;
        }
        
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s;
        }
        
        .activity-item:hover {
            background: #f8fafc;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            font-size: 1.5rem;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: #f1f5f9;
        }
        
        .activity-details {
            flex: 1;
        }
        
        .activity-user {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        
        .activity-action {
            color: #64748b;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }
        
        .activity-time {
            color: #94a3b8;
            font-size: 0.75rem;
        }
        
        .top-users-list {
            list-style: none;
        }
        
        .top-user-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
            align-items: center;
        }
        
        .top-user-item:last-child {
            border-bottom: none;
        }
        
        .rank-badge {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.125rem;
            background: #f1f5f9;
            color: #64748b;
        }
        
        .rank-badge.rank-1 {
            background: linear-gradient(135deg, #ffd700 0%, #ffed4e 100%);
            color: #92400e;
        }
        
        .rank-badge.rank-2 {
            background: linear-gradient(135deg, #c0c0c0 0%, #e0e0e0 100%);
            color: #475569;
        }
        
        .rank-badge.rank-3 {
            background: linear-gradient(135deg, #cd7f32 0%, #e6a164 100%);
            color: #fff;
        }
        
        .top-user-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .top-user-name {
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .top-user-value {
            font-size: 0.875rem;
            color: #64748b;
        }
        
        .progress-bar {
            height: 6px;
            background: #f1f5f9;
            border-radius: 999px;
            overflow: hidden;
            margin-top: 0.25rem;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transition: width 0.3s;
        }
        
        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.625rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .badge-admin {
            background: #ede9fe;
            color: #6d28d9;
        }
        
        .badge-user {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table thead {
            background: #f8fafc;
        }
        
        .data-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.875rem;
        }
        
        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.875rem;
        }
        
        .data-table tbody tr {
            transition: background 0.2s;
        }
        
        .data-table tbody tr:hover {
            background: #f8fafc;
        }
    </style>
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
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="admin_dashboard.php" class="active">
                    <i class="fas fa-chart-line"></i> Panel Admin
                </a>
                <a href="admin_users.php">
                    <i class="fas fa-users"></i> Usuarios
                </a>
                <a href="admin_files.php">
                    <i class="fas fa-file-alt"></i> Archivos
                </a>
                <a href="admin_config.php">
                    <i class="fas fa-cog"></i> Configuración
                </a>
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                </a>
            </div>
        </div>
    </div>
    
    <div class="container">
        <div class="page-header">
            <h1>
                <i class="fas fa-chart-pie"></i>
                Panel de Administración
            </h1>
            <p>Vista general de la actividad y estadísticas del sistema</p>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card info">
                <h3>Total Usuarios</h3>
                <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                <div class="stat-subtitle"><?php echo $stats['active_users_30d']; ?> activos (30d)</div>
            </div>
            
            <div class="stat-card success">
                <h3>Total Archivos</h3>
                <div class="stat-value"><?php echo number_format($stats['total_files']); ?></div>
                <div class="stat-subtitle"><?php echo $stats['files_today']; ?> subidos hoy</div>
            </div>
            
            <div class="stat-card <?php echo $stats['quota_percentage'] > 80 ? 'warning' : ''; ?>">
                <h3>Almacenamiento</h3>
                <div class="stat-value"><?php echo formatBytes($stats['total_storage']); ?></div>
                <div class="stat-subtitle"><?php echo $stats['quota_percentage']; ?>% de la cuota</div>
            </div>
            
            <div class="stat-card">
                <h3>Enlaces Compartidos</h3>
                <div class="stat-value"><?php echo number_format($stats['active_shares']); ?></div>
                <div class="stat-subtitle">Enlaces activos</div>
            </div>
        </div>
        
        <?php if (count($inactiveUsers30d) > 0): ?>
        <div class="alert-box">
            <i class="fas fa-exclamation-triangle" style="font-size: 1.5rem;"></i>
            <div>
                <strong>Usuarios Inactivos:</strong> 
                <?php echo count($inactiveUsers30d); ?> usuarios sin login en 30+ días
                <?php if ($inactiveUsers1y > 0): ?>
                    (<?php echo $inactiveUsers1y; ?> en 1+ año)
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="grid-2">
            <div class="card">
                <h3>📈 Actividad de Subidas (Últimos 7 Días)</h3>
                <div class="chart-wrapper">
                    <canvas id="activityChart"></canvas>
                </div>
            </div>
            
            <div class="card">
                <h3>🔔 Actividad Reciente</h3>
                <div class="activity-list">
                    <?php foreach ($recentLogs as $log): ?>
                    <div class="activity-item">
                        <div class="activity-icon">
                            <?php 
                            if (strpos($log['action'], 'login') !== false) echo '🔐';
                            elseif (strpos($log['action'], 'upload') !== false) echo '📤';
                            elseif (strpos($log['action'], 'delete') !== false) echo '🗑️';
                            elseif (strpos($log['action'], 'share') !== false) echo '🔗';
                            else echo '📝';
                            ?>
                        </div>
                        <div class="activity-details">
                            <div class="activity-user"><?php echo escapeHtml($log['username'] ?? 'Sistema'); ?></div>
                            <div class="activity-action"><?php echo escapeHtml($log['action']); ?></div>
                            <div class="activity-time"><?php echo date('d M Y H:i', strtotime($log['created_at'])); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <div class="grid-2">
            <div class="card">
                <h3>💾 Top 10 - Uso de Almacenamiento</h3>
                <ul class="top-users-list">
                    <?php foreach ($topStorageUsers as $index => $user): ?>
                    <li class="top-user-item">
                        <div class="rank-badge <?php echo $index < 3 ? 'rank-'.($index+1) : 'rank-default'; ?>">
                            <?php echo $index + 1; ?>
                        </div>
                        <div class="top-user-info">
                            <span class="top-user-name">
                                <?php echo escapeHtml($user['username']); ?>
                                <?php if ($user['role'] === 'admin'): ?>
                                    <span class="badge badge-admin">ADMIN</span>
                                <?php endif; ?>
                            </span>
                            <span class="top-user-value">
                                <?php echo formatBytes($user['storage_used']); ?> / 
                                <?php echo formatBytes($user['storage_quota']); ?>
                            </span>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo min(100, ($user['storage_used'] / $user['storage_quota']) * 100); ?>%"></div>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <div class="card">
                <h3>⚡ Más Activos (Últimos 7 Días)</h3>
                <ul class="top-users-list">
                    <?php foreach ($mostActiveUsers as $index => $user): ?>
                    <li class="top-user-item">
                        <div class="rank-badge <?php echo $index < 3 ? 'rank-'.($index+1) : 'rank-default'; ?>">
                            <?php echo $index + 1; ?>
                        </div>
                        <div class="top-user-info">
                            <span class="top-user-name">
                                <?php echo escapeHtml($user['username']); ?>
                                <?php if ($user['role'] === 'admin'): ?>
                                    <span class="badge badge-admin">ADMIN</span>
                                <?php endif; ?>
                            </span>
                            <span class="top-user-value">
                                <?php echo number_format($user['action_count']); ?> acciones
                                <?php if ($user['last_action']): ?>
                                    - <?php echo date('d M H:i', strtotime($user['last_action'])); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        
        <div class="card" style="margin-bottom: 2rem;">
            <h3>📁 Top 10 - Cantidad de Archivos</h3>
            <ul class="top-users-list">
                <?php foreach ($topFileUsers as $index => $user): ?>
                <li class="top-user-item">
                    <div class="rank-badge <?php echo $index < 3 ? 'rank-'.($index+1) : 'rank-default'; ?>">
                        <?php echo $index + 1; ?>
                    </div>
                    <div class="top-user-info">
                        <span class="top-user-name">
                            <?php echo escapeHtml($user['username']); ?>
                            <?php if ($user['role'] === 'admin'): ?>
                                <span class="badge badge-admin">ADMIN</span>
                            <?php endif; ?>
                        </span>
                        <span class="top-user-value"><?php echo number_format($user['file_count']); ?> archivos</span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        
        <?php if (count($inactiveUsers30d) > 0): ?>
        <div class="card">
            <h3>😴 Usuarios Inactivos (30+ Días)</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Email</th>
                        <th>Rol</th>
                        <th>Último Login</th>
                        <th>Creado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inactiveUsers30d as $user): ?>
                    <tr>
                        <td><?php echo escapeHtml($user['username']); ?></td>
                        <td><?php echo escapeHtml($user['email']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $user['role']; ?>">
                                <?php echo strtoupper($user['role']); ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            if ($user['last_login']) {
                                $days = floor((time() - strtotime($user['last_login'])) / 86400);
                                echo date('d M Y', strtotime($user['last_login'])) . " (hace {$days} días)";
                            } else {
                                echo 'Nunca';
                            }
                            ?>
                        </td>
                        <td><?php echo date('d M Y', strtotime($user['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <script>
        const ctx = document.getElementById('activityChart').getContext('2d');
        const activityData = <?php echo json_encode($activityData); ?>;
        
        const labels = [];
        const data = [];
        for (let i = 6; i >= 0; i--) {
            const date = new Date();
            date.setDate(date.getDate() - i);
            const dateStr = date.toISOString().split('T')[0];
            labels.push(date.toLocaleDateString('es-ES', { month: 'short', day: 'numeric' }));
            
            const found = activityData.find(d => d.date === dateStr);
            data.push(found ? parseInt(found.count) : 0);
        }
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Archivos Subidos',
                    data: data,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#667eea',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 14, weight: 'bold' },
                        bodyFont: { size: 13 }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            color: '#64748b'
                        },
                        grid: {
                            color: '#f1f5f9'
                        }
                    },
                    x: {
                        ticks: {
                            color: '#64748b'
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
