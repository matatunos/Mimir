<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();
$fileClass = new File();
$logger = new Logger();

$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$filters = [];
if ($search) $filters['search'] = $search;

$files = $fileClass->getByUser($user['id'], $filters, $perPage, $offset);
$totalFiles = $fileClass->getCount($user['id'], $filters);
$totalPages = ceil($totalFiles / $perPage);

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

$isAdmin = ($user['role'] === 'admin');
renderPageStart('Mis Archivos', 'files', $isAdmin);
renderHeader('Mis Archivos', $user);
?>

<div class="content">
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
        <div class="card-header" style="background: linear-gradient(135deg, #4a90e2, #50c878); color: white; padding: 1.5rem;">
            <h2 class="card-title" style="color: white; font-weight: 700; font-size: 1.5rem;"><i class="fas fa-folder"></i> Mis Archivos (<?php echo $totalFiles; ?>)</h2>
            <a href="<?php echo BASE_URL; ?>/user/upload.php" class="btn btn-success" style="background: white; color: #4a90e2; border: none; font-weight: 600;"><i class="fas fa-upload"></i> Subir Archivo</a>
        </div>
        <div class="card-body">
            <form method="GET" class="mb-3">
                <div style="display: flex; gap: 0.75rem;">
                    <input type="text" name="search" class="form-control" placeholder="Buscar archivos..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary">Buscar</button>
                    <?php if ($search): ?>
                        <a href="<?php echo BASE_URL; ?>/user/files.php" class="btn btn-outline">Limpiar</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (empty($files)): ?>
                <div style="text-align: center; padding: 4rem; background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%); border-radius: 1rem; border: 2px dashed var(--border-color);">
                    <div style="font-size: 5rem; margin-bottom: 1.5rem; opacity: 0.3;"><i class="fas fa-folder"></i></div>
                    <h3 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem;">No tienes archivos aún</h3>
                    <p style="color: var(--text-muted); margin-bottom: 2rem;">Comienza subiendo tu primer archivo</p>
                    <a href="<?php echo BASE_URL; ?>/user/upload.php" class="btn btn-primary" style="padding: 0.75rem 2rem; font-size: 1.0625rem; font-weight: 600; box-shadow: 0 4px 12px rgba(74, 144, 226, 0.3);"><i class="fas fa-upload"></i> Subir tu primer archivo</a>
                </div>
            <?php else: ?>
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
                            <?php foreach ($files as $file): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($file['original_name']); ?></div>
                                    <?php if ($file['description']): ?>
                                        <div style="font-size: 0.8125rem; color: var(--text-muted);"><?php echo htmlspecialchars($file['description']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format($file['file_size'] / 1024 / 1024, 2); ?> MB</td>
                                <td>
                                    <?php if ($file['is_shared']): ?>
                                        <span class="badge badge-success"><i class="fas fa-check"></i> Compartido (<?php echo $file['share_count']; ?>)</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">No compartido</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($file['created_at'])); ?></td>
                                <td>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <a href="<?php echo BASE_URL; ?>/user/download.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-primary" title="Descargar"><i class="fas fa-download"></i></a>
                                        <a href="<?php echo BASE_URL; ?>/user/share.php?file_id=<?php echo $file['id']; ?>" class="btn btn-sm btn-success" title="Compartir"><i class="fas fa-link"></i></a>
                                        <a href="<?php echo BASE_URL; ?>/user/delete.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar este archivo?')" title="Eliminar"><i class="fas fa-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">« Anterior</a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Siguiente »</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php renderPageEnd(); ?>
