<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/Config.php';

$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();
$fileClass = new File();
$logger = new Logger();

$search = $_GET['search'] ?? '';
$currentFolderId = isset($_GET['folder']) && $_GET['folder'] !== '' ? (int)$_GET['folder'] : null;

// Pagination: use configured items per page as page size
$config = new Config();
$perPage = (int)$config->get('items_per_page', 25);
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Get folder contents or search results
if ($search) {
    $filters = ['search' => $search];
    $files = $fileClass->getByUser($user['id'], $filters, $perPage, $offset);
    $totalFiles = $fileClass->getCount($user['id'], $filters);
} else {
    // get all items in folder, then slice for pagination (getFolderContents has no SQL-level pagination)
    $all = $fileClass->getFolderContents($user['id'], $currentFolderId);
    $totalFiles = count($all);
    if ($totalFiles > $perPage) {
        $files = array_slice($all, $offset, $perPage);
    } else {
        $files = $all;
    }
}

$totalPages = $totalFiles > 0 ? (int)ceil($totalFiles / $perPage) : 1;

// Get breadcrumb path if in a folder
$breadcrumbs = [];
if ($currentFolderId) {
    $breadcrumbs = $fileClass->getFolderPath($currentFolderId);
}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

$isAdmin = ($user['role'] === 'admin');
renderPageStart('Mis Archivos', 'user-files', $isAdmin);
renderHeader('Mis Archivos', $user);
?>

<style>
/* Floating bulk actions bar (similar to admin) */
.bulk-actions-bar {
    position: fixed;
    bottom: 2rem;
    left: 50%;
    transform: translateX(-50%);
    background: linear-gradient(135deg, #4a90e2, #50c878);
    color: white;
    padding: 1rem 2rem;
    border-radius: 2rem;
    box-shadow: 0 8px 32px rgba(0,0,0,0.3);
    display: none;
    z-index: 1000;
    animation: slideUp 0.3s ease-out;
}
@keyframes slideUp {
    from { transform: translateX(-50%) translateY(100px); opacity: 0; }
    to { transform: translateX(-50%) translateY(0); opacity: 1; }
}
.bulk-actions-bar.show { display: flex; align-items: center; gap: 1rem; }
.file-checkbox { width: 18px; height: 18px; cursor: pointer; }
</style>

<div class="content">
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
        <div class="card-header" style="padding: 1.5rem;">
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
                        <a href="<?php echo BASE_URL; ?>/user/files.php" class="btn btn-outline btn-outline--on-dark">Limpiar</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (empty($files)): ?>
                <div style="text-align: center; padding: 4rem; background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%); border-radius: 1rem; border: 2px dashed var(--border-color);">
                    <div style="font-size: 5rem; margin-bottom: 1.5rem; opacity: 0.3;"><i class="fas fa-folder"></i></div>
                    <h3 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem;">No tienes archivos aún</h3>
                    <p style="color: var(--text-muted); margin-bottom: 2rem;">Comienza subiendo tu primer archivo</p>
                    <a href="<?php echo BASE_URL; ?>/user/upload.php<?php echo $currentFolderId ? '?folder=' . $currentFolderId : ''; ?>" class="btn btn-primary" style="padding: 0.75rem 2rem; font-size: 1.0625rem; font-weight: 600; box-shadow: 0 4px 12px rgba(74, 144, 226, 0.3);"><i class="fas fa-upload"></i> Subir tu primer archivo</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                                    <tr>
                                        <th style="width:40px;"><input type="checkbox" id="selectAllUser" class="file-checkbox"></th>
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
                                            <input type="checkbox" name="file_ids[]" value="<?php echo $file['id']; ?>" class="file-checkbox user-file-item">
                                        </td>
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
                                            <?php if ($file['is_shared']): ?>
                                                <form method="POST" action="<?php echo BASE_URL; ?>/user/bulk_action.php" style="display:inline; margin:0;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                                                    <input type="hidden" name="file_ids[]" value="<?php echo $file['id']; ?>">
                                                    <input type="hidden" name="action" value="unshare">
                                                    <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('¿Desactivar el compartido de este archivo?')" title="Desactivar compartido"><i class="fas fa-ban"></i></button>
                                                </form>
                                            <?php endif; ?>
                                            <a href="<?php echo BASE_URL; ?>/user/delete.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar este archivo?')" title="Eliminar"><i class="fas fa-trash"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Bulk actions form (hidden submit) -->
                <form method="POST" id="userBulkForm" action="<?php echo BASE_URL; ?>/user/bulk_action.php">
                    <input type="hidden" name="action" id="userBulkAction" value="">
                    <input type="hidden" name="select_all" id="userSelectAll" value="0">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <input type="hidden" name="folder" value="<?php echo $currentFolderId ? $currentFolderId : ''; ?>">
                    <!-- target_folder removed per UI request -->
                    <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                </form>

                <!-- Bulk Actions Bar for users -->
                <div class="bulk-actions-bar" id="userBulkActionsBar">
                    <span id="userSelectedCount">0</span> elementos seleccionados
                    <button type="button" class="btn btn-danger" onclick="confirmUserBulkAction('delete')">
                        <i class="fas fa-trash"></i> Eliminar seleccionados
                    </button>
                    <button type="button" class="btn btn-warning" onclick="confirmUserBulkAction('share')">
                        <i class="fas fa-share"></i> Marcar como compartidos
                    </button>
                    <button type="button" class="btn btn-info" onclick="confirmUserBulkAction('unshare')">
                        <i class="fas fa-ban"></i> Desactivar compartidos
                    </button>
                    <!-- Move action removed from user UI -->
                    <button type="button" class="btn btn-secondary" onclick="clearUserSelection()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-outline btn-outline--on-dark" id="selectAllMatchingBtn" style="margin-left:1rem;">
                        Seleccionar todos los <?php echo $totalFiles; ?> coincidencias
                    </button>
                </div>

                <!-- Bulk confirm modal -->
                <div id="bulkConfirmModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center;">
                    <div style="background:var(--bg-main); padding:1.5rem; border-radius:0.75rem; width:90%; max-width:600px;">
                        <h3 id="bulkConfirmTitle">Confirmar acción</h3>
                        <p id="bulkConfirmBody">Se van a procesar <strong id="bulkConfirmCount">0</strong> elementos.</p>
                        <div style="display:flex; gap:0.5rem; justify-content:flex-end; margin-top:1rem;">
                            <button type="button" class="btn btn-outline btn-outline--on-dark" onclick="hideBulkConfirmModal()">Cancelar</button>
                            <button type="button" class="btn btn-danger" id="bulkConfirmBtn">Confirmar</button>
                        </div>
                    </div>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $currentFolderId ? '&folder=' . $currentFolderId : ''; ?>">« Anterior</a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $currentFolderId ? '&folder=' . $currentFolderId : ''; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $currentFolderId ? '&folder=' . $currentFolderId : ''; ?>">Siguiente »</a>
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
                <button type="button" onclick="hideCreateFolderModal()" class="btn btn-outline btn-outline--on-dark">Cancelar</button>
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

// Bulk selection logic for users
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAllUser');
    const fileItems = Array.from(document.querySelectorAll('.user-file-item'));
    const bulkBar = document.getElementById('userBulkActionsBar');
    const selectedCount = document.getElementById('userSelectedCount');
    const selectAllMatchingBtn = document.getElementById('selectAllMatchingBtn');
    const userSelectAllHidden = document.getElementById('userSelectAll');

    function updateUserBulkBar() {
        const checked = document.querySelectorAll('.user-file-item:checked').length;
        selectedCount.textContent = checked;
        if (checked > 0) bulkBar.classList.add('show'); else bulkBar.classList.remove('show');
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            fileItems.forEach(cb => cb.checked = this.checked);
            userSelectAllHidden.value = '0';
            updateUserBulkBar();
        });
    }

    fileItems.forEach(cb => cb.addEventListener('change', function() {
        updateUserBulkBar();
        const all = fileItems.every(i => i.checked);
        const some = fileItems.some(i => i.checked);
        if (selectAll) {
            selectAll.checked = all;
            selectAll.indeterminate = some && !all;
        }
    }));

    if (selectAllMatchingBtn) {
        selectAllMatchingBtn.addEventListener('click', function() {
            // Mark select_all flag and show bulk bar with total count
            userSelectAllHidden.value = '1';
            // visually check current page items
            fileItems.forEach(cb => cb.checked = true);
            selectedCount.textContent = '<?php echo $totalFiles; ?>';
            bulkBar.classList.add('show');
        });
    }
});

function clearUserSelection() {
    document.querySelectorAll('.user-file-item').forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('selectAllUser');
    if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
    document.getElementById('userSelectAll').value = '0';
    document.getElementById('userBulkActionsBar').classList.remove('show');
}

function confirmUserBulkAction(action) {
    let count = 0;
    const selectAllFlag = document.getElementById('userSelectAll').value === '1';
    if (selectAllFlag) {
        count = <?php echo $totalFiles; ?>;
    } else {
        count = document.querySelectorAll('.user-file-item:checked').length;
    }

    if (count === 0) return alert('No hay elementos seleccionados.');

    // Show modal with count and set confirm button action
    document.getElementById('bulkConfirmCount').textContent = count;
    const confirmBtn = document.getElementById('bulkConfirmBtn');
    // Reset confirm button to default state (danger) and modal title
    confirmBtn.className = 'btn btn-danger';
    confirmBtn.textContent = 'Confirmar';
    document.getElementById('bulkConfirmTitle').textContent = 'Confirmar acción';
    confirmBtn.onclick = function() {
        // Prepare form and submit
        document.getElementById('userBulkAction').value = action;
        const form = document.getElementById('userBulkForm');

        if (!selectAllFlag) {
            // Remove existing dynamic inputs
            document.querySelectorAll('input[name="file_ids[]"][data-dynamic]').forEach(n => n.remove());
            document.querySelectorAll('.user-file-item:checked').forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'file_ids[]';
                input.value = cb.value;
                input.setAttribute('data-dynamic', '1');
                form.appendChild(input);
            });
        }

        form.submit();
    };

    showBulkConfirmModal();
}
// Move functionality removed from user UI; backend still supports move if needed by admin.

function showBulkConfirmModal(mode) {
    document.getElementById('bulkConfirmModal').style.display = 'flex';
}

function hideBulkConfirmModal() {
    document.getElementById('bulkConfirmModal').style.display = 'none';
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
