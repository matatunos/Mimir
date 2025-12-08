<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Share.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();

$userClass = new User();
$fileClass = new File();
$shareClass = new Share();
$logger = new Logger();

// Get system statistics
$db = Database::getInstance()->getConnection();

$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
$totalFiles = $db->query("SELECT COUNT(*) FROM files")->fetchColumn();
$totalShares = $db->query("SELECT COUNT(*) FROM shares WHERE is_active = 1")->fetchColumn();
$totalStorage = $db->query("SELECT COALESCE(SUM(file_size), 0) FROM files")->fetchColumn();
$storageGB = $totalStorage / 1024 / 1024 / 1024;

// Advanced statistics
// Get period from query parameter (default 30 days)
$period = isset($_GET['period']) ? (int)$_GET['period'] : 30;
$allowedPeriods = [30, 90, 365, 730, 1095]; // 30 días, 3 meses, 1 año, 2 años, 3 años
if (!in_array($period, $allowedPeriods)) {
    $period = 30;
}

$dailyUploads = $userClass->getSystemDailyUploads($period);
$weeklyUploads = $userClass->getSystemWeeklyUploads(52); // 1 año de semanas
$activityByDayOfWeek = $userClass->getActivityByDayOfWeek($period);
$weekendVsWeekday = $userClass->getWeekendVsWeekdayStats($period);
$fileTypeDistribution = $userClass->getSystemFileTypeDistribution();
$topUsersByUploads = $userClass->getTopUsersByUploads(10);
$shareStats = $userClass->getSystemShareStats();
$mostSharedFiles = $userClass->getMostSharedFiles(10);
$storageUsageByUser = $userClass->getStorageUsageByUser();

// Recent activity
$recentActivity = $logger->getActivityLogs([], 10, 0);

// Top users by storage
$topUsers = $db->query("
    SELECT u.username, u.full_name, 
           COALESCE(SUM(f.file_size), 0) as total_storage,
           COUNT(f.id) as file_count
    FROM users u
    LEFT JOIN files f ON u.id = f.user_id
    WHERE u.role = 'user'
    GROUP BY u.id
    ORDER BY total_storage DESC
    LIMIT 5
")->fetchAll();

// Prepare data for Chart.js
$dailyLabels = [];
$dailyCounts = [];
$dailySizes = [];
$dailyUsers = [];

// Date format depends on period
$dateFormat = 'd/m';
if ($period > 90) {
    $dateFormat = 'M y'; // Short month + year for long periods
}

for ($i = $period - 1; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dailyLabels[] = date($dateFormat, strtotime($date));
    $dailyCounts[$date] = 0;
    $dailySizes[$date] = 0;
    $dailyUsers[$date] = 0;
}

foreach ($dailyUploads as $upload) {
    $dailyCounts[$upload['date']] = (int)$upload['count'];
    $dailySizes[$upload['date']] = (float)$upload['total_size'];
    $dailyUsers[$upload['date']] = (int)$upload['unique_users'];
}

$dailyCountsValues = array_values($dailyCounts);
$dailySizesValues = array_map(function($size) {
    return round($size / 1024 / 1024, 2); // MB
}, array_values($dailySizes));
$dailyUsersValues = array_values($dailyUsers);

// Weekly data
$weeklyLabels = [];
$weeklyCounts = [];
$weeklySizes = [];
foreach ($weeklyUploads as $week) {
    $weeklyLabels[] = date('d/m', strtotime($week['week_start']));
    $weeklyCounts[] = (int)$week['count'];
    $weeklySizes[] = round($week['total_size'] / 1024 / 1024, 2);
}

// Day of week data
$dayOfWeekLabels = [];
$dayOfWeekCounts = [];
$dayOfWeekColors = [];
foreach ($activityByDayOfWeek as $day) {
    $dayOfWeekLabels[] = $day['day_name'];
    $dayOfWeekCounts[] = (int)$day['total_files'];
    // Weekend colors
    $isWeekend = in_array($day['day_of_week'], [5, 6]); // Sábado, Domingo
    $dayOfWeekColors[] = $isWeekend ? '#E24A90' : '#4A90E2';
}

// File type data
$typeLabels = [];
$typeCounts = [];
$typeColors = [
    'Imágenes' => '#4A90E2',
    'Vídeos' => '#E24A90',
    'Audio' => '#90E24A',
    'PDF' => '#E2904A',
    'Comprimidos' => '#904AE2',
    'Texto' => '#4AE290',
    'Otros' => '#999999'
];
$chartColors = [];

foreach ($fileTypeDistribution as $type) {
    $typeLabels[] = $type['type'];
    $typeCounts[] = (int)$type['count'];
    $chartColors[] = $typeColors[$type['type']] ?? '#999999';
}

renderPageStart('Dashboard Admin', 'dashboard', true);
renderHeader('Panel de Administración', $user);
?>

<style>
.admin-stat-card {
    background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    padding: 1.75rem;
    position: relative;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.admin-stat-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 16px 32px rgba(0,0,0,0.12);
}
.admin-stat-card::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 5px;
    background: linear-gradient(90deg, #4a90e2, #50c878, #ffa500, #9b59b6);
    background-size: 200% 100%;
    animation: shimmer 3s infinite;
}
@keyframes shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
.admin-stat-icon {
    font-size: 4rem;
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    opacity: 0.08;
    transition: all 0.3s;
}
.admin-stat-card:hover .admin-stat-icon {
    opacity: 0.15;
    transform: translateY(-50%) scale(1.15) rotate(5deg);
}
.admin-stat-value {
    font-size: 3rem;
    font-weight: 900;
    background: linear-gradient(135deg, #4a90e2, #50c878);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    line-height: 1;
    margin-bottom: 0.5rem;
}
.admin-stat-label {
    font-size: 0.9375rem;
    color: var(--text-main);
    font-weight: 600;
    margin-bottom: 0.25rem;
}
.admin-stat-sublabel {
    font-size: 0.8125rem;
    color: var(--text-muted);
    font-weight: 500;
}
.period-btn {
    padding: 0.5rem 1rem;
    border: 2px solid var(--border-color);
    background: var(--bg-main);
    color: var(--text-main);
    border-radius: 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.period-btn:hover {
    border-color: var(--primary);
    background: var(--primary);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(74, 144, 226, 0.2);
}
.period-btn.active {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
    box-shadow: 0 2px 8px rgba(74, 144, 226, 0.3);
}
</style>

<div class="content">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Usuarios Totales</div>
                <div class="admin-stat-value"><?php echo $totalUsers; ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-check"></i> <?php echo $activeUsers; ?> activos</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-users"></i></div>
        </div>
        
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Archivos Totales</div>
                <div class="admin-stat-value"><?php echo $totalFiles; ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-save"></i> <?php echo number_format($storageGB, 2); ?> GB</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-folder"></i></div>
        </div>
        
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Comparticiones</div>
                <div class="admin-stat-value"><?php echo $totalShares; ?></div>
                <div class="admin-stat-sublabel">🟢 Activas</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-link"></i></div>
        </div>
        
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Almacenamiento</div>
                <div class="admin-stat-value"><?php echo number_format($storageGB, 1); ?></div>
                <div class="admin-stat-sublabel">GB utilizados</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-save"></i></div>
        </div>
        
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Total Descargas</div>
                <div class="admin-stat-value"><?php echo $shareStats['total_downloads'] ?? 0; ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-chart-line"></i> <?php echo round($shareStats['avg_downloads_per_share'] ?? 0, 1); ?> promedio</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-download"></i></div>
        </div>
        
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Usuarios Compartiendo</div>
                <div class="admin-stat-value"><?php echo $shareStats['users_sharing'] ?? 0; ?></div>
                <div class="admin-stat-sublabel">de <?php echo $activeUsers; ?> activos</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-user-friends"></i></div>
        </div>
    </div>
    
    <!-- Gráficos -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <h3 class="card-title"><i class="fas fa-chart-area"></i> Actividad de Subidas</h3>
                <div class="period-selector" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button class="period-btn <?php echo $period === 30 ? 'active' : ''; ?>" data-period="30">30 días</button>
                    <button class="period-btn <?php echo $period === 90 ? 'active' : ''; ?>" data-period="90">3 meses</button>
                    <button class="period-btn <?php echo $period === 365 ? 'active' : ''; ?>" data-period="365">1 año</button>
                    <button class="period-btn <?php echo $period === 730 ? 'active' : ''; ?>" data-period="730">2 años</button>
                    <button class="period-btn <?php echo $period === 1095 ? 'active' : ''; ?>" data-period="1095">3 años</button>
                </div>
            </div>
            <div class="card-body">
                <canvas id="systemUploadsChart" height="300"></canvas>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-pie"></i> Distribución de Archivos</h3>
            </div>
            <div class="card-body">
                <canvas id="fileTypesChart" height="300"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Nuevas gráficas de análisis temporal -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-calendar-week"></i> Actividad por Semana (Último Año)</h3>
            </div>
            <div class="card-body">
                <canvas id="weeklyUploadsChart" height="300"></canvas>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-calendar-day"></i> Actividad por Día de la Semana</h3>
            </div>
            <div class="card-body">
                <canvas id="dayOfWeekChart" height="300"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Comparativa fin de semana vs entre semana -->
    <div class="card" style="margin-bottom: 2rem;">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-chart-bar"></i> Comparativa: Fin de Semana vs Entre Semana</h3>
        </div>
        <div class="card-body">
            <?php if (!empty($weekendVsWeekday)): ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
                    <?php foreach ($weekendVsWeekday as $stat): ?>
                    <div style="text-align: center; padding: 2rem; background: var(--bg-secondary); border-radius: 1rem; border: 2px solid <?php echo $stat['period_type'] === 'Fin de Semana' ? '#E24A90' : '#4A90E2'; ?>;">
                        <h3 style="font-size: 1.5rem; color: <?php echo $stat['period_type'] === 'Fin de Semana' ? '#E24A90' : '#4A90E2'; ?>; margin-bottom: 1rem;">
                            <?php if ($stat['period_type'] === 'Fin de Semana'): ?>
                                🎉 <?php echo $stat['period_type']; ?>
                            <?php else: ?>
                                💼 <?php echo $stat['period_type']; ?>
                            <?php endif; ?>
                        </h3>
                        <div style="display: grid; gap: 1rem; text-align: left;">
                            <div style="display: flex; justify-content: space-between; padding: 0.75rem; background: var(--bg-main); border-radius: 0.5rem;">
                                <span style="font-weight: 600;">Total Archivos:</span>
                                <span style="color: var(--primary); font-weight: 700; font-size: 1.25rem;"><?php echo number_format($stat['total_files']); ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 0.75rem; background: var(--bg-main); border-radius: 0.5rem;">
                                <span style="font-weight: 600;">Promedio por Día:</span>
                                <span style="color: var(--success); font-weight: 700; font-size: 1.25rem;"><?php echo round($stat['avg_files_per_day'], 1); ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 0.75rem; background: var(--bg-main); border-radius: 0.5rem;">
                                <span style="font-weight: 600;">Volumen Total:</span>
                                <span style="color: var(--warning); font-weight: 700;"><?php echo number_format($stat['total_size'] / 1024 / 1024 / 1024, 2); ?> GB</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 0.75rem; background: var(--bg-main); border-radius: 0.5rem;">
                                <span style="font-weight: 600;">Usuarios Únicos:</span>
                                <span style="font-weight: 700;"><?php echo $stat['unique_users']; ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <?php 
                // Calculate comparison
                $weekend = null;
                $weekday = null;
                foreach ($weekendVsWeekday as $stat) {
                    if ($stat['period_type'] === 'Fin de Semana') {
                        $weekend = $stat;
                    } else {
                        $weekday = $stat;
                    }
                }
                if ($weekend && $weekday):
                    $weekendAvg = $weekend['avg_files_per_day'];
                    $weekdayAvg = $weekday['avg_files_per_day'];
                    $difference = (($weekendAvg - $weekdayAvg) / $weekdayAvg) * 100;
                ?>
                <div style="margin-top: 2rem; padding: 1.5rem; background: linear-gradient(135deg, rgba(74, 144, 226, 0.1), rgba(226, 74, 144, 0.1)); border-radius: 1rem; text-align: center;">
                    <p style="font-size: 1.125rem; font-weight: 600; margin-bottom: 0.5rem;">
                        <?php if ($difference > 0): ?>
                            📈 Los fines de semana tienen un <span style="color: #E24A90; font-size: 1.5rem;"><?php echo abs(round($difference, 1)); ?>%</span> MÁS actividad que entre semana
                        <?php elseif ($difference < 0): ?>
                            📉 Los fines de semana tienen un <span style="color: #4A90E2; font-size: 1.5rem;"><?php echo abs(round($difference, 1)); ?>%</span> MENOS actividad que entre semana
                        <?php else: ?>
                            ⚖️ La actividad es similar entre semana y fin de semana
                        <?php endif; ?>
                    </p>
                    <p style="color: var(--text-muted); font-size: 0.9375rem;">
                        Promedio diario: <?php echo round($weekendAvg, 1); ?> archivos (fin de semana) vs <?php echo round($weekdayAvg, 1); ?> archivos (entre semana)
                    </p>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <p style="color: var(--text-muted); text-align: center; padding: 2rem;">Sin datos suficientes</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Tablas de estadísticas -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-trophy"></i> Top Usuarios por Subidas</h3>
            </div>
            <div class="card-body">
                <?php if (empty($topUsersByUploads)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">Sin datos</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>Archivos</th>
                                    <th>Tamaño</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topUsersByUploads as $u): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?></td>
                                    <td><span class="badge badge-primary"><?php echo $u['file_count']; ?></span></td>
                                    <td><?php echo number_format($u['total_size'] / 1024 / 1024 / 1024, 2); ?> GB</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fire"></i> Archivos Más Compartidos</h3>
            </div>
            <div class="card-body">
                <?php if (empty($mostSharedFiles)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">Sin datos</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Archivo</th>
                                    <th>Enlaces</th>
                                    <th>Descargas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mostSharedFiles as $file): ?>
                                <tr>
                                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($file['original_name']); ?>">
                                        <?php echo htmlspecialchars($file['original_name']); ?>
                                        <small style="display: block; color: var(--text-muted);"><?php echo htmlspecialchars($file['username']); ?></small>
                                    </td>
                                    <td><span class="badge badge-success"><?php echo $file['share_count']; ?></span></td>
                                    <td><strong><?php echo $file['total_downloads']; ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-database"></i> Uso de Cuota de Almacenamiento</h2>
            </div>
            <div class="card-body">
                <?php if (empty($storageUsageByUser)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">Sin datos</p>
                <?php else: ?>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php foreach ($storageUsageByUser as $u): ?>
                        <div style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?></div>
                                <div style="font-size: 0.875rem; color: var(--text-muted);">
                                    <?php echo number_format($u['used_storage'] / 1024 / 1024 / 1024, 2); ?> / <?php echo number_format($u['storage_quota'] / 1024 / 1024 / 1024, 1); ?> GB
                                </div>
                            </div>
                            <div style="background: var(--bg-secondary); height: 0.5rem; border-radius: 0.25rem; overflow: hidden;">
                                <div style="width: <?php echo min(100, $u['usage_percent']); ?>%; height: 100%; background: <?php echo $u['usage_percent'] > 90 ? 'var(--danger)' : ($u['usage_percent'] > 70 ? 'var(--warning)' : 'var(--success)'); ?>; transition: width 0.3s;"></div>
                            </div>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; text-align: right;">
                                <?php echo round($u['usage_percent'], 1); ?>%
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-history"></i> Actividad Reciente</h2>
            </div>
            <div class="card-body">
                <?php if (empty($recentActivity)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">Sin actividad</p>
                <?php else: ?>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php foreach ($recentActivity as $activity): ?>
                        <div style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                <div style="flex: 1;">
                                    <div style="font-weight: 500; margin-bottom: 0.25rem;">
                                        <?php echo htmlspecialchars($activity['username'] ?? 'Sistema'); ?>
                                    </div>
                                    <div style="font-size: 0.875rem; color: var(--text-muted);">
                                        <?php echo htmlspecialchars($activity['description']); ?>
                                    </div>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); white-space: nowrap; margin-left: 1rem;">
                                    <?php 
                                        $time = strtotime($activity['created_at']);
                                        $diff = time() - $time;
                                        if ($diff < 60) echo 'Ahora';
                                        elseif ($diff < 3600) echo ceil($diff / 60) . ' min';
                                        elseif ($diff < 86400) echo ceil($diff / 3600) . ' h';
                                        else echo date('d/m H:i', $time);
                                    ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <style>
    .admin-quick-action {
        background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%);
        border: 2px solid var(--border-color);
        border-radius: 1rem;
        padding: 2rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 1rem;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        text-decoration: none;
        color: var(--text-main);
        position: relative;
        overflow: hidden;
    }
    .admin-quick-action::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(74, 144, 226, 0.1), transparent);
        transition: left 0.5s;
    }
    .admin-quick-action:hover::before {
        left: 100%;
    }
    .admin-quick-action:hover {
        transform: translateY(-8px);
        border-color: var(--primary);
        box-shadow: 0 12px 24px rgba(74, 144, 226, 0.2);
        background: var(--primary);
        color: white;
    }
    .admin-action-icon {
        font-size: 3rem;
        transition: transform 0.3s;
    }
    .admin-quick-action:hover .admin-action-icon {
        transform: scale(1.2) rotate(5deg);
    }
    .admin-action-text {
        font-weight: 700;
        font-size: 1.0625rem;
        text-align: center;
    }
    </style>
    
    <div class="card mt-3" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
        <div class="card-header" style="background: linear-gradient(135deg, #4a90e2, #50c878); color: white; padding: 1.5rem;">
            <h2 class="card-title" style="color: white; font-weight: 700; font-size: 1.5rem;"><i class="fas fa-bolt"></i> Accesos Rápidos</h2>
        </div>
        <div class="card-body" style="padding: 2rem;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                <a href="<?php echo BASE_URL; ?>/admin/users.php" class="admin-quick-action">
                    <div class="admin-action-icon"><i class="fas fa-users"></i></div>
                    <div class="admin-action-text">Gestionar Usuarios</div>
                </a>
                <a href="<?php echo BASE_URL; ?>/admin/files.php" class="admin-quick-action">
                    <div class="admin-action-icon"><i class="fas fa-folder"></i></div>
                    <div class="admin-action-text">Gestionar Archivos</div>
                </a>
                <a href="<?php echo BASE_URL; ?>/admin/shares.php" class="admin-quick-action">
                    <div class="admin-action-icon"><i class="fas fa-link"></i></div>
                    <div class="admin-action-text">Ver Comparticiones</div>
                </a>
                <a href="<?php echo BASE_URL; ?>/admin/config.php" class="admin-quick-action">
                    <div class="admin-action-icon"><i class="fas fa-cog"></i></div>
                    <div class="admin-action-text">Configuración</div>
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Period selector buttons
    document.querySelectorAll('.period-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const period = this.getAttribute('data-period');
            window.location.href = '<?php echo BASE_URL; ?>/admin/index.php?period=' + period;
        });
    });

    // Gráfico de actividad del sistema
    const systemCtx = document.getElementById('systemUploadsChart');
    if (systemCtx) {
        new Chart(systemCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dailyLabels); ?>,
                datasets: [{
                    label: 'Archivos Subidos',
                    data: <?php echo json_encode($dailyCountsValues); ?>,
                    borderColor: 'rgb(74, 144, 226)',
                    backgroundColor: 'rgba(74, 144, 226, 0.1)',
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y'
                }, {
                    label: 'Volumen (MB)',
                    data: <?php echo json_encode($dailySizesValues); ?>,
                    borderColor: 'rgb(80, 200, 120)',
                    backgroundColor: 'rgba(80, 200, 120, 0.1)',
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y1'
                }, {
                    label: 'Usuarios Activos',
                    data: <?php echo json_encode($dailyUsersValues); ?>,
                    borderColor: 'rgb(255, 165, 0)',
                    backgroundColor: 'rgba(255, 165, 0, 0.1)',
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y2'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Archivos'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'MB'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    },
                    y2: {
                        type: 'linear',
                        display: false,
                        position: 'right',
                    }
                }
            }
        });
    }

    // Gráfico de distribución de tipos
    const typesCtx = document.getElementById('fileTypesChart');
    if (typesCtx) {
        new Chart(typesCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($typeLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($typeCounts); ?>,
                    backgroundColor: <?php echo json_encode($chartColors); ?>,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                    },
                    title: {
                        display: false
                    }
                }
            }
        });
    }
    
    // Gráfico semanal
    const weeklyCtx = document.getElementById('weeklyUploadsChart');
    if (weeklyCtx) {
        new Chart(weeklyCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($weeklyLabels); ?>,
                datasets: [{
                    label: 'Archivos por Semana',
                    data: <?php echo json_encode($weeklyCounts); ?>,
                    backgroundColor: 'rgba(74, 144, 226, 0.7)',
                    borderColor: 'rgb(74, 144, 226)',
                    borderWidth: 2,
                    borderRadius: 6
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
                        callbacks: {
                            afterLabel: function(context) {
                                const size = <?php echo json_encode($weeklySizes); ?>[context.dataIndex];
                                return 'Volumen: ' + size + ' MB';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Archivos'
                        }
                    }
                }
            }
        });
    }
    
    // Gráfico por día de la semana
    const dayOfWeekCtx = document.getElementById('dayOfWeekChart');
    if (dayOfWeekCtx) {
        new Chart(dayOfWeekCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($dayOfWeekLabels); ?>,
                datasets: [{
                    label: 'Archivos Subidos',
                    data: <?php echo json_encode($dayOfWeekCounts); ?>,
                    backgroundColor: <?php echo json_encode($dayOfWeekColors); ?>,
                    borderColor: <?php echo json_encode($dayOfWeekColors); ?>,
                    borderWidth: 2,
                    borderRadius: 8
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
                        callbacks: {
                            afterLabel: function(context) {
                                const dayIndex = context.dataIndex;
                                return dayIndex >= 5 ? '🎉 Fin de Semana' : '💼 Entre Semana';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Total de Archivos'
                        }
                    }
                }
            }
        });
    }
});
</script>

<?php renderPageEnd(); ?>
