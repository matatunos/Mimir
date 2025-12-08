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

// Obtener solo estadísticas del usuario
$stats = $userClass->getStatistics($user['id']);
$recentActivity = $userClass->getRecentActivity($user['id'], 10);

$isAdmin = ($user['role'] === 'admin');
renderPageStart('Dashboard', 'dashboard', $isAdmin);
renderHeader('Dashboard', $user);
?>

<style>
.stat-card-modern {
    background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    padding: 1.75rem;
    position: relative;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.stat-card-modern:hover {
    transform: translateY(-6px);
    box-shadow: 0 16px 32px rgba(0,0,0,0.12);
}
.stat-card-modern::after {
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
.stat-icon-modern {
    font-size: 4rem;
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    opacity: 0.08;
    transition: all 0.3s;
}
.stat-card-modern:hover .stat-icon-modern {
    opacity: 0.15;
    transform: translateY(-50%) scale(1.15) rotate(5deg);
}
.stat-value-modern {
    font-size: 3rem;
    font-weight: 900;
    background: linear-gradient(135deg, #4a90e2, #50c878);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    line-height: 1;
    margin-bottom: 0.5rem;
}
.stat-label-modern {
    font-size: 0.9375rem;
    color: var(--text-main);
    font-weight: 600;
    margin-bottom: 0.25rem;
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
    <!-- Estadísticas principales del usuario -->
    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 1.5rem; margin-bottom: 2rem;">
        <div class="stat-card-modern">
            <div style="position: relative; z-index: 1;">
                <div class="stat-label-modern">Mis Archivos</div>
                <div class="stat-value-modern"><?php echo $stats['total_files'] ?? 0; ?></div>
            </div>
            <div class="stat-icon-modern"><i class="fas fa-folder"></i></div>
        </div>
        
        <div class="stat-card-modern">
            <div style="position: relative; z-index: 1;">
                <div class="stat-label-modern">Mi Espacio Usado</div>
                <div class="stat-value-modern">
                    <?php 
                    $sizeGB = round(($stats['total_size'] ?? 0) / 1024 / 1024 / 1024, 2);
                    $quotaGB = round(($user['storage_quota'] ?? 10737418240) / 1024 / 1024 / 1024, 2);
                    $percentage = $quotaGB > 0 ? min(100, round(($sizeGB / $quotaGB) * 100, 1)) : 0;
                    echo $sizeGB . ' GB'; 
                    ?>
                </div>
                <small style="color: var(--text-muted); margin-bottom: 1rem; display: block;">de <?php echo $quotaGB; ?> GB (<?php echo $percentage; ?>%)</small>
                
                <div style="width: 100%; background: var(--bg-secondary); border-radius: 1rem; height: 12px; overflow: hidden; margin-top: 0.75rem;">
                    <div style="height: 100%; background: linear-gradient(90deg, #4a90e2, #50c878); border-radius: 1rem; transition: width 0.3s ease; width: <?php echo $percentage; ?>%;"></div>
                </div>
            </div>
            <div class="stat-icon-modern"><i class="fas fa-save"></i></div>
        </div>
    </div>

    <!-- Acciones Rápidas -->
    <div class="card" style="margin-bottom: 2rem;">
        <div class="card-header" style="background: linear-gradient(135deg, #e9b149, #444e52); color: white; padding: 1.5rem;">
            <h2 class="card-title" style="color: white; margin: 0;"><i class="fas fa-bolt"></i> Acciones Rápidas</h2>
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

    <!-- Actividad Reciente -->
    <?php
    $recentFiles = $fileClass->getByUser($user['id'], [], 10, 0);
    if (!empty($recentFiles)):
    ?>
    <div class="card">
        <div class="card-header" style="background: linear-gradient(135deg, #e9b149, #444e52); color: white; padding: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
            <h2 class="card-title" style="color: white; margin: 0;"><i class="fas fa-clock"></i> Archivos Recientes</h2>
            <a href="<?php echo BASE_URL; ?>/user/files.php" class="btn btn-sm btn-outline" style="background: rgba(255,255,255,0.2); color: white; border-color: rgba(255,255,255,0.3);">Ver todos</a>
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

<?php renderPageEnd(); ?>
