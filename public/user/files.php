<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/User.php';
// Invalidate opcode cache for File class to pick up recent changes
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__DIR__ . '/../../classes/File.php', true);
}
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
    // Include expired files for the owner so "Ver mis archivos" shows all their items
    $all = $fileClass->getFolderContents($user['id'], $currentFolderId, true);
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

// Show detailed upload results from previous upload (if any)
$uploadResults = [];
if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['upload_results'])) {
    $uploadResults = $_SESSION['upload_results'];
    unset($_SESSION['upload_results']);
}

$isAdmin = ($user['role'] === 'admin');
renderPageStart(t('my_files_section'), 'user-files', $isAdmin);
renderHeader(t('my_files_section'), $user);
?>

<style>
/* Floating bulk actions bar (similar to admin) */
.bulk-actions-bar {
    position: fixed;
    bottom: 1.25rem;
    left: 50%;
    transform: translateX(-50%);
    background: linear-gradient(135deg, #4a90e2, #50c878);
    color: white;
    padding: 0.45rem 0.75rem;
    border-radius: 999px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.18);
    display: none;
    z-index: 1000;
    animation: slideUp 0.18s ease-out;
    max-width: calc(100% - 2rem);
    white-space: nowrap;
    overflow: hidden;
    align-items: center;
}
@keyframes slideUp {
    from { transform: translateX(-50%) translateY(100px); opacity: 0; }
    to { transform: translateX(-50%) translateY(0); opacity: 1; }
}
.bulk-actions-bar.show { display: flex; align-items: center; gap: 0.5rem; }
.bulk-actions-bar .btn { padding: 0.35rem 0.5rem; font-size: 0.9rem; min-width: 2.2rem; }
.file-checkbox { width: 16px; height: 16px; cursor: pointer; }
/* Grid view */
.file-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 1rem; }
.file-grid .grid-item { background: var(--bg-secondary); border:1px solid var(--border-color); padding:0.75rem; border-radius:8px; text-align:center; display:flex; flex-direction:column; align-items:center; gap:0.5rem; }
.file-grid .grid-thumb { width:100%; height:110px; display:flex; align-items:center; justify-content:center; background:#f6f6f6; border-radius:6px; overflow:hidden; }
.file-grid .grid-thumb img { width:100%; height:100%; object-fit:cover; }
.file-grid .grid-thumb i { font-size:2.2rem; color:var(--text-muted); }
.file-grid .grid-label { font-size:0.9rem; word-break:break-word; }
.view-icons .file-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
.view-icons-xl .file-grid { grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
.view-icons-xl .file-grid .grid-thumb { height:200px; }
.grid-thumb.clickable { cursor: pointer; }
/* Folder color in icon views (match detailed view) */
.view-icons .file-grid .folder-thumb i,
.view-icons .file-grid .grid-item.folder .grid-thumb i {
    color: #e9b149;
}
.view-icons-xl .file-grid .folder-thumb i,
.view-icons-xl .file-grid .grid-item.folder .grid-thumb i {
    color: #e9b149;
    font-size: 2.6rem;
}
/* Pagination styles */
.pagination { display:flex; gap:0.5rem; justify-content:center; align-items:center; padding:0.75rem 0; }
.pagination a { display:inline-block; padding:0.45rem 0.75rem; border-radius:6px; color:var(--text-main); text-decoration:none; border:1px solid transparent; }
.pagination a:hover { background:var(--bg-secondary); }
.pagination a.active { background:var(--brand-primary); color:#fff; border-color:rgba(0,0,0,0.05); box-shadow:0 2px 8px rgba(0,0,0,0.06); font-weight:700; }
.pagination .page-info { margin-left:0.75rem; color:var(--text-muted); font-size:0.95rem; }
</style>

<div class="content">
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <script>
    (function(){
        function makeInteractive(tableId, searchId, statusId){
            var table = document.getElementById(tableId);
            if (!table) return;
            var tbody = table.tBodies[0];
            var rows = Array.prototype.slice.call(tbody.rows);
            var headers = table.tHead.rows[0].cells;
            for (let i=0;i<headers.length;i++){
                headers[i].style.cursor='pointer';
                headers[i].addEventListener('click', function(){
                    var asc = this.getAttribute('data-asc') !== '1';
                    rows.sort(function(a,b){
                        var A = a.cells[i].innerText.trim().toLowerCase();
                        var B = b.cells[i].innerText.trim().toLowerCase();
                        return A === B ? 0 : (A > B ? 1 : -1);
                    });
                    if (!asc) rows.reverse();
                    rows.forEach(function(r){ tbody.appendChild(r); });
                    this.setAttribute('data-asc', asc ? '1' : '0');
                });
            }
            var search = document.getElementById(searchId);
            var status = document.getElementById(statusId);
            function applyFilter(){
                var q = (search && search.value || '').toLowerCase();
                var s = (status && status.value) || 'all';
                rows.forEach(function(r){
                    var name = r.cells[0].innerText.toLowerCase();
                    var st = r.cells[1].innerText.toLowerCase().trim();
                    var ok = (q === '' || name.indexOf(q) !== -1) && (s === 'all' || st === s);
                    r.style.display = ok ? '' : 'none';
                });
            }
            if (search) search.addEventListener('input', applyFilter);
            if (status) status.addEventListener('change', applyFilter);
        }
        makeInteractive('filesUploadResultsTable','filesResultsSearch','filesResultsStatus');
    })();
    </script>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (!empty($uploadResults)): ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><strong>Resultado de la subida</strong></div>
            <div class="card-body">
                <div style="margin-bottom:0.5rem;">
                    <strong><?php echo count(array_filter($uploadResults, function($r){ return $r['status']==='ok'; })); ?></strong> archivos subidos correctamente,
                    <strong><?php echo count(array_filter($uploadResults, function($r){ return $r['status']!=='ok'; })); ?></strong> fallaron.
                </div>
                <div style="max-height:220px; overflow:auto;">
                    <div style="display:flex; justify-content:space-between; gap:0.5rem; margin-bottom:0.5rem; align-items:center;">
                        <div style="display:flex; gap:0.5rem; align-items:center;">
                            <input id="filesResultsSearch" type="text" placeholder="<?php echo t('search'); ?>" class="form-control" style="padding:0.4rem 0.6rem; width:220px;">
                            <select id="filesResultsStatus" class="form-control" style="padding:0.35rem 0.5rem; width:160px;">
                                <option value="all">Todos</option>
                                <option value="ok">OK</option>
                                <option value="error">Error</option>
                            </select>
                        </div>
                        <div style="font-size:0.9rem; color:var(--text-muted);"><?php echo htmlspecialchars(t('click_headers_to_sort')); ?></div>
                    </div>
                    <table id="filesUploadResultsTable" class="table table-sm">
                        <thead>
                            <tr><th><?php echo htmlspecialchars(t('table_name')); ?></th><th><?php echo htmlspecialchars(t('table_status') ?? 'Estado'); ?></th><th><?php echo htmlspecialchars(t('reason') ?? 'Motivo'); ?></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($uploadResults as $res): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($res['name']); ?></td>
                                    <td><?php echo $res['status'] === 'ok' ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">Error</span>'; ?></td>
                                    <td><?php echo $res['status'] === 'ok' ? '-' : htmlspecialchars($res['reason']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

            <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
        <div class="card-header" style="padding: 1.5rem;">
            <h2 class="card-title" style="font-weight: 700; font-size: 1.5rem; margin: 0;"><i class="fas fa-folder"></i> <?php echo htmlspecialchars(t('my_files_section')); ?> (<?php echo $totalFiles; ?>)</h2>
            <div style="display: flex; gap: 0.5rem;">
                <button onclick="showCreateFolderModal()" class="btn btn-primary" style="background: white; color: #4a90e2; border: none; font-weight: 600;">
                    <i class="fas fa-folder-plus"></i> <?php echo htmlspecialchars(t('create') . ' ' . t('folder')); ?>
                </button>
                <a href="<?php echo BASE_URL; ?>/user/upload.php<?php echo $currentFolderId ? '?folder=' . $currentFolderId : ''; ?>" class="btn btn-success" style="background: white; color: #50c878; border: none; font-weight: 600;">
                    <?php echo htmlspecialchars(t('upload_button')); ?>
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
                    <input type="text" name="search" class="form-control" placeholder="<?php echo htmlspecialchars(t('search_placeholder')); ?>" value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('search')); ?></button>
                    <?php if ($search): ?>
                        <a href="<?php echo BASE_URL; ?>/user/files.php" class="btn btn-outline btn-outline--on-dark"><?php echo htmlspecialchars(t('clear')); ?></a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- View mode selector -->
            <div style="display:flex; justify-content:flex-end; align-items:center; gap:0.5rem; margin-bottom:0.75rem;">
                <label style="font-size:0.9rem; color:var(--text-muted);">Vista:</label>
                <select id="viewModeSelect" class="form-control" style="width:220px;">
                    <option value="detailed"><?php echo htmlspecialchars(t('view_detailed') ?? 'Detallada'); ?></option>
                    <option value="icons"><?php echo htmlspecialchars(t('view_icons_large') ?? 'Iconos grandes'); ?></option>
                    <option value="icons-xl"><?php echo htmlspecialchars(t('view_icons_xl') ?? 'Iconos muy grandes'); ?></option>
                </select>
            </div>

            <?php if (empty($files)): ?>
                <div style="text-align: center; padding: 4rem; background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%); border-radius: 1rem; border: 2px dashed var(--border-color);">
                    <div style="font-size: 5rem; margin-bottom: 1.5rem; opacity: 0.3;"><i class="fas fa-folder"></i></div>
                    <h3 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem;"><?php echo htmlspecialchars(t('no_files_yet')); ?></h3>
                    <p style="color: var(--text-muted); margin-bottom: 2rem;"><?php echo htmlspecialchars(t('start_by_uploading_first')); ?></p>
                    <a href="<?php echo BASE_URL; ?>/user/upload.php<?php echo $currentFolderId ? '?folder=' . $currentFolderId : ''; ?>" class="btn btn-primary" style="padding: 0.75rem 2rem; font-size: 1.0625rem; font-weight: 600; box-shadow: 0 4px 12px rgba(74, 144, 226, 0.3);"><i class="fas fa-upload"></i> <?php echo htmlspecialchars(t('upload_first_file')); ?></a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                                    <tr>
                                                        <th style="width:40px;"><input type="checkbox" id="selectAllUser" class="file-checkbox"></th>
                                                        <th><?php echo htmlspecialchars(t('table_name')); ?></th>
                                                        <th><?php echo htmlspecialchars(t('table_size')); ?></th>
                                                        <th><?php echo htmlspecialchars(t('table_shared')); ?></th>
                                                        <th><?php echo htmlspecialchars(t('table_date')); ?></th>
                                                        <th><?php echo htmlspecialchars(t('table_actions')); ?></th>
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
                                                    <?php echo $file['file_count']; ?> <?php echo htmlspecialchars(t('files')); ?>, <?php echo $file['subfolder_count']; ?> <?php echo htmlspecialchars(t('folder')); ?>
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
                                        <span class="badge badge-success"><i class="fas fa-check"></i> <?php echo htmlspecialchars(t('shared_with_count', [$file['share_count']])); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary"><?php echo htmlspecialchars(t('not_shared')); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($file['created_at'])); ?></td>
                                <td>
                                    <div style="display: flex; gap: 0.5rem;">
                                            <?php if ($file['is_folder']): ?>
                                            <a href="<?php echo BASE_URL; ?>/user/files.php?folder=<?php echo $file['id']; ?>" class="btn btn-sm btn-primary" title="<?php echo htmlspecialchars(t('open')); ?>"><i class="fas fa-folder-open"></i></a>
                                            <button onclick="deleteFolder(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>')" class="btn btn-sm btn-danger" title="<?php echo htmlspecialchars(t('delete')); ?>"><i class="fas fa-trash"></i></button>
                                        <?php else: ?>
                                            <a href="<?php echo BASE_URL; ?>/user/download.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-primary" title="<?php echo htmlspecialchars(t('download')); ?>"><i class="fas fa-download"></i></a>
                                            <a href="<?php echo BASE_URL; ?>/user/share.php?file_id=<?php echo $file['id']; ?>" class="btn btn-sm btn-success" title="<?php echo htmlspecialchars(t('share')); ?>"><i class="fas fa-link"></i></a>
                                            <a href="<?php echo BASE_URL; ?>/user/share.php?file_id=<?php echo $file['id']; ?>&amp;gallery=1" class="btn btn-sm btn-warning" title="<?php echo htmlspecialchars(t('publish_to_gallery')); ?>"><i class="fas fa-image"></i></a>
                                            <?php if ($file['is_shared']): ?>
                                                <form method="POST" action="<?php echo BASE_URL; ?>/user/bulk_action.php" style="display:inline; margin:0;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                                                    <input type="hidden" name="file_ids[]" value="<?php echo $file['id']; ?>">
                                                    <input type="hidden" name="action" value="unshare">
                                                    <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm(<?php echo json_encode(t('confirm_disable_shared_file')); ?>)" title="<?php echo t('disable_shared'); ?>"><i class="fas fa-ban"></i></button>
                                                </form>
                                            <?php endif; ?>
                                            <a href="<?php echo BASE_URL; ?>/user/delete.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm(<?php echo json_encode(t('confirm_delete_file')); ?>)" title="<?php echo t('delete'); ?>"><i class="fas fa-trash"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Grid view (thumbnails) -->
                <div id="filesGrid" class="file-grid" style="display:none;">
                    <?php foreach ($files as $file): ?>
                        <div class="grid-item <?php echo $file['is_folder'] ? 'folder' : 'file'; ?>" data-file-id="<?php echo $file['id']; ?>" <?php if ($file['is_folder']): ?>data-folder-id="<?php echo $file['id']; ?>"<?php endif; ?>>
                            <?php if ($file['is_folder']): ?>
                                <div class="grid-thumb folder-thumb" data-folder-id="<?php echo $file['id']; ?>"><i class="fas fa-folder"></i></div>
                                <div class="grid-label"><?php echo htmlspecialchars($file['original_name']); ?></div>
                            <?php else: ?>
                                <?php $isImage = strpos($file['mime_type'], 'image/') === 0; ?>
                                <div class="grid-thumb <?php echo $isImage ? 'clickable' : ''; ?>" <?php if ($isImage): ?>data-preview-url="<?php echo BASE_URL; ?>/user/preview.php?id=<?php echo $file['id']; ?>"<?php endif; ?>>
                                    <?php if ($isImage): ?>
                                        <img src="<?php echo BASE_URL; ?>/user/preview.php?id=<?php echo $file['id']; ?>&thumb=1" alt="<?php echo htmlspecialchars($file['original_name']); ?>">
                                    <?php else: ?>
                                        <i class="fas fa-file"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="grid-label"><?php echo htmlspecialchars($file['original_name']); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
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
                        <span id="userSelectedCount">0</span>
                        <div style="display:inline-flex; align-items:center; gap:0.4rem; margin-left:0.5rem;">
                            <button type="button" class="btn btn-danger" title="Eliminar" onclick="confirmUserBulkAction('delete')">
                                <i class="fas fa-trash"></i>
                            </button>
                            <button type="button" class="btn btn-warning" title="Compartir" onclick="confirmUserBulkAction('share')">
                                <i class="fas fa-share"></i>
                            </button>
                            <button type="button" class="btn btn-primary" title="Descargar" onclick="confirmUserBulkAction('download')">
                                <i class="fas fa-download"></i>
                            </button>
                            <button type="button" class="btn btn-info" title="Desactivar compartidos" onclick="confirmUserBulkAction('unshare')">
                                <i class="fas fa-ban"></i>
                            </button>
                            <button type="button" class="btn btn-secondary" title="<?php echo t('cancel'); ?>" onclick="clearUserSelection()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <button type="button" class="btn btn-outline btn-outline--on-dark" id="selectAllMatchingBtn" style="margin-left:0.5rem; padding:0.35rem 0.6rem; font-size:0.85rem;">
                            <?php echo htmlspecialchars(t('select_all_matching', [$totalFiles])); ?>
                        </button>
                </div>

                <!-- Bulk confirm modal -->
                <div id="bulkConfirmModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center;">
                    <div style="background:var(--bg-main); padding:1.5rem; border-radius:0.75rem; width:90%; max-width:600px;">
                        <h3 id="bulkConfirmTitle">Confirmar acción</h3>
                        <p id="bulkConfirmBody">Se van a procesar <strong id="bulkConfirmCount">0</strong> elementos.</p>
                        <div style="display:flex; gap:0.5rem; justify-content:flex-end; margin-top:1rem;">
                                    <button type="button" class="btn btn-outline btn-outline--on-dark" onclick="hideBulkConfirmModal()"><?php echo htmlspecialchars(t('cancel')); ?></button>
                                    <button type="button" class="btn btn-danger" id="bulkConfirmBtn"><?php echo htmlspecialchars(t('confirm')); ?></button>
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
                    <span class="page-info"><?php echo htmlspecialchars(sprintf('Página %d de %d', $page, $totalPages)); ?></span>
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
                <button type="button" onclick="hideCreateFolderModal()" class="btn btn-outline btn-outline--on-dark"><?php echo t('cancel'); ?></button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> <?php echo t('create'); ?> <?php echo t('folder'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Image preview modal -->
<div id="imagePreviewModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:3000; align-items:center; justify-content:center;">
    <div style="max-width:90%; max-height:90%;">
        <img id="imagePreviewImg" src="" style="max-width:100%; max-height:100%; border-radius:8px; box-shadow:0 8px 32px rgba(0,0,0,0.6);" alt="Preview">
    </div>
    <button onclick="closeImagePreview()" style="position:fixed; top:1rem; right:1rem; background:transparent; border:none; color:white; font-size:1.5rem;">&times;</button>
</div>

<script>
// View mode + thumbnail behavior
document.addEventListener('DOMContentLoaded', function(){
    const select = document.getElementById('viewModeSelect');
    const table = document.querySelector('.table');
    const grid = document.getElementById('filesGrid');
    const body = document.body;
    function applyMode(mode){
        if(mode === 'detailed'){
            table && (table.style.display='table');
            grid && (grid.style.display='none');
            body.classList.remove('view-icons','view-icons-xl');
        } else if(mode === 'icons'){
            table && (table.style.display='none');
            grid && (grid.style.display='grid');
            body.classList.add('view-icons');
            body.classList.remove('view-icons-xl');
        } else if(mode === 'icons-xl'){
            table && (table.style.display='none');
            grid && (grid.style.display='grid');
            body.classList.add('view-icons-xl');
            body.classList.remove('view-icons');
        }
        try { localStorage.setItem('mimir_view_mode','' + mode); } catch(e){}
    }
    const saved = (localStorage.getItem('mimir_view_mode') || 'detailed');
    if(select) select.value = saved;
    applyMode(saved);
    if(select) select.addEventListener('change', function(){ applyMode(this.value); });

    // Thumbnail click -> preview
    document.querySelectorAll('#filesGrid .grid-thumb.clickable').forEach(function(n){
        n.addEventListener('click', function(e){
            e.stopPropagation();
            const url = this.getAttribute('data-preview-url');
            if(!url) return;
            const img = document.getElementById('imagePreviewImg');
            img.src = url;
            document.getElementById('imagePreviewModal').style.display = 'flex';
        });
    });

    // Make folder grid items navigable: click anywhere on the folder item opens it
    document.querySelectorAll('#filesGrid .grid-item.folder').forEach(function(item){
        item.addEventListener('click', function(){
            const folderId = this.getAttribute('data-folder-id') || this.getAttribute('data-file-id');
            if (!folderId) return;
            window.location.href = '<?php echo BASE_URL; ?>/user/files.php?folder=' + encodeURIComponent(folderId);
        });
        // Ensure folder thumb click doesn't conflict
        const thumb = item.querySelector('.grid-thumb');
        if (thumb) thumb.addEventListener('click', function(e){ e.stopPropagation(); window.location.href = '<?php echo BASE_URL; ?>/user/files.php?folder=' + encodeURIComponent(item.getAttribute('data-folder-id')); });
    });
});

function closeImagePreview(){
    document.getElementById('imagePreviewModal').style.display = 'none';
    const img = document.getElementById('imagePreviewImg');
    img.src = '';
}

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

        // Handle non-OK responses gracefully
        if (!response.ok) {
            let text = await response.text();
            // Try to parse JSON fallback
            try {
                const maybeJson = JSON.parse(text);
                alert('Error: ' + (maybeJson.message || JSON.stringify(maybeJson)));
            } catch (e) {
                // Strip HTML tags for a cleaner message
                const stripped = text.replace(/<[^>]*>/g, '').trim();
                alert('Error: ' + (stripped || response.status + ' ' + response.statusText));
            }
            return;
        }

        // Clone the response so we can safely attempt to parse JSON
        const responseClone = response.clone();
        let data;
        try {
            data = await response.json();
        } catch (e) {
            const text = await responseClone.text();
            alert('Error parsing server response: ' + text);
            return;
        }

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

    if (count === 0) return alert(<?php echo json_encode(t('no_items_selected')); ?>);

    // Show modal with count and set confirm button action
    document.getElementById('bulkConfirmCount').textContent = count;
    const confirmBtn = document.getElementById('bulkConfirmBtn');
    // Reset confirm button to default state (danger) and modal title
    confirmBtn.className = 'btn btn-danger';
    confirmBtn.textContent = <?php echo json_encode(t('confirm')); ?>;
    document.getElementById('bulkConfirmTitle').textContent = <?php echo json_encode(t('confirm_action')); ?>;
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

        // Close the confirm modal and hide the bulk actions bar immediately
        try { hideBulkConfirmModal(); } catch (e) {}
        try { document.getElementById('userBulkActionsBar').classList.remove('show'); } catch (e) {}
        try { clearUserSelection(); } catch (e) {}

        // Disable the confirm button to avoid double-submits
        confirmBtn.disabled = true;

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
    if (!confirm(<?php echo json_encode(t('confirm_delete_folder_named')); ?>.replace('%s', folderName))) {
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
        
        // Safely parse JSON, falling back to text if needed
        const deleteClone = response.clone();
        let data;
        try {
            data = await response.json();
            } catch (e) {
            const text = await deleteClone.text();
            alert(<?php echo json_encode(t('error_parsing_response')); ?> + ' ' + text);
            return;
        }

        if (data.success) {
            location.reload();
        } else {
            alert(<?php echo json_encode(t('error_colon')); ?> + ' ' + data.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert(<?php echo json_encode(t('error_deleting_folder')); ?>);
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
