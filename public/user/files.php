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
$currentFolderId = isset($_GET['folder']) && $_GET['folder'] !== '' ? (int)$_GET['folder'] : null;
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Get folder contents or search results
if ($search) {
    $filters = ['search' => $search];
    $files = $fileClass->getByUser($user['id'], $filters, $perPage, $offset);
    $totalFiles = $fileClass->getCount($user['id'], $filters);
} else {
    $files = $fileClass->getFolderContents($user['id'], $currentFolderId);
    $totalFiles = count($files);
}

$totalPages = ceil($totalFiles / $perPage);

// Get breadcrumb path if in a folder
$breadcrumbs = [];
if ($currentFolderId) {
    $breadcrumbs = $fileClass->getFolderPath($currentFolderId);
}

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
        <div class="card-header" style="background: linear-gradient(135deg, #e9b149, #444e52); color: white; padding: 1.5rem;">
            <h2 class="card-title" style="color: white; font-weight: 700; font-size: 1.5rem; margin: 0;"><i class="fas fa-folder"></i> Mis Archivos (<?php echo $totalFiles; ?>)</h2>
            <div style="display: flex; gap: 0.5rem;">
                <button onclick="showCreateFolderModal()" class="btn btn-primary" style="background: white; color: #4a90e2; border: none; font-weight: 600;">
                    <i class="fas fa-folder-plus"></i> Nueva Carpeta
                </button>
                <a href="<?php echo BASE_URL; ?>/user/upload.php<?php echo $currentFolderId ? '?folder=' . $currentFolderId : ''; ?>" class="btn btn-success" style="background: white; color: #50c878; border: none; font-weight: 600;">
                    <i class="fas fa-upload"></i> Subir Archivo
                </a>
            </div>
        </div>
        <div class="card-body">
            
            <!-- Breadcrumb Navigation -->
            <?php if (!$search): ?>
            <div style="margin-bottom: 1.5rem; padding: 0.75rem 1rem; background: var(--bg-secondary); border-radius: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-home" style="color: var(--text-muted);"></i>
                <a href="<?php echo BASE_URL; ?>/user/files.php" style="color: var(--text-main); text-decoration: none; font-weight: 500;">
                    Inicio
                </a>
                <?php foreach ($breadcrumbs as $folder): ?>
                    <i class="fas fa-chevron-right" style="color: var(--text-muted); font-size: 0.75rem;"></i>
                    <a href="<?php echo BASE_URL; ?>/user/files.php?folder=<?php echo $folder['id']; ?>" style="color: var(--text-main); text-decoration: none; font-weight: 500;">
                        <?php echo htmlspecialchars($folder['name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
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
                                    <?php if ($file['is_folder']): ?>
                                        <a href="<?php echo BASE_URL; ?>/user/files.php?folder=<?php echo $file['id']; ?>" style="text-decoration: none; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem;">
                                            <i class="fas fa-folder" style="color: #e9b149; font-size: 1.25rem;"></i>
                                            <div>
                                                <div style="font-weight: 600;"><?php echo htmlspecialchars($file['original_name']); ?></div>
                                                <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                    <?php echo $file['file_count']; ?> archivo(s), <?php echo $file['subfolder_count']; ?> carpeta(s)
                                                </div>
                                            </div>
                                        </a>
                                    <?php else: ?>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <i class="fas fa-file" style="color: var(--text-muted);"></i>
                                            <div>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($file['original_name']); ?></div>
                                                <?php if ($file['description']): ?>
                                                    <div style="font-size: 0.8125rem; color: var(--text-muted);"><?php echo htmlspecialchars($file['description']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($file['is_folder']): ?>
                                        <span style="color: var(--text-muted);">—</span>
                                    <?php else: ?>
                                        <?php echo number_format($file['file_size'] / 1024 / 1024, 2); ?> MB
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($file['is_folder']): ?>
                                        <span style="color: var(--text-muted);">—</span>
                                    <?php elseif ($file['is_shared']): ?>
                                        <span class="badge badge-success"><i class="fas fa-check"></i> Compartido (<?php echo $file['share_count']; ?>)</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">No compartido</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($file['created_at'])); ?></td>
                                <td>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <?php if ($file['is_folder']): ?>
                                            <a href="<?php echo BASE_URL; ?>/user/files.php?folder=<?php echo $file['id']; ?>" class="btn btn-sm btn-primary" title="Abrir"><i class="fas fa-folder-open"></i></a>
                                            <button onclick="deleteFolder(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>')" class="btn btn-sm btn-danger" title="Eliminar"><i class="fas fa-trash"></i></button>
                                        <?php else: ?>
                                            <a href="<?php echo BASE_URL; ?>/user/download.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-primary" title="Descargar"><i class="fas fa-download"></i></a>
                                            <a href="<?php echo BASE_URL; ?>/user/share.php?file_id=<?php echo $file['id']; ?>" class="btn btn-sm btn-success" title="Compartir"><i class="fas fa-link"></i></a>
                                            <a href="<?php echo BASE_URL; ?>/user/delete.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar este archivo?')" title="Eliminar"><i class="fas fa-trash"></i></a>
                                        <?php endif; ?>
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

<!-- Modal para crear carpeta -->
<div id="createFolderModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: var(--bg-main); border-radius: 1rem; padding: 2rem; max-width: 500px; width: 90%; box-shadow: 0 8px 32px rgba(0,0,0,0.2);">
        <h3 style="margin: 0 0 1.5rem 0; color: var(--text-main);"><i class="fas fa-folder-plus"></i> Nueva Carpeta</h3>
        <form id="createFolderForm" onsubmit="createFolder(event)">
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-main);">Nombre de la carpeta</label>
                <input type="text" id="folderName" class="form-control" placeholder="Mi Carpeta" required autofocus>
            </div>
            <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                <button type="button" onclick="hideCreateFolderModal()" class="btn btn-outline">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Crear Carpeta</button>
            </div>
        </form>
    </div>
</div>

<script>
function showCreateFolderModal() {
    document.getElementById('createFolderModal').style.display = 'flex';
    document.getElementById('folderName').focus();
}

function hideCreateFolderModal() {
    document.getElementById('createFolderModal').style.display = 'none';
    document.getElementById('folderName').value = '';
}

async function createFolder(event) {
    event.preventDefault();
    
    const folderName = document.getElementById('folderName').value.trim();
    if (!folderName) return;
    
    const currentFolderId = <?php echo $currentFolderId ? $currentFolderId : 'null'; ?>;
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/user/create_folder.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                folder_name: folderName,
                parent_folder_id: currentFolderId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            hideCreateFolderModal();
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al crear la carpeta');
    }
}

async function deleteFolder(folderId, folderName) {
    if (!confirm(`¿Eliminar la carpeta "${folderName}" y todo su contenido?`)) {
        return;
    }
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/user/delete_folder.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                folder_id: folderId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al eliminar la carpeta');
    }
}

// Cerrar modal con ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideCreateFolderModal();
    }
});
</script>

<?php renderPageEnd(); ?>
