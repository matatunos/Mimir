<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();
$fileClass = new File();
$logger = new Logger();
$db = Database::getInstance()->getConnection();

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && (!empty($_POST['file_ids']) || (isset($_POST['select_all']) && $_POST['select_all'] == '1'))) {
    $action = $_POST['action'];
    $fileIds = is_array($_POST['file_ids'] ?? null) ? array_map('intval', $_POST['file_ids']) : [];

    // If select_all flag is present, build the file ID list according to provided filters
    if (isset($_POST['select_all']) && $_POST['select_all'] == '1') {
        $filters = $_POST['filters'] ?? '';
        $farr = [];
        if ($filters !== '') parse_str($filters, $farr);

        $search_f = $farr['search'] ?? '';
        $filterUser_f = $farr['user'] ?? '';
        $filterShared_f = $farr['shared'] ?? '';
        $filterType_f = $farr['type'] ?? '';

        $whereA = ["1=1"];
        $paramsA = [];
        if ($search_f) {
            $whereA[] = "(f.original_name LIKE ? OR f.description LIKE ?)";
            $paramsA[] = "%$search_f%";
            $paramsA[] = "%$search_f%";
        }
        if ($filterUser_f) {
            $whereA[] = "u.username LIKE ?";
            $paramsA[] = "%$filterUser_f%";
        }
        if ($filterShared_f === 'yes') {
            $whereA[] = "f.is_shared = 1";
        } elseif ($filterShared_f === 'no') {
            $whereA[] = "f.is_shared = 0";
        }
        if ($filterType_f) {
            $whereA[] = "f.mime_type LIKE ?";
            $paramsA[] = "$filterType_f%";
        }

        $whereClauseA = implode(' AND ', $whereA);
        $stmtA = $db->prepare("SELECT f.id FROM files f LEFT JOIN users u ON f.user_id = u.id WHERE $whereClauseA");
        $stmtA->execute($paramsA);
        $rows = $stmtA->fetchAll(PDO::FETCH_ASSOC);
        $fileIds = array_map(function($r){ return intval($r['id']); }, $rows);
    }
    
    // If admin requested a bulk download, prepare and stream a ZIP immediately
    if ($action === 'download') {
        $tmp = sys_get_temp_dir() . '/mimir_admin_bulk_' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE) !== TRUE) {
            header('Location: ' . BASE_URL . '/admin/files.php?error=' . urlencode('No se pudo crear el archivo ZIP temporal'));
            exit;
        }

        $added = 0;
        $namesSeen = [];
        foreach ($fileIds as $fileId) {
            $file = $fileClass->getById($fileId);
            if (!$file) continue;
            if ($file['is_folder']) continue;
            if (!is_file($file['file_path'])) continue;

            $name = $file['original_name'];
            if (isset($namesSeen[$name])) {
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $base = pathinfo($name, PATHINFO_FILENAME);
                $name = $base . '_' . $namesSeen[$name] . ($ext ? '.' . $ext : '');
                $namesSeen[$file['original_name']]++;
            } else {
                $namesSeen[$file['original_name']] = 1;
            }

            $zip->addFile($file['file_path'], $name);
            $added++;
        }

        $zip->close();

        if ($added === 0) {
            @unlink($tmp);
            header('Location: ' . BASE_URL . '/admin/files.php?error=' . urlencode('No se encontraron archivos válidos para descargar'));
            exit;
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="admin_download_' . date('Ymd_His') . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    $success = 0;
    $errors = 0;
    
    try {
        $db->beginTransaction();
        
        foreach ($fileIds as $fileId) {
            $file = $fileClass->getById($fileId);
            if (!$file) {
                $errors++;
                continue;
            }
            
            switch ($action) {
                case 'delete':
                    // Admin may delete files they do not own; do not restrict by admin user id
                    if ($fileClass->delete($fileId)) {
                        $logger->log($user['id'], 'file_delete', 'file', $fileId, 'Admin eliminó archivo: ' . $file['original_name']);
                        $success++;
                    } else {
                        $errors++;
                    }
                    break;
                    
                case 'unshare':
                    $stmt = $db->prepare("UPDATE files SET is_shared = 0 WHERE id = ?");
                    if ($stmt->execute([$fileId])) {
                        // Deactivate all shares
                        $stmt = $db->prepare("UPDATE shares SET is_active = 0 WHERE file_id = ?");
                        $stmt->execute([$fileId]);
                        $logger->log($user['id'], 'file_unshare', 'file', $fileId, 'Admin dejó de compartir archivo: ' . $file['original_name']);
                        $success++;
                    } else {
                        $errors++;
                    }
                    break;
                    
                case 'share':
                    $stmt = $db->prepare("UPDATE files SET is_shared = 1 WHERE id = ?");
                    if ($stmt->execute([$fileId])) {
                        $logger->log($user['id'], 'file_share', 'file', $fileId, 'Admin marcó archivo como compartido: ' . $file['original_name']);
                        $success++;
                    } else {
                        $errors++;
                    }
                    break;
            }
        }
        
        $db->commit();
        $message = "Acción completada: $success exitosos";
        if ($errors > 0) $message .= ", $errors errores";
        header('Location: ' . BASE_URL . '/admin/files.php?success=' . urlencode($message));
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        header('Location: ' . BASE_URL . '/admin/files.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Filters and sorting
$search = $_GET['search'] ?? '';
$filterUser = $_GET['user'] ?? '';
$filterShared = $_GET['shared'] ?? '';
$filterType = $_GET['type'] ?? '';
$sortBy = $_GET['sort'] ?? 'created_at';
$sortOrder = $_GET['order'] ?? 'DESC';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = isset($_GET['per_page']) ? max(10, min(100, intval($_GET['per_page']))) : 25;
$offset = ($page - 1) * $perPage;

// Build query
$where = ["1=1"];
$params = [];

if ($search) {
    $where[] = "(f.original_name LIKE ? OR f.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filterUser) {
    $where[] = "u.username LIKE ?";
    $params[] = "%$filterUser%";
}

if ($filterShared === 'yes') {
    $where[] = "f.is_shared = 1";
} elseif ($filterShared === 'no') {
    $where[] = "f.is_shared = 0";
}

if ($filterType) {
    $where[] = "f.mime_type LIKE ?";
    $params[] = "$filterType%";
}

$whereClause = implode(' AND ', $where);

// Valid sort columns (include shared indicators)
$validSort = ['original_name', 'file_size', 'created_at', 'username', 'mime_type', 'is_shared', 'share_count'];
if (!in_array($sortBy, $validSort)) $sortBy = 'created_at';
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Map sort key to actual ORDER BY expression
if ($sortBy === 'username') {
    $orderColumn = 'u.username';
} elseif ($sortBy === 'share_count') {
    // share_count is an alias in the SELECT, ordering by alias is supported
    $orderColumn = 'share_count';
} else {
    $orderColumn = 'f.' . $sortBy;
}

// Get files
$stmt = $db->prepare("
    SELECT 
        f.*,
        u.username,
        u.full_name,
        (SELECT COUNT(*) FROM shares WHERE file_id = f.id AND is_active = 1) as share_count
    FROM files f
    LEFT JOIN users u ON f.user_id = u.id
    WHERE $whereClause
    ORDER BY " . $orderColumn . " $sortOrder
    LIMIT ? OFFSET ?
");
$params[] = $perPage;
$params[] = $offset;
$stmt->execute($params);
$files = $stmt->fetchAll();

// Count total
$stmt = $db->prepare("
    SELECT COUNT(*) 
    FROM files f
    LEFT JOIN users u ON f.user_id = u.id
    WHERE $whereClause
");
$stmt->execute(array_slice($params, 0, -2));
$totalFiles = $stmt->fetchColumn();
$totalPages = ceil($totalFiles / $perPage);

// Get file type categories for filter
$stmt = $db->query("
    SELECT DISTINCT SUBSTRING_INDEX(mime_type, '/', 1) as category, COUNT(*) as count
    FROM files
    GROUP BY category
    ORDER BY count DESC
");
$fileCategories = $stmt->fetchAll();

// Get users for filter
$stmt = $db->query("
    SELECT DISTINCT u.username, COUNT(f.id) as file_count
    FROM users u
    INNER JOIN files f ON u.id = f.user_id
    GROUP BY u.id
    ORDER BY file_count DESC
    LIMIT 20
");
$users = $stmt->fetchAll();

renderPageStart('Gestión de Archivos', 'files', true);
renderHeader('Gestión de Archivos', $user);
?>

<style>
.filter-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    background: var(--primary);
    color: white;
    border-radius: 1rem;
    font-size: 0.875rem;
    margin-right: 0.5rem;
}
.sort-link {
    color: var(--text-main);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}
.sort-link:hover {
    color: var(--primary);
}
.sort-link.active {
    color: var(--primary);
    font-weight: 700;
}
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
.bulk-actions-bar.show {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.file-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
}
</style>

<div class="content">
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>
    
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 2rem;">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-filter"></i> Filtros y Búsqueda</h3>
        </div>
        <div class="card-body">
            <form method="GET" id="filterForm">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <label>Buscar</label>
                        <input type="text" name="search" class="form-control" placeholder="Nombre o descripción..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div>
                        <label>Usuario</label>
                        <select name="user" class="form-control">
                            <option value="">Todos los usuarios</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo htmlspecialchars($u['username']); ?>" <?php echo $filterUser === $u['username'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($u['username']); ?> (<?php echo $u['file_count']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>¿Compartido?</label>
                        <select name="shared" class="form-control">
                            <option value="">Todos</option>
                            <option value="yes" <?php echo $filterShared === 'yes' ? 'selected' : ''; ?>>Sí</option>
                            <option value="no" <?php echo $filterShared === 'no' ? 'selected' : ''; ?>>No</option>
                        </select>
                    </div>
                    <div>
                        <label>Tipo</label>
                        <select name="type" class="form-control">
                            <option value="">Todos los tipos</option>
                            <?php foreach ($fileCategories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $filterType === $cat['category'] ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($cat['category']); ?> (<?php echo $cat['count']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary">Aplicar Filtros</button>
                    <a href="<?php echo BASE_URL; ?>/admin/files.php" class="btn btn-secondary">Limpiar</a>
                    <div style="margin-left: auto; display: flex; align-items: center; gap: 0.5rem;">
                        <label style="margin: 0; white-space: nowrap;">Por página:</label>
                        <select name="per_page" class="form-control" style="width: auto;" onchange="this.form.submit()">
                            <option value="10" <?php echo $perPage === 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $perPage === 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $perPage === 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $perPage === 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                    </div>
                </div>
            </form>
            
            <?php if ($search || $filterUser || $filterShared || $filterType): ?>
                <div style="margin-top: 1rem;">
                    <strong>Filtros activos:</strong>
                    <?php if ($search): ?>
                        <span class="filter-badge">Búsqueda: <?php echo htmlspecialchars($search); ?></span>
                    <?php endif; ?>
                    <?php if ($filterUser): ?>
                        <span class="filter-badge">Usuario: <?php echo htmlspecialchars($filterUser); ?></span>
                    <?php endif; ?>
                    <?php if ($filterShared): ?>
                        <span class="filter-badge">Compartido: <?php echo $filterShared === 'yes' ? 'Sí' : 'No'; ?></span>
                    <?php endif; ?>
                    <?php if ($filterType): ?>
                        <span class="filter-badge">Tipo: <?php echo htmlspecialchars($filterType); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h2 class="card-title"><i class="fas fa-folder"></i> Archivos del Sistema (<?php echo number_format($totalFiles); ?>)</h2>
            <div>
                <button type="button" class="btn btn-primary" onclick="toggleSelectAll()">
                    <i class="fas fa-check-square"></i> Seleccionar Todo
                </button>
                <button type="button" class="btn btn-outline" onclick="selectAllFiltered()" title="Seleccionar todos los archivos que coinciden con los filtros">
                    <i class="fas fa-check"></i> Seleccionar todos (filtro)
                </button>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($files)): ?>
                <div style="text-align: center; padding: 4rem;">
                    <div style="font-size: 5rem; margin-bottom: 1.5rem; opacity: 0.3;"><i class="fas fa-search"></i></div>
                    <h3>No se encontraron archivos</h3>
                    <p style="color: var(--text-muted);">Intenta ajustar los filtros de búsqueda</p>
                </div>
            <?php else: ?>
                <form method="POST" id="bulkForm">
                    <input type="hidden" name="action" id="bulkAction">
                    <input type="hidden" name="select_all" id="selectAllFlag" value="0">
                    <input type="hidden" name="filters" id="bulkFilters" value="">
                    
                    <div style="overflow-x: auto;">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">
                                        <input type="checkbox" class="file-checkbox" id="selectAll">
                                    </th>
                                    <th>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'original_name', 'order' => $sortBy === 'original_name' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>" 
                                           class="sort-link <?php echo $sortBy === 'original_name' ? 'active' : ''; ?>">
                                            Archivo
                                            <?php if ($sortBy === 'original_name'): ?>
                                                <i class="fas fa-sort-<?php echo $sortOrder === 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'username', 'order' => $sortBy === 'username' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>" 
                                           class="sort-link <?php echo $sortBy === 'username' ? 'active' : ''; ?>">
                                            Propietario
                                            <?php if ($sortBy === 'username'): ?>
                                                <i class="fas fa-sort-<?php echo $sortOrder === 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'mime_type', 'order' => $sortBy === 'mime_type' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>" 
                                           class="sort-link <?php echo $sortBy === 'mime_type' ? 'active' : ''; ?>">
                                            Tipo
                                            <?php if ($sortBy === 'mime_type'): ?>
                                                <i class="fas fa-sort-<?php echo $sortOrder === 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'file_size', 'order' => $sortBy === 'file_size' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>" 
                                           class="sort-link <?php echo $sortBy === 'file_size' ? 'active' : ''; ?>">
                                            Tamaño
                                            <?php if ($sortBy === 'file_size'): ?>
                                                <i class="fas fa-sort-<?php echo $sortOrder === 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'is_shared', 'order' => $sortBy === 'is_shared' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>" 
                                           class="sort-link <?php echo $sortBy === 'is_shared' ? 'active' : ''; ?>">
                                            Compartido
                                            <?php if ($sortBy === 'is_shared'): ?>
                                                <i class="fas fa-sort-<?php echo $sortOrder === 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'created_at', 'order' => $sortBy === 'created_at' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>" 
                                           class="sort-link <?php echo $sortBy === 'created_at' ? 'active' : ''; ?>">
                                            Fecha
                                            <?php if ($sortBy === 'created_at'): ?>
                                                <i class="fas fa-sort-<?php echo $sortOrder === 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th style="width:160px; text-align:right;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($files as $file): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="file_ids[]" value="<?php echo $file['id']; ?>" class="file-checkbox file-item">
                                    </td>
                                    <td>
                                        <div style="max-width: 300px; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($file['original_name']); ?>">
                                            <?php echo htmlspecialchars($file['original_name']); ?>
                                        </div>
                                        <?php if ($file['description']): ?>
                                            <small style="color: var(--text-muted);"><?php echo htmlspecialchars(substr($file['description'], 0, 50)); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($file['username']); ?></td>
                                    <td>
                                        <span style="font-size: 0.875rem; padding: 0.25rem 0.5rem; background: var(--bg-secondary); border-radius: 0.25rem;">
                                            <?php echo htmlspecialchars(explode('/', (string)($file['mime_type'] ?? ''))[0]); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($file['file_size'] / 1024 / 1024, 2); ?> MB</td>
                                    <td>
                                        <?php if ($file['is_shared']): ?>
                                            <span style="color: var(--success);">
                                                ✓ Sí <?php echo $file['share_count'] > 0 ? '(' . $file['share_count'] . ')' : ''; ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space: nowrap;"><?php echo date('d/m/Y H:i', strtotime($file['created_at'])); ?></td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <div style="display:flex; gap:0.35rem; justify-content:flex-end;">
                                            <a href="<?php echo BASE_URL; ?>/admin/download.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-outline" title="Descargar" target="_blank" rel="noopener">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline" onclick="adminToggleShare(<?php echo $file['id']; ?>, <?php echo $file['is_shared'] ? 'false' : 'true'; ?>)" title="<?php echo $file['is_shared'] ? 'Desactivar compartido' : 'Marcar como compartido'; ?>">
                                                <?php echo $file['is_shared'] ? '<i class="fas fa-ban"></i>' : '<i class="fas fa-share"></i>'; ?>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="adminDeleteFile(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>')" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; margin-top: 2rem;">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn btn-secondary">← Anterior</a>
                    <?php endif; ?>
                    
                    <span style="padding: 0.5rem 1rem;">Página <?php echo $page; ?> de <?php echo $totalPages; ?></span>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn btn-secondary">Siguiente →</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Bulk Actions Bar -->
<div class="bulk-actions-bar" id="bulkActionsBar">
    <span id="selectedCount">0</span> archivos seleccionados
    <button type="button" class="btn btn-danger" onclick="confirmBulkAction('delete')">
        <i class="fas fa-trash"></i> Eliminar
    </button>
    <button type="button" class="btn btn-warning" onclick="confirmBulkAction('unshare')">
        <i class="fas fa-ban"></i> Dejar de Compartir
    </button>
    <button type="button" class="btn btn-success" onclick="confirmBulkAction('share')">
        <i class="fas fa-share"></i> Marcar como Compartido
    </button>
    <button type="button" class="btn btn-primary" onclick="confirmBulkAction('download')">
        <i class="fas fa-download"></i> Descargar seleccionados
    </button>
    <button type="button" class="btn btn-info" onclick="openReassignModal()">
        <i class="fas fa-user-edit"></i> Reasignar
    </button>
    <button type="button" class="btn btn-secondary" onclick="clearSelection()">
        <i class="fas fa-times"></i> Cancelar
    </button>
</div>

<!-- Processing overlay -->
<div id="processingOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:2147483647; align-items:center; justify-content:center;">
    <style>
        .mimir-spinner { width:48px; height:48px; border:6px solid rgba(255,255,255,0.16); border-top-color: #fff; border-radius:50%; animation: mimir-spin 1s linear infinite; margin:0 auto 0.5rem; }
        @keyframes mimir-spin { to { transform: rotate(360deg); } }
    </style>
    <div style="background:transparent; color:white; text-align:center;">
        <div class="mimir-spinner" aria-hidden="true"></div>
        <div id="processingMessage" style="font-size:1.125rem;">Procesando, por favor espere...</div>
    </div>
</div>

<script>
// Ensure globals exist early to avoid race conditions if buttons are clicked
var totalFilteredFiles = <?php echo intval($totalFiles); ?>;
if (!window._selectAllFilteredActive) window._selectAllFilteredActive = function(){ return false; };
if (!window._setSelectAllFilteredActive) window._setSelectAllFilteredActive = function(v){};

document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const fileCheckboxes = document.querySelectorAll('.file-item');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const selectedCountSpan = document.getElementById('selectedCount');
    // total number of files matching current filters (populated server-side)
    // (declared earlier to avoid races)
    let selectAllFilteredActive = false;
    
    function updateBulkActionsBar() {
        const checkedCount = document.querySelectorAll('.file-item:checked').length;
        selectedCountSpan.textContent = checkedCount;
        
        if (checkedCount > 0) {
            bulkActionsBar.classList.add('show');
        } else {
            bulkActionsBar.classList.remove('show');
        }
    }
    
    selectAllCheckbox.addEventListener('change', function() {
        fileCheckboxes.forEach(cb => cb.checked = this.checked);
        updateBulkActionsBar();
    });
    
    fileCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            // any manual change cancels the 'select all filtered' mode
            if (window._setSelectAllFilteredActive) window._setSelectAllFilteredActive(false);
            updateBulkActionsBar();
            
            // Update select all checkbox state
            const allChecked = Array.from(fileCheckboxes).every(cb => cb.checked);
            const someChecked = Array.from(fileCheckboxes).some(cb => cb.checked);
            selectAllCheckbox.checked = allChecked;
            selectAllCheckbox.indeterminate = someChecked && !allChecked;
        });
    });
    // expose selectAllFilteredActive to global scope
    window._selectAllFilteredActive = function() { return selectAllFilteredActive; };
    window._setSelectAllFilteredActive = function(v) { selectAllFilteredActive = !!v; };
});

// Simple spinner: show/hide overlay and update message. Keep minimal progress/log support.
function showProcessing(msg, options = {}) {
    const overlay = document.getElementById('processingOverlay');
    const msgEl = document.getElementById('processingMessage');
    if (!overlay) return;
    if (msgEl && msg) msgEl.textContent = msg;
    // clear logs if present
    const logs = document.getElementById('processingLogs');
    if (options.clearLogs && logs) logs.innerHTML = '';
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
    if (bar) bar.style.width = Math.max(0, Math.min(100, Math.round(percent))) + '%';
    if (percentEl) percentEl.textContent = Math.max(0, Math.min(100, Math.round(percent))) + '%';
}

function appendProcessingLog(text) { const logs = document.getElementById('processingLogs'); if (!logs) { console.log('[files log]', text); return; } const div = document.createElement('div'); div.textContent = text; logs.appendChild(div); logs.scrollTop = logs.scrollHeight; }

function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAll');
    selectAllCheckbox.checked = !selectAllCheckbox.checked;
    selectAllCheckbox.dispatchEvent(new Event('change'));
}

function selectAllFiltered() {
    // mark that user wants to select all files matching filters
    // check the visible checkboxes too for visual feedback
    document.querySelectorAll('.file-item').forEach(cb => cb.checked = true);
    document.getElementById('selectAll').checked = true;
    window._setSelectAllFilteredActive(true);
    document.getElementById('selectedCount').textContent = totalFilteredFiles;
    document.getElementById('bulkActionsBar').classList.add('show');
}

function clearSelection() {
    document.querySelectorAll('.file-item').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    document.getElementById('bulkActionsBar').classList.remove('show');
    if (window._setSelectAllFilteredActive) window._setSelectAllFilteredActive(false);
    document.getElementById('selectedCount').textContent = '0';
}

function confirmBulkAction(action) {
    const pageCheckedCount = document.querySelectorAll('.file-item:checked').length;
    const count = (window._selectAllFilteredActive && window._selectAllFilteredActive()) ? totalFilteredFiles : pageCheckedCount;
    let message = '';
    
    switch(action) {
        case 'delete':
            message = `¿Estás seguro de eliminar ${count} archivo(s)? Esta acción no se puede deshacer.`;
            break;
        case 'unshare':
            message = `¿Dejar de compartir ${count} archivo(s)?`;
            break;
        case 'share':
            message = `¿Marcar ${count} archivo(s) como compartidos?`;
            break;
        case 'download':
            message = `¿Descargar ${count} archivo(s)?`;
            break;
    }
    
    if (confirm(message)) {
        // show processing overlay while the form posts and server works
        showProcessing('Ejecutando acción, por favor espere...');
        // If selecting all filtered, set hidden input with filters
        const bulkForm = document.getElementById('bulkForm');
        const selectAllFlag = document.getElementById('selectAllFlag');
        const filtersInput = document.getElementById('bulkFilters');
        if (window._selectAllFilteredActive && window._selectAllFilteredActive()) {
            selectAllFlag.value = '1';
            // include current query string (without leading ?)
            filtersInput.value = window.location.search.length ? window.location.search.substring(1) : '';
            // remove per-page file_ids inputs to avoid confusion
            const existing = bulkForm.querySelectorAll('input[name="file_ids[]"]');
            existing.forEach(e => e.remove());
        } else {
            selectAllFlag.value = '0';
            filtersInput.value = '';
        }
        document.getElementById('bulkAction').value = action;
        // hide the bulk actions bar and clear selection so UI doesn't remain selected
        try { document.getElementById('bulkActionsBar').classList.remove('show'); } catch (e) {}
        try { clearSelection(); } catch (e) {}
        document.getElementById('bulkForm').submit();
    }
}

// Admin single-file actions using the existing bulk form
function adminToggleShare(fileId, makeShared) {
    if (!confirm(makeShared ? '¿Marcar este archivo como compartido?' : '¿Quitar compartición de este archivo?')) return;
    const form = document.getElementById('bulkForm');
    const input = document.getElementById('bulkAction');
    // Clear existing file_ids inputs
    const existing = form.querySelectorAll('input[name="file_ids[]"]');
    existing.forEach(e => e.remove());
    const hid = document.createElement('input');
    hid.type = 'hidden'; hid.name = 'file_ids[]'; hid.value = fileId; form.appendChild(hid);
    // ensure select_all flag is cleared
    const selectAllFlag = document.getElementById('selectAllFlag'); if (selectAllFlag) selectAllFlag.value = '0';
    const filtersInput = document.getElementById('bulkFilters'); if (filtersInput) filtersInput.value = '';
    input.value = makeShared ? 'share' : 'unshare';
    form.submit();
}

function adminDeleteFile(fileId, fileName) {
    if (!confirm(`¿Eliminar "${fileName}"? Esta acción no se puede deshacer.`)) return;
    const form = document.getElementById('bulkForm');
    const input = document.getElementById('bulkAction');
    const existing = form.querySelectorAll('input[name="file_ids[]"]');
    existing.forEach(e => e.remove());
    const hid = document.createElement('input');
    hid.type = 'hidden'; hid.name = 'file_ids[]'; hid.value = fileId; form.appendChild(hid);
    // ensure select_all flag is cleared
    const selectAllFlag = document.getElementById('selectAllFlag'); if (selectAllFlag) selectAllFlag.value = '0';
    const filtersInput = document.getElementById('bulkFilters'); if (filtersInput) filtersInput.value = '';
    input.value = 'delete';
    form.submit();
}
</script>

<!-- Reassign Modal -->
<div id="reassignModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center;">
    <div id="reassignModalContent" style="background:white; width:520px; max-width:95%; border-radius:8px; padding:1rem; box-shadow:0 8px 32px rgba(0,0,0,0.3); position:relative; z-index:2147483647; pointer-events:auto;">
        <h3 style="margin-top:0;">Reasignar archivos</h3>
        <p id="reassignCount">0 archivos seleccionados</p>
        <div style="margin-bottom:0.5rem;">
            <label>Buscar usuario destino</label>
            <input type="search" id="reassignUserSearch" class="form-control" placeholder="Escribe nombre de usuario o correo..." autocomplete="off">
            <div id="reassignUserResults" style="max-height:200px; overflow:auto; margin-top:0.5rem;"></div>
        </div>
        <div style="display:flex; gap:0.5rem; justify-content:flex-end; margin-top:1rem;">
            <button type="button" class="btn btn-secondary" onclick="closeReassignModal()">Cancelar</button>
            <button type="button" class="btn btn-primary" id="reassignConfirmBtn" onclick="confirmReassign()" disabled>Reasignar</button>
        </div>
    </div>
</div>

<script>
let reassignSelectedFiles = [];
let reassignSelectedUserId = 0;
let reassignUseSelectAll = false;

function openReassignModal() {
    const checked = Array.from(document.querySelectorAll('.file-item:checked')).map(cb => cb.value);
    // If select-all-filtered mode active, use that
    if (window._selectAllFilteredActive && window._selectAllFilteredActive()) {
        reassignUseSelectAll = true;
        reassignSelectedFiles = [];
        document.getElementById('reassignCount').textContent = `${totalFilteredFiles} archivos seleccionados`;
    } else {
        reassignUseSelectAll = false;
        if (checked.length === 0) {
            alert('Selecciona al menos un archivo para reasignar.');
            return;
        }
        reassignSelectedFiles = checked.map(id => parseInt(id));
        document.getElementById('reassignCount').textContent = `${reassignSelectedFiles.length} archivos seleccionados`;
    }
    document.getElementById('reassignUserSearch').value = '';
    document.getElementById('reassignUserResults').innerHTML = '';
    reassignSelectedUserId = 0;
    document.getElementById('reassignConfirmBtn').disabled = true;
    const modal = document.getElementById('reassignModal');
    modal.style.display = 'flex';
    document.getElementById('reassignUserSearch').focus();
    // prevent clicks inside modal from closing or being intercepted by page-level handlers
    const modalContent = document.getElementById('reassignModalContent');
    if (modalContent) {
        modalContent.addEventListener('click', function(e) { e.stopPropagation(); });
    }
}

function closeReassignModal() {
    document.getElementById('reassignModal').style.display = 'none';
}

let reassignSearchTimeout = null;
document.getElementById('reassignUserSearch').addEventListener('input', function(e) {
    const q = this.value.trim();
    const results = document.getElementById('reassignUserResults');
    results.innerHTML = '';
    reassignSelectedUserId = 0;
    document.getElementById('reassignConfirmBtn').disabled = true;
    if (reassignSearchTimeout) clearTimeout(reassignSearchTimeout);
    if (q.length < 2) return;
    reassignSearchTimeout = setTimeout(() => {
        fetch('/admin/orphan_files_api.php?action=search_users&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(Mimir.parseJsonResponse)
            .then(data => {
                if (!data.success) { results.innerHTML = '<div class="text-danger">Error buscando usuarios</div>'; return; }
                results.innerHTML = '';
                (data.users || []).forEach(u => {
                    const div = document.createElement('div');
                    div.style.padding = '0.4rem';
                    div.style.borderBottom = '1px solid #eee';
                    div.style.cursor = 'pointer';
                    div.textContent = u.username + (u.full_name ? ' (' + u.full_name + ')' : '') + ' — ' + (u.email || '');
                    div.addEventListener('click', function() {
                        // select user
                        reassignSelectedUserId = u.id;
                        Array.from(results.children).forEach(c => c.style.background = '');
                        div.style.background = '#eef';
                        document.getElementById('reassignConfirmBtn').disabled = false;
                    });
                    results.appendChild(div);
                });
            }).catch(err => { results.innerHTML = '<div class="text-danger">Error de red</div>'; });
    }, 300);
});

function confirmReassign() {
    if (!reassignSelectedUserId) { alert('Selecciona un usuario destino'); return; }
    const count = reassignUseSelectAll ? totalFilteredFiles : reassignSelectedFiles.length;
    if (!confirm(`¿Reasignar ${count} archivo(s) al usuario seleccionado?`)) return;
    const fd = new FormData();
    // action name expected by orphan_files_api.php handler
    fd.append('action', 'reassign_any');
    fd.append('user_id', reassignSelectedUserId);
    if (reassignUseSelectAll) {
        fd.append('select_all', '1');
        fd.append('filters', window.location.search.length ? window.location.search.substring(1) : '');
    } else {
        fd.append('file_ids', JSON.stringify(reassignSelectedFiles));
    }

    // Disable confirm button and show processing overlay
    const confirmBtn = document.getElementById('reassignConfirmBtn');
    if (confirmBtn) { confirmBtn.disabled = true; }

    if (reassignUseSelectAll) {
        // Fetch full ID list from server then process in batches
        showProcessing('Obteniendo lista de archivos...');
        const filters = window.location.search.length ? window.location.search.substring(1) : '';
        const listUrl = '/admin/orphan_files_api.php?action=list_ids&' + filters;
        const pageSize = 500;
        const chunkSize = 50;
        let succeeded = 0;
        let failed = 0;
        showProcessing('Reasignando archivos por páginas...', { clearLogs: true, percent: 0, status: 'Iniciando' });
        Mimir.processListIdsInPages(listUrl, 'reassign_any', pageSize, 100, {
            onProgress: function(processed, total) {
                const pct = total ? Math.round((processed / total) * 100) : 0;
                updateProcessingProgress(pct, `Procesados ${processed}`);
            },
            onLog: function(txt) { appendProcessingLog(txt); },
            onError: function(err) {
                hideProcessing(); if (confirmBtn) confirmBtn.disabled = false; console.error('Error paginando lista:', err); alert('Error durante la reasignación: ' + (err.message || err));
            },
            onComplete: function() { hideProcessing(); if (confirmBtn) confirmBtn.disabled = false; alert('Reasignación completada.'); closeReassignModal(); window.location.reload(); }
        });
    } else {
        showProcessing('Reasignando archivos, esto puede tardar varios segundos...');
            fetch('/admin/orphan_files_api.php?action=reassign_any', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(Mimir.parseJsonResponse)
            .then(resp => {
                hideProcessing();
                if (confirmBtn) { confirmBtn.disabled = false; }
                if (resp.success) {
                    alert((resp.count || 0) + ' archivos reasignados. ' + (resp.message ? '\n' + resp.message : ''));
                    closeReassignModal();
                    // reload page to reflect ownership changes
                    window.location.reload();
                } else {
                    alert('Error: ' + (resp.message || 'No se pudo reasignar'));
                }
            }).catch(err => {
                hideProcessing();
                if (confirmBtn) { confirmBtn.disabled = false; }
                alert('Error de red: ' + err.message);
            });
    }
}
</script>

<?php renderPageEnd(); ?>
