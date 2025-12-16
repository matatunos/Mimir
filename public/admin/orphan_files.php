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

renderPageStart('Archivos Huérfanos', 'orphan_files', true);
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
    background-color: #fff;
    color: #222;
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

.floating-action-bar {
    position: fixed;
    left: 50%;
    transform: translateX(-50%);
    bottom: 1rem;
    z-index: 1000;
    display: none;
}
.floating-action-bar .bar {
    background: #fff;
    border: 1px solid var(--border-color);
    color: var(--text);
    padding: 0.6rem 1rem;
    border-radius: 8px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.12);
    display: flex;
    gap: 0.75rem;
    align-items: center;
    min-width: 320px;
    justify-content: center;
}
.floating-action-bar .bar .btn { font-size: 0.95rem; }
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
                <div id="selectAllNotice" style="display:none; padding:0.5rem 1rem; background:#fffbe6; border:1px solid #ffe58f; color:#ad8b00; margin-bottom:1rem; border-radius:0.25rem;">
                    Se han seleccionado los <span id="selectedVisibleCount"></span> archivos visibles. <button class="btn btn-link" onclick="selectAllFiles()">Seleccionar los <strong><?php echo $totalOrphans; ?></strong> archivos huérfanos</button>
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
                <label>Seleccionar usuario</label>
                <select id="userSelect" class="form-control">
                    <?php foreach ($userClass->getAll(['is_active' => 1], 1000, 0) as $u): ?>
                        <option value="<?php echo $u['id']; ?>">
                            <?php echo htmlspecialchars($u['username']); ?><?php if ($u['full_name']) echo ' - ' . htmlspecialchars($u['full_name']); ?><?php if ($u['email']) echo ' (' . htmlspecialchars($u['email']) . ')'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-top:1rem; display:flex; gap:0.5rem; justify-content:flex-end;">
                <button class="btn btn-outline" onclick="closeAssignModal()">Cancelar</button>
                <button class="btn btn-primary" id="assignConfirmBtn" onclick="confirmAssign()">Asignar</button>
            </div>
        </div>
    </div>
</div>

<!-- Processing overlay (used for long operations like batch reassign/delete) -->
<div id="processingOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.66); z-index:2147483647; align-items:center; justify-content:center;">
    <div style="background:rgba(0,0,0,0.7); color:white; text-align:left; width:720px; max-width:95%; border-radius:8px; padding:1rem;">
        <div style="display:flex; gap:1rem; align-items:center;">
            <div style="font-size:2.25rem;">
                <i class="fas fa-spinner fa-pulse"></i>
            </div>
            <div style="flex:1;">
                <div id="processingMessage" style="font-size:1.05rem; margin-bottom:0.5rem;">Procesando, por favor espere...</div>
                <div style="background: rgba(255,255,255,0.12); border-radius:6px; overflow:hidden; height:14px; position:relative;">
                    <div id="processingBarFill" style="background: linear-gradient(90deg,#4a90e2,#50c878); height:100%; width:0%; transition:width 250ms ease;"></div>
                </div>
                <div id="processingPercent" style="margin-top:6px; font-size:0.9rem; opacity:0.95;">0%</div>
            </div>
            <div style="min-width:120px; text-align:right; font-size:0.95rem; color: #fff; opacity:0.9;">
                <div id="processingMiniStatus">Iniciado</div>
            </div>
        </div>

        <div id="processingLogs" style="margin-top:0.75rem; max-height:180px; overflow:auto; background: rgba(255,255,255,0.04); border-radius:6px; padding:0.5rem; font-size:0.9rem; color:#fff; border:1px solid rgba(255,255,255,0.04);">
            <!-- logs will be appended here -->
        </div>
    </div>
</div>
<style>
    .mimir-spinner { width:40px; height:40px; border:5px solid rgba(255,255,255,0.12); border-top-color:#fff; border-radius:50%; animation:mimir-spin 1s linear infinite; margin-right:0.75rem; }
    @keyframes mimir-spin { to { transform: rotate(360deg); } }
</style>
<div style="display:flex; gap:1rem; align-items:center;">
    <div><div class="mimir-spinner" aria-hidden="true"></div></div>
    <div style="flex:1;">
        <div id="processingMessage" style="font-size:1.05rem; margin-bottom:0.5rem;">Procesando, por favor espere...</div>
        <div style="background: rgba(255,255,255,0.12); border-radius:6px; overflow:hidden; height:14px; position:relative;">
            <div id="processingBarFill" style="background: linear-gradient(90deg,#4a90e2,#50c878); height:100%; width:0%; transition:width 250ms ease;"></div>
        </div>
        <div id="processingPercent" style="margin-top:6px; font-size:0.9rem; opacity:0.95;">0%</div>
    </div>
    <div style="min-width:120px; text-align:right; font-size:0.95rem; color: #fff; opacity:0.9;">
        <div id="processingMiniStatus">Iniciado</div>
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

let allFilesSelected = false;
let allFileIds = <?php echo json_encode(array_column($orphans, 'id')); ?>;
let totalOrphans = <?php echo $totalOrphans; ?>;

function updateBulkActions() {
    const selected = document.querySelectorAll('.file-select:checked').length;
    console.log('[orphan_files] updateBulkActions selected=', selected, 'allFilesSelected=', allFilesSelected);

    const deleteBtn = document.getElementById('bulkDeleteBtn');
    const assignBtn = document.getElementById('bulkAssignBtn');

    if (deleteBtn) deleteBtn.disabled = selected === 0 && !allFilesSelected;
    if (assignBtn) assignBtn.disabled = selected === 0 && !allFilesSelected;

    if (deleteBtn) deleteBtn.innerHTML = (selected > 0 || allFilesSelected) ? `🗑️ Eliminar ${(allFilesSelected ? totalOrphans : selected)} archivo(s)` : '🗑️ Eliminar seleccionados';
    if (assignBtn) assignBtn.innerHTML = (selected > 0 || allFilesSelected) ? `👤 Asignar ${(allFilesSelected ? totalOrphans : selected)} archivo(s)` : '👤 Asignar seleccionados';

    // Mostrar aviso para seleccionar todos (si existe)
    const selectAllNotice = document.getElementById('selectAllNotice');
    const selectedVisibleCount = document.getElementById('selectedVisibleCount');
    if (selectAllNotice && selectedVisibleCount) {
        if (selected === allFileIds.length && totalOrphans > allFileIds.length && !allFilesSelected) {
            selectAllNotice.style.display = 'block';
            selectedVisibleCount.textContent = selected;
        } else {
            selectAllNotice.style.display = 'none';
        }
    }

    // Mostrar u ocultar barra flotante
    toggleFloatingBar(selected);
}

function selectAllFiles() {
    allFilesSelected = true;
    updateBulkActions();
}

function showAssignModal(fileId, fileName) {
    currentFileId = fileId;
    currentFileIds = [fileId];
    document.getElementById('modalFileInfo').style.display = 'block';
    document.getElementById('modalFileName').textContent = fileName;
    document.getElementById('assignModal').style.display = 'block';
}

function bulkAssign() {
    let ids;
    if (allFilesSelected) {
        ids = []; // server will handle 'all' flag
    } else {
        const selected = document.querySelectorAll('.file-select:checked');
        ids = Array.from(selected).map(cb => parseInt(cb.value));
    }
    if (ids.length === 0 && !allFilesSelected) return;
    currentFileId = null;
    currentFileIds = ids;
    document.getElementById('modalFileInfo').style.display = 'block';
    document.getElementById('modalFileName').textContent = `${ids.length} archivo(s) seleccionado(s)`;
    document.getElementById('assignModal').style.display = 'block';
}

function confirmAssign() {
    const sel = document.getElementById('userSelect');
    const userId = parseInt(sel.value);
    if (!userId) { alert('Selecciona un usuario'); return; }
    if (allFilesSelected) {
        const formData = new FormData();
        formData.append('action', 'assign');
        formData.append('all', '1');
        formData.append('search', <?php echo json_encode($search); ?>);
        formData.append('user_id', userId);

        fetch('/admin/orphan_files_api.php', { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(Mimir.parseJsonResponse)
            .then(data => {
                if (data.success) {
                    window.location.href = '?success=' + encodeURIComponent((data.count || 0) + ' archivos asignados correctamente');
                } else {
                    alert('Error: ' + (data.message || 'No se pudo asignar'));
                }
            }).catch(e => { alert('Error al asignar'); console.error(e); });
    } else {
        assignToUser(userId);
    }
}

function closeAssignModal() {
    document.getElementById('assignModal').style.display = 'none';
    currentFileId = null;
    currentFileIds = [];
}

function searchUsers() {
    const searchInput = document.getElementById('userSearch');
    const resultsDiv = document.getElementById('userSearchResults');
    if (!searchInput || !resultsDiv) return; // search UI not present in this modal (we use select)
    const query = searchInput.value.trim();
    
    if (searchTimeout) clearTimeout(searchTimeout);
    
    if (query.length < 2) {
        resultsDiv.style.display = 'none';
        return;
    }
    
    resultsDiv.innerHTML = '<div class="search-loading">Buscando...</div>';
    resultsDiv.style.display = 'block';
    
    searchTimeout = setTimeout(() => {
        fetch(`/admin/orphan_files_api.php?action=search_users&q=${encodeURIComponent(query)}`, { credentials: 'same-origin' })
            .then(Mimir.parseJsonResponse)
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
    // Always process in batches for consistent UX
    const BATCH_SIZE = 50;
    showProcessing('Procesando asignación por lotes...', { clearLogs: true, percent: 0, status: 'Iniciando' });
    let processed = 0;
    let successTotal = 0;
    let failedList = [];

    function processChunk(start) {
        const chunk = fileIds.slice(start, start + BATCH_SIZE);
        updateProcessingProgress((processed / fileIds.length) * 100, `Procesando ${Math.min(start + BATCH_SIZE, fileIds.length)} / ${fileIds.length}`);
        const fd = new FormData();
        fd.append('action', 'assign');
        fd.append('user_id', userId);
        fd.append('file_ids', JSON.stringify(chunk));

        fetch('/admin/orphan_files_api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(Mimir.parseJsonResponse)
            .then(resp => {
                if (resp && resp.success) {
                    successTotal += (resp.count || chunk.length);
                    appendProcessingLog(`Lote: asignados ${resp.count || chunk.length}` + (resp.message ? ` — ${resp.message}` : ''));
                } else {
                    failedList.push(resp ? (resp.message || 'Lote fallido') : 'Lote fallido');
                    appendProcessingLog(`Lote: error — ${resp && resp.message ? resp.message : 'Desconocido'}`);
                }
                processed += chunk.length;
                updateProcessingProgress((processed / fileIds.length) * 100, `Procesado ${processed} / ${fileIds.length}`);
                if (start + BATCH_SIZE < fileIds.length) {
                    setTimeout(() => processChunk(start + BATCH_SIZE), 150);
                } else {
                    updateProcessingProgress(100, 'Finalizado');
                    appendProcessingLog(`Operación finalizada. ${successTotal} asignados.` + (failedList.length ? ` Errores: ${failedList.join('; ')}` : ''));
                    setTimeout(() => { hideProcessing(); window.location.reload(); }, 700);
                }
            })
            .catch(err => {
                appendProcessingLog('Error de red: ' + err.message);
                hideProcessing();
                alert('Error de red durante la asignación: ' + err.message);
            });
    }

    processChunk(0);
}

function deleteOrphan(fileId, fileName) {
    if (!confirm(`¿Seguro que quieres eliminar "${fileName}"? Esta acción no se puede deshacer.`)) return;

    // Always process via batch pipeline for consistent UX (single id becomes single-chunk)
    const ids = [parseInt(fileId)];
    const BATCH_SIZE = 50;
    showProcessing('Eliminando archivo...', { clearLogs: true, percent: 0, status: 'Iniciando' });
    let processed = 0;
    let deletedTotal = 0;

    function processChunk(start) {
        const chunk = ids.slice(start, start + BATCH_SIZE);
        updateProcessingProgress((processed / ids.length) * 100, `Eliminando ${Math.min(start + BATCH_SIZE, ids.length)} / ${ids.length}`);
        const fd = new FormData();
        fd.append('action', 'bulk_delete');
        fd.append('file_ids', JSON.stringify(chunk));

        fetch('/admin/orphan_files_api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(Mimir.parseJsonResponse)
            .then(resp => {
                if (resp && resp.success) {
                    deletedTotal += (resp.deleted || chunk.length);
                    appendProcessingLog(`Lote eliminado: ${resp.deleted || chunk.length}`);
                } else {
                    appendProcessingLog('Lote con error: ' + (resp && resp.message ? resp.message : 'Desconocido'));
                }
                processed += chunk.length;
                updateProcessingProgress((processed / ids.length) * 100, `Procesado ${processed} / ${ids.length}`);
                if (start + BATCH_SIZE < ids.length) {
                    setTimeout(() => processChunk(start + BATCH_SIZE), 150);
                } else {
                    updateProcessingProgress(100, 'Finalizado');
                    appendProcessingLog(`Eliminación finalizada. ${deletedTotal} eliminados.`);
                    setTimeout(()=> { hideProcessing(); window.location.reload(); }, 700);
                }
            }).catch(err => { hideProcessing(); appendProcessingLog('Error de red: ' + err.message); alert('Error de red: ' + err.message); });
    }

    processChunk(0);
}

function bulkDelete() {
    let selected = Array.from(document.querySelectorAll('.file-select:checked')).map(cb => parseInt(cb.value));

    if (!allFilesSelected && selected.length === 0) return;

    const count = allFilesSelected ? totalOrphans : selected.length;
    if (!confirm(`¿Seguro que quieres eliminar ${count} archivo(s)? Esta acción no se puede deshacer.`)) return;
    // Always process via batching pipeline (for select-all fetch list, otherwise use selected ids)
    const BATCH_SIZE = 50;
    if (allFilesSelected) {
        showProcessing('Obteniendo lista de archivos para eliminar...', { clearLogs: true, percent: 0, status: 'Obteniendo lista' });
        const filters = <?php echo json_encode($search); ?> ? ('search=' + encodeURIComponent(<?php echo json_encode($search); ?>)) : '';
        // Use paginated processing for large lists
        const filters = <?php echo json_encode($search); ?> ? ('search=' + encodeURIComponent(<?php echo json_encode($search); ?>)) : '';
        const listUrl = '/admin/orphan_files_api.php?action=list_ids&' + filters;
        showProcessing('Obteniendo y procesando archivos por páginas...', { clearLogs: true, percent: 0, status: 'Iniciando' });
        Mimir.processListIdsInPages(listUrl, 'bulk_delete', 500, 100, {
            onProgress: function(processed, total) { updateProcessingProgress(total ? Math.round((processed/total)*100) : 0, `Procesados ${processed} / ${total||'?'} `); },
            onLog: function(txt) { appendProcessingLog(txt); },
            onError: function(err) { hideProcessing(); console.error('Error obteniendo lista (orphan_files paginado):', err); if (err && (err.code === 'AUTH_REQUIRED')) { Mimir.showAuthBanner('Sesión expirada o no autorizada. Reautentica en otra pestaña y recarga.'); return; } alert('Error obteniendo lista: ' + (err.message || '')); },
            onComplete: function() { hideProcessing(); window.location.reload(); }
        });
        return;
    }

    // Non-select-all path: process selected ids in batches
    if (!selected || selected.length === 0) return;
    showProcessing('Eliminando archivos seleccionados...', { clearLogs: true, percent: 0, status: 'Enviando' });
    processIdsInBatches(selected, 'bulk_delete', BATCH_SIZE, function(){ hideProcessing(); window.location.reload(); });
}

// Helper: process an array of IDs in client-side batches, calling the given action on each chunk
function processIdsInBatches(ids, action, batchSize, onComplete) {
    let processed = 0;
    let successTotal = 0;
    const failedList = [];

    function chunkProcess(start) {
        const chunk = ids.slice(start, start + batchSize);
        updateProcessingProgress((processed / ids.length) * 100, `Procesando ${Math.min(start + batchSize, ids.length)} / ${ids.length}`);
        const fd = new FormData();
        fd.append('action', action);
        fd.append('file_ids', JSON.stringify(chunk));

        fetch('/admin/orphan_files_api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(Mimir.parseJsonResponse)
            .then(resp => {
                if (resp && resp.success) {
                    successTotal += (resp.deleted || resp.count || chunk.length);
                    appendProcessingLog(`Lote procesado: ${chunk.length} elementos` + (resp.message ? ` — ${resp.message}` : ''));
                } else {
                    appendProcessingLog('Lote con error: ' + (resp && resp.message ? resp.message : 'Desconocido'));
                }
                processed += chunk.length;
                updateProcessingProgress((processed / ids.length) * 100, `Procesado ${processed} / ${ids.length}`);
                if (start + batchSize < ids.length) setTimeout(() => chunkProcess(start + batchSize), 150);
                else { updateProcessingProgress(100, 'Finalizado'); appendProcessingLog(`Operación finalizada. Procesados: ${processed}. Éxitos estimados: ${successTotal}`); if (typeof onComplete === 'function') setTimeout(onComplete, 600); }
            }).catch(err => { hideProcessing(); appendProcessingLog('Error de red: ' + err.message); alert('Error de red: ' + err.message); });
    }

    chunkProcess(0);
}

// Cerrar modal al hacer clic fuera
window.onclick = function(event) {
    const modal = document.getElementById('assignModal');
    if (event.target === modal) {
        closeAssignModal();
    }
}

// Mostrar u ocultar barra flotante según selección
function toggleFloatingBar(selectedCount) {
    const bar = document.getElementById('floatingBar');
    if (!bar) return;
    console.log('[orphan_files] toggleFloatingBar selectedCount=', selectedCount, 'allFilesSelected=', allFilesSelected);
    if ((selectedCount > 0) || allFilesSelected) {
        document.getElementById('floatingCount').textContent = (allFilesSelected ? totalOrphans : selectedCount) + ' seleccionado(s)';
        // force visibility and strong styles in case theme overwrote CSS
        try {
            bar.style.setProperty('display', 'block', 'important');
            bar.style.setProperty('left', '50%', 'important');
            bar.style.setProperty('transform', 'translateX(-50%)', 'important');
            bar.style.setProperty('bottom', '1rem', 'important');
            bar.style.setProperty('z-index', '2147483647', 'important');
            const inner = bar.querySelector('.bar');
            if (inner) {
                inner.style.setProperty('display', 'flex', 'important');
                inner.style.setProperty('background', '#fff', 'important');
                inner.style.setProperty('border', '2px solid #d0d0d0', 'important');
                inner.style.setProperty('box-shadow', '0 8px 30px rgba(0,0,0,0.2)', 'important');
                inner.style.setProperty('color', '#111', 'important');
                inner.style.setProperty('padding', '0.6rem 1rem', 'important');
                inner.style.setProperty('border-radius', '8px', 'important');
            }
        } catch (e) {
            // fallback
            bar.style.display = 'block';
        }
    } else {
        try { bar.style.setProperty('display', 'none', 'important'); } catch(e) { bar.style.display = 'none'; }
    }
}

// Inicializar estado cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function(){
    try { updateBulkActions(); } catch(e) { console.warn('[orphan_files] init updateBulkActions failed', e); }
});

// Simple spinner: show/hide overlay and update message. Keep minimal progress/log support.
function showProcessing(msg, options = {}) {
    const overlay = document.getElementById('processingOverlay');
    const msgEl = document.getElementById('processingMessage');
    const logs = document.getElementById('processingLogs');
    if (!overlay) return;
    if (msgEl && msg) msgEl.textContent = msg;
    if (typeof options.clearLogs !== 'undefined' && options.clearLogs && logs) logs.innerHTML = '';
    overlay.style.display = 'flex';
}

function hideProcessing() {
    const overlay = document.getElementById('processingOverlay');
    if (!overlay) return;
    overlay.style.display = 'none';
}

function updateProcessingProgress(percent, statusMessage) {
    const bar = document.getElementById('processingBarFill');
    const percentEl = document.getElementById('processingPercent');
    const mini = document.getElementById('processingMiniStatus');
    if (bar) bar.style.width = Math.max(0, Math.min(100, Math.round(percent))) + '%';
    if (percentEl) percentEl.textContent = Math.max(0, Math.min(100, Math.round(percent))) + '%';
    if (mini && statusMessage) mini.textContent = statusMessage;
}

function appendProcessingLog(text) {
    const logs = document.getElementById('processingLogs');
    if (!logs) { console.log('[orphan_files log]', text); return; }
    const div = document.createElement('div');
    div.textContent = text;
    logs.appendChild(div);
    logs.scrollTop = logs.scrollHeight;
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
