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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && !empty($_POST['file_ids'])) {
    $action = $_POST['action'];
    $fileIds = array_map('intval', $_POST['file_ids']);
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
                    if ($fileClass->delete($fileId, $user['id'])) {
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

// Valid sort columns
$validSort = ['original_name', 'file_size', 'created_at', 'username', 'mime_type'];
if (!in_array($sortBy, $validSort)) $sortBy = 'created_at';
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

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
    ORDER BY " . ($sortBy === 'username' ? 'u.username' : 'f.' . $sortBy) . " $sortOrder
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
.bulk-actions-bar.show {
    display: flex;
    align-items: center;
    gap: 1rem;
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
                                    <th>Compartido</th>
                                    <th>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'created_at', 'order' => $sortBy === 'created_at' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'])); ?>" 
                                           class="sort-link <?php echo $sortBy === 'created_at' ? 'active' : ''; ?>">
                                            Fecha
                                            <?php if ($sortBy === 'created_at'): ?>
                                                <i class="fas fa-sort-<?php echo $sortOrder === 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
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
                                            <?php echo explode('/', $file['mime_type'])[0]; ?>
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
    <button type="button" class="btn btn-secondary" onclick="clearSelection()">
        <i class="fas fa-times"></i> Cancelar
    </button>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const fileCheckboxes = document.querySelectorAll('.file-item');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const selectedCountSpan = document.getElementById('selectedCount');
    
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
            updateBulkActionsBar();
            
            // Update select all checkbox state
            const allChecked = Array.from(fileCheckboxes).every(cb => cb.checked);
            const someChecked = Array.from(fileCheckboxes).some(cb => cb.checked);
            selectAllCheckbox.checked = allChecked;
            selectAllCheckbox.indeterminate = someChecked && !allChecked;
        });
    });
});

function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAll');
    selectAllCheckbox.checked = !selectAllCheckbox.checked;
    selectAllCheckbox.dispatchEvent(new Event('change'));
}

function clearSelection() {
    document.querySelectorAll('.file-item').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    document.getElementById('bulkActionsBar').classList.remove('show');
}

function confirmBulkAction(action) {
    const count = document.querySelectorAll('.file-item:checked').length;
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
    }
    
    if (confirm(message)) {
        document.getElementById('bulkAction').value = action;
        document.getElementById('bulkForm').submit();
    }
}
</script>

<?php renderPageEnd(); ?>
