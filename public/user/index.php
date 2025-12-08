<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Share.php';
require_once __DIR__ . '/../../classes/Config.php';

$auth = new Auth();
$auth->requireLogin();

$user = $auth->getUser();
$userClass = new User();
$fileClass = new File();
$shareClass = new Share();

// Obtener todas las estadísticas
$stats = $userClass->getStatistics($user['id']);
$dailyUploads = $userClass->getDailyUploads($user['id'], 30);
$topFilesByCount = $userClass->getTopFilesByCount($user['id'], 10);
$topFilesBySize = $userClass->getTopFilesBySize($user['id'], 10);
$downloadStats = $userClass->getDownloadStats($user['id']);
$fileTypeDistribution = $userClass->getFileTypeDistribution($user['id']);
$shareStats = $userClass->getShareStats($user['id']);
$recentActivity = $userClass->getRecentActivity($user['id'], 10);

// Preparar datos para Chart.js
$dailyLabels = [];
$dailyCounts = [];
$dailySizes = [];

// Rellenar los últimos 30 días
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dailyLabels[] = date('d/m', strtotime($date));
    $dailyCounts[$date] = 0;
    $dailySizes[$date] = 0;
}

foreach ($dailyUploads as $upload) {
    $dailyCounts[$upload['date']] = (int)$upload['count'];
    $dailySizes[$upload['date']] = (float)$upload['total_size'];
}

$dailyCountsValues = array_values($dailyCounts);
$dailySizesValues = array_map(function($size) {
    return round($size / 1024 / 1024, 2); // Convert to MB
}, array_values($dailySizes));

// Datos para distribución de tipos
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

$isAdmin = ($user['role'] === 'admin');
renderPageStart('Dashboard', 'dashboard', $isAdmin);
renderHeader('Dashboard', $user);
?>

<style>
.stat-card-modern {
    background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    padding: 1.5rem;
    position: relative;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.stat-card-modern:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.15);
    border-color: var(--primary);
}
.stat-card-modern::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--primary), var(--success));
    opacity: 0;
    transition: opacity 0.3s;
}
.stat-card-modern:hover::before {
    opacity: 1;
}
.stat-icon-modern {
    font-size: 3.5rem;
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    opacity: 0.1;
    transition: all 0.3s;
}
.stat-card-modern:hover .stat-icon-modern {
    opacity: 0.2;
    transform: translateY(-50%) scale(1.1);
}
.stat-value-modern {
    font-size: 2.5rem;
    font-weight: 800;
    background: linear-gradient(135deg, var(--primary), var(--success));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 0.25rem;
}
.stat-label-modern {
    font-size: 0.875rem;
    color: var(--text-muted);
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.quick-action-btn {
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    border-radius: 0.75rem;
    padding: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: all 0.3s;
    text-decoration: none;
    color: var(--text-main);
}
.quick-action-btn:hover {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
    transform: translateX(4px);
}
.quick-action-icon {
    font-size: 2rem;
    transition: transform 0.3s;
}
.quick-action-btn:hover .quick-action-icon {
    transform: scale(1.2);
}
</style>

<div class="content">
    <!-- Estadísticas principales -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <div class="stat-card-modern">
            <div style="position: relative; z-index: 1;">
                <div class="stat-label-modern">Total Archivos</div>
                <div class="stat-value-modern"><?php echo $stats['total_files'] ?? 0; ?></div>
            </div>
            <div class="stat-icon-modern"><i class="fas fa-folder"></i></div>
        </div>
        
        <div class="stat-card-modern">
            <div style="position: relative; z-index: 1;">
                <div class="stat-label-modern">Espacio Usado</div>
                <div class="stat-value-modern">
                    <?php 
                    $sizeGB = round(($stats['total_size'] ?? 0) / 1024 / 1024 / 1024, 2);
                    echo $sizeGB . ' GB'; 
                    ?>
                </div>
            </div>
            <div class="stat-icon-modern"><i class="fas fa-save"></i></div>
        </div>
        
        <div class="stat-card-modern">
            <div style="position: relative; z-index: 1;">
                <div class="stat-label-modern">Enlaces Compartidos</div>
                <div class="stat-value-modern"><?php echo $shareStats['total_shares'] ?? 0; ?></div>
                <small style="color: var(--success);"><?php echo $shareStats['active_shares'] ?? 0; ?> activos</small>
            </div>
            <div class="stat-icon-modern"><i class="fas fa-link"></i></div>
        </div>
        
        <div class="stat-card-modern">
            <div style="position: relative; z-index: 1;">
                <div class="stat-label-modern">Total Descargas</div>
                <div class="stat-value-modern"><?php echo $shareStats['total_downloads'] ?? 0; ?></div>
            </div>
            <div class="stat-icon-modern"><i class="fas fa-download"></i></div>
        </div>
        
        <div class="stat-card-modern">
            <div style="position: relative; z-index: 1;">
                <div class="stat-label-modern">Tasa de Descarga</div>
                <div class="stat-value-modern"><?php echo round($downloadStats['download_rate'] ?? 0, 1); ?>%</div>
                <small style="color: var(--text-muted);"><?php echo $downloadStats['files_with_downloads'] ?? 0; ?> archivos descargados</small>
            </div>
            <div class="stat-icon-modern"><i class="fas fa-chart-line"></i></div>
        </div>
        
        <div class="stat-card-modern">
            <div style="position: relative; z-index: 1;">
                <div class="stat-label-modern">Promedio Descargas</div>
                <div class="stat-value-modern"><?php echo round($shareStats['avg_downloads_per_share'] ?? 0, 1); ?></div>
                <small style="color: var(--text-muted);">por enlace</small>
            </div>
            <div class="stat-icon-modern"><i class="fas fa-chart-bar"></i></div>
        </div>
    </div>

    <!-- Acciones Rápidas -->
    <div class="card" style="border-radius: 1rem; overflow: hidden; margin-bottom: 2rem;">
        <div class="card-header" style="background: linear-gradient(135deg, var(--primary), var(--success)); color: white;">
            <h2 class="card-title" style="color: white; font-weight: 700;"><i class="fas fa-bolt"></i> Acciones Rápidas</h2>
        </div>
        <div class="card-body" style="padding: 1.5rem;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <a href="<?php echo BASE_URL; ?>/user/files.php" class="quick-action-btn">
                    <span class="quick-action-icon">📂</span>
                    <span style="font-weight: 600;">Mis Archivos</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/user/upload.php" class="quick-action-btn">
                    <span class="quick-action-icon"><i class="fas fa-upload"></i></span>
                    <span style="font-weight: 600;">Subir Archivo</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/user/shares.php" class="quick-action-btn">
                    <span class="quick-action-icon"><i class="fas fa-link"></i></span>
                    <span style="font-weight: 600;">Mis Comparticiones</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Gráficos -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <!-- Gráfico de subidas diarias -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-area"></i> Subidas Últimos 30 Días</h3>
            </div>
            <div class="card-body">
                <canvas id="dailyUploadsChart" height="250"></canvas>
            </div>
        </div>
        
        <!-- Gráfico de tipos de archivo -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-pie"></i> Distribución por Tipo</h3>
            </div>
            <div class="card-body">
                <canvas id="fileTypesChart" height="250"></canvas>
            </div>
        </div>
    </div>

    <!-- Top Files -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <!-- Top archivos por número de subidas -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fire"></i> Top Archivos Más Subidos</h3>
            </div>
            <div class="card-body">
                <?php if (!empty($topFilesByCount)): ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Archivo</th>
                                <th>Subidas</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topFilesByCount as $file): ?>
                            <tr>
                                <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($file['original_name']); ?>">
                                    <?php echo htmlspecialchars($file['original_name']); ?>
                                </td>
                                <td><span class="badge badge-primary"><?php echo $file['upload_count']; ?></span></td>
                                <td><?php echo number_format($file['total_size'] / 1024 / 1024, 1); ?> MB</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p style="text-align: center; color: var(--text-muted); padding: 2rem;">No hay datos disponibles</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top archivos por tamaño -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-weight-hanging"></i> Archivos Más Grandes</h3>
            </div>
            <div class="card-body">
                <?php if (!empty($topFilesBySize)): ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Archivo</th>
                                <th>Tamaño</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topFilesBySize as $file): ?>
                            <tr>
                                <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($file['original_name']); ?>">
                                    <?php echo htmlspecialchars($file['original_name']); ?>
                                </td>
                                <td><strong><?php echo number_format($file['file_size'] / 1024 / 1024, 1); ?> MB</strong></td>
                                <td><?php echo date('d/m/Y', strtotime($file['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p style="text-align: center; color: var(--text-muted); padding: 2rem;">No hay datos disponibles</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Archivos Recientes -->
    <?php
    $recentFiles = $fileClass->getByUser($user['id'], [], 10, 0);
    if (!empty($recentFiles)):
    ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fas fa-clock"></i> Archivos Recientes</h2>
            <a href="<?php echo BASE_URL; ?>/user/files.php" class="btn btn-sm btn-outline">Ver todos</a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Tamaño</th>
                            <th>Compartido</th>
                            <th>Fecha</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentFiles as $file): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($file['original_name']); ?></td>
                            <td><?php echo number_format($file['file_size'] / 1024 / 1024, 2); ?> MB</td>
                            <td>
                                <?php if ($file['is_shared']): ?>
                                    <span class="badge badge-success">Sí</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">No</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d/m/Y H:i', strtotime($file['created_at'])); ?></td>
                            <td>
                                <a href="<?php echo BASE_URL; ?>/user/download.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-primary">Descargar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Gráfico de subidas diarias
    const dailyCtx = document.getElementById('dailyUploadsChart');
    if (dailyCtx) {
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dailyLabels); ?>,
                datasets: [{
                    label: 'Número de Archivos',
                    data: <?php echo json_encode($dailyCountsValues); ?>,
                    borderColor: 'rgb(74, 144, 226)',
                    backgroundColor: 'rgba(74, 144, 226, 0.1)',
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y'
                }, {
                    label: 'Volumen (MB)',
                    data: <?php echo json_encode($dailySizesValues); ?>,
                    borderColor: 'rgb(144, 226, 74)',
                    backgroundColor: 'rgba(144, 226, 74, 0.1)',
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y1'
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
                }
            }
        });
    }

    // Gráfico de tipos de archivo
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
});
</script>

<?php renderPageEnd(); ?>
