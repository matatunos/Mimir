<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/User.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();
$fileClass = new File();
$userClass = new User();

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Filters
$search = trim($_GET['search'] ?? '');
$filters = [];
if ($search) {
    $filters['search'] = $search;
}

// Get orphaned files
$orphans = $fileClass->getOrphans($filters, $perPage, $offset);
$totalOrphans = $fileClass->countOrphans($filters);
$totalPages = ceil($totalOrphans / $perPage);

renderPageStart('Archivos Huérfanos', 'users', true);
renderHeader('Archivos Huérfanos', $user);
?>

<style>
.orphan-actions {
    display: flex;
    gap: 0.5rem;
}
.orphan-actions button {
    padding: 0.25rem 0.75rem;
    font-size: 0.875rem;
}
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
}
.modal-content {
    background-color: var(--bg-primary);
    color: var(--text-primary);
    margin: 5% auto;
    padding: 2rem;
    border-radius: 0.5rem;
    max-width: 600px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.3);
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}
.modal-close {
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--text-muted);
}
.user-search-results {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid var(--border-color);
    border-radius: 0.25rem;
    margin-top: 0.5rem;
}
.user-item {
    padding: 0.75rem;
    cursor: pointer;
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s;
}
.user-item:hover {
    background: var(--bg-secondary);
}
.user-item:last-child {
    border-bottom: none;
}
.search-loading {
    text-align: center;
    padding: 1rem;
    color: var(--text-muted);
}
</style>

<div class="content">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-box"></i> Archivos Huérfanos</h1>
            <p style="color: var(--text-muted);">Archivos sin propietario asignado</p>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong><?php echo number_format($totalOrphans); ?></strong> archivos huérfanos
                <?php if ($search): ?>
                    (búsqueda: "<?php echo htmlspecialchars($search); ?>")
                <?php endif; ?>
            </div>
            <div style="display: flex; gap: 0.75rem;">
                <form method="GET" style="display: flex; gap: 0.5rem;">
                    <input type="text" name="search" placeholder="Buscar por nombre..." 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           class="form-control" style="width: 250px;">
                    <button type="submit" class="btn btn-primary">🔍 Buscar</button>
                    <?php if ($search): ?>
                        <a href="<?php echo BASE_URL; ?>/admin/orphan_files.php" class="btn btn-outline">✖ Limpiar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($orphans)): ?>
                <div style="padding: 3rem; text-align: center; color: var(--text-muted);">
                    <?php if ($search): ?>
                        No se encontraron archivos huérfanos con ese criterio
                    <?php else: ?>
                        ✅ No hay archivos huérfanos en este momento
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div style="padding: 1rem; border-bottom: 1px solid var(--border-color); display: flex; gap: 1rem; align-items: center;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; margin: 0;">
                        <input type="checkbox" id="selectAll" onclick="toggleSelectAll()">
                        <span>Seleccionar todos</span>
                    </label>
                    <button class="btn btn-primary btn-sm" onclick="bulkAssign()" id="bulkAssignBtn" disabled>
                        👤 Asignar seleccionados
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="bulkDelete()" id="bulkDeleteBtn" disabled>
                        🗑️ Eliminar seleccionados
                    </button>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;"></th>
                            <th>Archivo</th>
                            <th style="width: 120px;">Tamaño</th>
                            <th style="width: 180px;">Subido</th>
                            <th style="width: 200px; text-align: right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orphans as $file): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="file-select" value="<?php echo $file['id']; ?>" 
                                           onchange="updateBulkActions()">
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <span style="font-size: 1.5rem;"><?php echo getFileIcon($file['original_name'] ?? ''); ?></span>
                                        <div>
                                            <strong><?php echo htmlspecialchars($file['original_name'] ?? 'Sin nombre'); ?></strong>
                                            <?php if (!empty($file['stored_name']) && $file['stored_name'] !== $file['original_name']): ?>
                                                <br><small style="color: var(--text-muted);">Almacenado: <?php echo htmlspecialchars($file['stored_name']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo formatFileSize($file['file_size'] ?? 0); ?></td>
                                <td>
                                    <?php 
                                    if (!empty($file['created_at'])) {
                                        $uploadDate = new DateTime($file['created_at']);
                                        echo $uploadDate->format('d/m/Y H:i');
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td style="text-align: right;">
                                    <div class="orphan-actions">
                                        <button class="btn btn-primary btn-sm" 
                                                onclick="showAssignModal(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars($file['original_name'] ?? 'archivo', ENT_QUOTES); ?>')">
                                            👤 Asignar
                                        </button>
                                        <button class="btn btn-danger btn-sm" 
                                                onclick="deleteOrphan(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars($file['original_name'] ?? 'archivo', ENT_QUOTES); ?>')">
                                            🗑️ Eliminar
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" 
                               class="page-link">← Anterior</a>
                        <?php endif; ?>
                        
                        <span class="page-info">Página <?php echo $page; ?> de <?php echo $totalPages; ?></span>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" 
                               class="page-link">Siguiente →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal para asignar usuario -->
<div id="assignModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 style="margin: 0;">Asignar archivo(s) a usuario</h2>
            <span class="modal-close" onclick="closeAssignModal()">&times;</span>
        </div>
        <div>
            <p id="modalFileInfo"><strong>Archivo:</strong> <span id="modalFileName"></span></p>
            <div class="form-group">
                <label>Buscar usuario</label>
                <input type="text" id="userSearch" class="form-control" 
                       placeholder="Escribe nombre, email o usuario..." 
                       oninput="searchUsers()">
            </div>
            <div id="userSearchResults" class="user-search-results" style="display: none;"></div>
        </div>
    </div>
</div>

<script>
let currentFileId = null;
let currentFileIds = [];
let searchTimeout = null;

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.file-select');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
    updateBulkActions();
}

function updateBulkActions() {
    const selected = document.querySelectorAll('.file-select:checked').length;
    const deleteBtn = document.getElementById('bulkDeleteBtn');
    const assignBtn = document.getElementById('bulkAssignBtn');
    
    deleteBtn.disabled = selected === 0;
    assignBtn.disabled = selected === 0;
    
    deleteBtn.innerHTML = selected > 0 ? `🗑️ Eliminar ${selected} archivo(s)` : '🗑️ Eliminar seleccionados';
    assignBtn.innerHTML = selected > 0 ? `👤 Asignar ${selected} archivo(s)` : '👤 Asignar seleccionados';
}

function showAssignModal(fileId, fileName) {
    currentFileId = fileId;
    currentFileIds = [fileId];
    document.getElementById('modalFileInfo').style.display = 'block';
    document.getElementById('modalFileName').textContent = fileName;
    document.getElementById('assignModal').style.display = 'block';
    document.getElementById('userSearch').value = '';
    document.getElementById('userSearchResults').style.display = 'none';
}

function bulkAssign() {
    const selected = document.querySelectorAll('.file-select:checked');
    if (selected.length === 0) return;
    
    currentFileId = null;
    currentFileIds = Array.from(selected).map(cb => parseInt(cb.value));
    
    document.getElementById('modalFileInfo').style.display = 'block';
    document.getElementById('modalFileName').textContent = `${selected.length} archivo(s) seleccionado(s)`;
    document.getElementById('assignModal').style.display = 'block';
    document.getElementById('userSearch').value = '';
    document.getElementById('userSearchResults').style.display = 'none';
}

function closeAssignModal() {
    document.getElementById('assignModal').style.display = 'none';
    currentFileId = null;
    currentFileIds = [];
}

function searchUsers() {
    const query = document.getElementById('userSearch').value.trim();
    const resultsDiv = document.getElementById('userSearchResults');
    
    if (searchTimeout) clearTimeout(searchTimeout);
    
    if (query.length < 2) {
        resultsDiv.style.display = 'none';
        return;
    }
    
    resultsDiv.innerHTML = '<div class="search-loading">Buscando...</div>';
    resultsDiv.style.display = 'block';
    
    searchTimeout = setTimeout(() => {
        fetch(`<?php echo BASE_URL; ?>/admin/orphan_files_api.php?action=search_users&q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.users.length > 0) {
                    resultsDiv.innerHTML = data.users.map(user => `
                        <div class="user-item" onclick="assignToUser(${user.id})">
                            <strong>${user.username}</strong>
                            ${user.full_name ? `<br><small>${user.full_name}</small>` : ''}
                            ${user.email ? `<br><small style="color: var(--text-muted);">${user.email}</small>` : ''}
                        </div>
                    `).join('');
                } else {
                    resultsDiv.innerHTML = '<div class="search-loading">No se encontraron usuarios</div>';
                }
            })
            .catch(error => {
                resultsDiv.innerHTML = '<div class="search-loading" style="color: var(--danger);">Error en la búsqueda</div>';
                console.error('Search error:', error);
            });
    }, 300);
}

function assignToUser(userId) {
    if (!currentFileId && currentFileIds.length === 0) return;
    
    const fileIds = currentFileIds.length > 0 ? currentFileIds : [currentFileId];
    const formData = new FormData();
    formData.append('action', 'assign');
    formData.append('file_ids', JSON.stringify(fileIds));
    formData.append('user_id', userId);
    
    fetch('<?php echo BASE_URL; ?>/admin/orphan_files_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const count = fileIds.length;
            const message = count > 1 ? `${count} archivos asignados correctamente` : 'Archivo asignado correctamente';
            window.location.href = '?success=' + encodeURIComponent(message);
        } else {
            alert('Error: ' + (data.message || 'No se pudo asignar el archivo'));
        }
    })
    .catch(error => {
        alert('Error al asignar el archivo');
        console.error('Assign error:', error);
    });
}

function deleteOrphan(fileId, fileName) {
    if (!confirm(`¿Seguro que quieres eliminar "${fileName}"? Esta acción no se puede deshacer.`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('file_id', fileId);
    
    fetch('<?php echo BASE_URL; ?>/admin/orphan_files_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = '?success=' + encodeURIComponent('Archivo eliminado correctamente');
        } else {
            alert('Error: ' + (data.message || 'No se pudo eliminar el archivo'));
        }
    })
    .catch(error => {
        alert('Error al eliminar el archivo');
        console.error('Delete error:', error);
    });
}

function bulkDelete() {
    const selected = Array.from(document.querySelectorAll('.file-select:checked')).map(cb => cb.value);
    
    if (selected.length === 0) return;
    
    if (!confirm(`¿Seguro que quieres eliminar ${selected.length} archivo(s)? Esta acción no se puede deshacer.`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'bulk_delete');
    formData.append('file_ids', JSON.stringify(selected));
    
    fetch('<?php echo BASE_URL; ?>/admin/orphan_files_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = '?success=' + encodeURIComponent(`${data.deleted} archivo(s) eliminado(s) correctamente`);
        } else {
            alert('Error: ' + (data.message || 'No se pudieron eliminar los archivos'));
        }
    })
    .catch(error => {
        alert('Error al eliminar los archivos');
        console.error('Bulk delete error:', error);
    });
}

// Cerrar modal al hacer clic fuera
window.onclick = function(event) {
    const modal = document.getElementById('assignModal');
    if (event.target === modal) {
        closeAssignModal();
    }
}
</script>

<?php
function getFileIcon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'pdf' => '<i class="fas fa-file"></i>',
        'doc' => '📝', 'docx' => '📝',
        'xls' => '<i class="fas fa-chart-bar"></i>', 'xlsx' => '<i class="fas fa-chart-bar"></i>',
        'ppt' => '<i class="fas fa-chart-bar"></i>', 'pptx' => '<i class="fas fa-chart-bar"></i>',
        'jpg' => '🖼️', 'jpeg' => '🖼️', 'png' => '🖼️', 'gif' => '🖼️',
        'mp4' => '🎥', 'avi' => '🎥', 'mov' => '🎥',
        'mp3' => '🎵', 'wav' => '🎵',
        'zip' => '<i class="fas fa-box"></i>', 'rar' => '<i class="fas fa-box"></i>', '7z' => '<i class="fas fa-box"></i>',
        'txt' => '📃',
    ];
    return $icons[$ext] ?? '<i class="fas fa-file"></i>';
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

renderPageEnd();
?>
