<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireAdmin();

$db = Database::getInstance()->getConnection();

// Pagination and filters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

$filterOwner = $_GET['owner'] ?? '';
$filterShared = $_GET['shared'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$searchTerm = $_GET['search'] ?? '';

// Sorting
$sortBy = $_GET['sort'] ?? 'created_at';
$sortDir = $_GET['dir'] ?? 'desc';

// Validate sort column
$allowedSortColumns = ['original_filename', 'username', 'file_size', 'is_shared', 'created_at'];
if (!in_array($sortBy, $allowedSortColumns)) {
    $sortBy = 'created_at';
}

// Validate sort direction
if (!in_array(strtolower($sortDir), ['asc', 'desc'])) {
    $sortDir = 'desc';
}

// Handle bulk delete
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'bulk_delete' && !empty($_POST['file_ids'])) {
        try {
            $fileIds = $_POST['file_ids'];
            $placeholders = str_repeat('?,', count($fileIds) - 1) . '?';
            
            // Get file paths before deletion
            $stmt = $db->prepare("SELECT file_path FROM files WHERE id IN ($placeholders)");
            $stmt->execute($fileIds);
            $filePaths = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Delete from database
            $stmt = $db->prepare("DELETE FROM files WHERE id IN ($placeholders)");
            $stmt->execute($fileIds);
            
            // Delete physical files
            foreach ($filePaths as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            
            // Update user storage
            $stmt = $db->query("
                UPDATE users u 
                SET storage_used = COALESCE((
                    SELECT SUM(file_size) 
                    FROM files 
                    WHERE user_id = u.id
                ), 0)
            ");
            
            $message = count($fileIds) . ' archivos eliminados exitosamente';
            $messageType = 'success';
            
            AuditLog::log('bulk_delete_files', 'Deleted ' . count($fileIds) . ' files');
        } catch (Exception $e) {
            $message = 'Error al eliminar archivos: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Build query
$whereConditions = [];
$params = [];

if (!empty($filterOwner)) {
    $whereConditions[] = "u.username LIKE ?";
    $params[] = "%$filterOwner%";
}

if ($filterShared === 'yes') {
    $whereConditions[] = "ps.id IS NOT NULL AND ps.is_active = 1";
} elseif ($filterShared === 'no') {
    $whereConditions[] = "ps.id IS NULL";
}

if (!empty($filterDateFrom)) {
    $whereConditions[] = "DATE(f.created_at) >= ?";
    $params[] = $filterDateFrom;
}

if (!empty($filterDateTo)) {
    $whereConditions[] = "DATE(f.created_at) <= ?";
    $params[] = $filterDateTo;
}

if (!empty($searchTerm)) {
    $whereConditions[] = "f.original_filename LIKE ?";
    $params[] = "%$searchTerm%";
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get total count
$countSql = "
    SELECT COUNT(DISTINCT f.id)
    FROM files f
    INNER JOIN users u ON f.user_id = u.id
    LEFT JOIN public_shares ps ON f.id = ps.file_id
    $whereClause
";
$stmt = $db->prepare($countSql);
if (!empty($params)) {
    $stmt->execute($params);
} else {
    $stmt->execute();
}
$totalFiles = $stmt->fetchColumn();
$totalPages = ceil($totalFiles / $perPage);

// Build ORDER BY clause dynamically
$orderByClause = '';
switch ($sortBy) {
    case 'original_filename':
        $orderByClause = "f.original_filename " . strtoupper($sortDir);
        break;
    case 'username':
        $orderByClause = "u.username " . strtoupper($sortDir);
        break;
    case 'file_size':
        $orderByClause = "f.file_size " . strtoupper($sortDir);
        break;
    case 'is_shared':
        $orderByClause = "(CASE WHEN ps.id IS NOT NULL AND ps.is_active = 1 THEN 1 ELSE 0 END) " . strtoupper($sortDir);
        break;
    case 'created_at':
    default:
        $orderByClause = "f.created_at " . strtoupper($sortDir);
        break;
}

// Get files
$sql = "
    SELECT 
        f.id,
        f.original_filename,
        f.file_size,
        f.mime_type,
        f.created_at,
        u.id as user_id,
        u.username,
        u.email,
        CASE WHEN ps.id IS NOT NULL AND ps.is_active = 1 THEN 1 ELSE 0 END as is_shared,
        ps.share_token,
        ps.created_at as share_created_at
    FROM files f
    INNER JOIN users u ON f.user_id = u.id
    LEFT JOIN public_shares ps ON f.id = ps.file_id AND ps.is_active = 1
    $whereClause
    ORDER BY $orderByClause
    LIMIT $perPage OFFSET $offset
";

$stmt = $db->prepare($sql);
if (!empty($params)) {
    $stmt->execute($params);
} else {
    $stmt->execute();
}
$files = $stmt->fetchAll();

// Get all users for filter
$stmt = $db->query("SELECT DISTINCT username FROM users ORDER BY username");
$users = $stmt->fetchAll(PDO::FETCH_COLUMN);

$siteName = SystemConfig::get('site_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar Archivos - <?php echo escapeHtml($siteName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #1e293b;
        }
        
        .navbar {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1rem 0;
        }
        
        .navbar-content {
            max-width: 1600px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .navbar-brand {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .navbar-menu {
            display: flex;
            gap: 1rem;
        }
        
        .navbar-menu a {
            color: #64748b;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .navbar-menu a:hover,
        .navbar-menu a.active {
            background: #f1f5f9;
            color: #667eea;
        }
        
        .container {
            max-width: 1600px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            font-size: 2rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .filters-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .filter-group label {
            font-weight: 600;
            color: #475569;
            font-size: 0.875rem;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 0.625rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.875rem;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .filter-actions {
            display: flex;
            gap: 0.75rem;
        }
        
        .content-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .bulk-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
        }
        
        .bulk-actions-info {
            flex: 1;
            color: #64748b;
            font-weight: 500;
        }
        
        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            font-size: 0.875rem;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        
        .btn-danger:hover {
            background: #b91c1c;
        }
        
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table thead {
            background: #f8fafc;
        }
        
        .data-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.875rem;
        }
        
        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.875rem;
        }
        
        .data-table tbody tr {
            transition: background 0.2s;
        }
        
        .data-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .data-table tbody tr.selected {
            background: #ede9fe;
        }
        
        .checkbox-cell {
            width: 40px;
        }
        
        .checkbox-cell input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .badge {
            padding: 0.25rem 0.625rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-secondary {
            background: #f1f5f9;
            color: #64748b;
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .user-name {
            font-weight: 600;
            color: #1e293b;
        }
        
        .user-email {
            color: #64748b;
            font-size: 0.8125rem;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }
        
        .pagination a,
        .pagination span {
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            text-decoration: none;
            color: #475569;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .pagination a:hover {
            background: #f1f5f9;
            color: #667eea;
        }
        
        .pagination .active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .pagination .disabled {
            opacity: 0.5;
            pointer-events: none;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 8px;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
        }
        
        .stat-label {
            font-size: 0.8125rem;
            color: #64748b;
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-cloud"></i>
                <?php echo escapeHtml($siteName); ?>
            </div>
            <div class="navbar-menu">
                <a href="dashboard.php">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="admin_dashboard.php">
                    <i class="fas fa-chart-line"></i> Panel Admin
                </a>
                <a href="admin_users.php">
                    <i class="fas fa-users"></i> Usuarios
                </a>
                <a href="admin_files.php" class="active">
                    <i class="fas fa-file-alt"></i> Archivos
                </a>
                <a href="admin_config.php">
                    <i class="fas fa-cog"></i> Configuración
                </a>
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                </a>
            </div>
        </div>
    </div>
    
    <div class="container">
        <div class="page-header">
            <h1>
                <i class="fas fa-files"></i>
                Administrar Todos los Archivos
            </h1>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($totalFiles); ?></div>
                    <div class="stat-label">Total Archivos</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $totalPages; ?></div>
                    <div class="stat-label">Páginas</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $page; ?></div>
                    <div class="stat-label">Página Actual</div>
                </div>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo escapeHtml($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="filters-card">
            <form method="GET" id="filterForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label><i class="fas fa-search"></i> Buscar Nombre</label>
                        <input type="text" name="search" value="<?php echo escapeHtml($searchTerm); ?>" placeholder="Nombre de archivo...">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-user"></i> Propietario</label>
                        <input type="text" name="owner" value="<?php echo escapeHtml($filterOwner); ?>" placeholder="Usuario..." list="usersList">
                        <datalist id="usersList">
                            <?php foreach ($users as $username): ?>
                                <option value="<?php echo escapeHtml($username); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-share-nodes"></i> Compartido</label>
                        <select name="shared">
                            <option value="">Todos</option>
                            <option value="yes" <?php echo $filterShared === 'yes' ? 'selected' : ''; ?>>Sí</option>
                            <option value="no" <?php echo $filterShared === 'no' ? 'selected' : ''; ?>>No</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> Desde</label>
                        <input type="date" name="date_from" value="<?php echo escapeHtml($filterDateFrom); ?>">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> Hasta</label>
                        <input type="date" name="date_to" value="<?php echo escapeHtml($filterDateTo); ?>">
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Aplicar Filtros
                    </button>
                    <a href="admin_files.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpiar
                    </a>
                </div>
            </form>
        </div>
        
        <div class="content-card">
            <form method="POST" id="bulkForm">
                <input type="hidden" name="action" value="bulk_delete">
                
                <div class="bulk-actions">
                    <span class="bulk-actions-info">
                        <span id="selectedCount">0</span> archivos seleccionados
                    </span>
                    <button type="button" class="btn btn-secondary" onclick="selectAll()">
                        <i class="fas fa-check-square"></i> Seleccionar Todo
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="deselectAll()">
                        <i class="fas fa-square"></i> Deseleccionar Todo
                    </button>
                    <button type="submit" class="btn btn-danger" id="deleteBtn" disabled onclick="return confirm('¿Estás seguro de eliminar los archivos seleccionados? Esta acción no se puede deshacer.')">
                        <i class="fas fa-trash"></i> Eliminar Seleccionados
                    </button>
                </div>
                
                <?php if (empty($files)): ?>
                    <p style="text-align: center; color: #94a3b8; padding: 3rem;">No se encontraron archivos con los filtros aplicados.</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="checkbox-cell">
                                    <input type="checkbox" id="selectAllCheckbox" onchange="toggleAll(this)">
                                </th>
                                <?php
                                function buildSortUrl($column, $currentSort, $currentDir, $params) {
                                    $newDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
                                    $queryParams = array_merge($params, ['sort' => $column, 'dir' => $newDir]);
                                    return '?' . http_build_query($queryParams);
                                }
                                
                                function getSortIcon($column, $currentSort, $currentDir) {
                                    if ($currentSort !== $column) {
                                        return '<i class="fas fa-sort sort-icon"></i>';
                                    }
                                    return $currentDir === 'asc' 
                                        ? '<i class="fas fa-sort-up sort-icon"></i>'
                                        : '<i class="fas fa-sort-down sort-icon"></i>';
                                }
                                
                                $queryParams = [
                                    'page' => $page,
                                    'owner' => $filterOwner,
                                    'shared' => $filterShared,
                                    'date_from' => $filterDateFrom,
                                    'date_to' => $filterDateTo,
                                    'search' => $searchTerm
                                ];
                                $queryParams = array_filter($queryParams, fn($v) => $v !== '');
                                ?>
                                <th class="sortable <?php echo $sortBy === 'original_filename' ? 'active' : ''; ?>">
                                    <a href="<?php echo buildSortUrl('original_filename', $sortBy, $sortDir, $queryParams); ?>">
                                        Archivo
                                        <?php echo getSortIcon('original_filename', $sortBy, $sortDir); ?>
                                    </a>
                                </th>
                                <th class="sortable <?php echo $sortBy === 'username' ? 'active' : ''; ?>">
                                    <a href="<?php echo buildSortUrl('username', $sortBy, $sortDir, $queryParams); ?>">
                                        Propietario
                                        <?php echo getSortIcon('username', $sortBy, $sortDir); ?>
                                    </a>
                                </th>
                                <th class="sortable <?php echo $sortBy === 'file_size' ? 'active' : ''; ?>" style="width: 100px;">
                                    <a href="<?php echo buildSortUrl('file_size', $sortBy, $sortDir, $queryParams); ?>">
                                        Tamaño
                                        <?php echo getSortIcon('file_size', $sortBy, $sortDir); ?>
                                    </a>
                                </th>
                                <th class="sortable <?php echo $sortBy === 'is_shared' ? 'active' : ''; ?>" style="width: 120px;">
                                    <a href="<?php echo buildSortUrl('is_shared', $sortBy, $sortDir, $queryParams); ?>">
                                        Compartido
                                        <?php echo getSortIcon('is_shared', $sortBy, $sortDir); ?>
                                    </a>
                                </th>
                                <th class="sortable <?php echo $sortBy === 'created_at' ? 'active' : ''; ?>" style="width: 140px;">
                                    <a href="<?php echo buildSortUrl('created_at', $sortBy, $sortDir, $queryParams); ?>">
                                        Fecha
                                        <?php echo getSortIcon('created_at', $sortBy, $sortDir); ?>
                                    </a>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($files as $file): ?>
                            <tr class="file-row">
                                <td class="checkbox-cell">
                                    <input type="checkbox" name="file_ids[]" value="<?php echo $file['id']; ?>" class="file-checkbox" onchange="updateSelection()">
                                </td>
                                <td>
                                    <i class="fas fa-file-alt" style="color: #94a3b8; margin-right: 0.5rem;"></i>
                                    <?php echo escapeHtml($file['original_filename']); ?>
                                </td>
                                <td>
                                    <div class="user-info">
                                        <span class="user-name"><?php echo escapeHtml($file['username']); ?></span>
                                        <span class="user-email"><?php echo escapeHtml($file['email']); ?></span>
                                    </div>
                                </td>
                                <td><?php echo formatBytes($file['file_size']); ?></td>
                                <td>
                                    <?php if ($file['is_shared']): ?>
                                        <span class="badge badge-success">
                                            <i class="fas fa-check"></i> Sí
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">
                                            <i class="fas fa-times"></i> No
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: #64748b;"><?php echo date('d/m/Y H:i', strtotime($file['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </form>
            
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php 
                    $paginationParams = array_merge($queryParams, ['sort' => $sortBy, 'dir' => $sortDir]);
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($paginationParams, ['page' => $page - 1])); ?>">
                            <i class="fas fa-chevron-left"></i> Anterior
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-left"></i> Anterior</span>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    if ($startPage > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($paginationParams, ['page' => 1])); ?>">1</a>
                        <?php if ($startPage > 2): ?>
                            <span>...</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($paginationParams, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <span>...</span>
                        <?php endif; ?>
                        <a href="?<?php echo http_build_query(array_merge($paginationParams, ['page' => $totalPages])); ?>"><?php echo $totalPages; ?></a>
                    <?php endif; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?php echo http_build_query(array_merge($paginationParams, ['page' => $page + 1])); ?>">
                            Siguiente <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled">Siguiente <i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function updateSelection() {
            const checkboxes = document.querySelectorAll('.file-checkbox:checked');
            const count = checkboxes.length;
            document.getElementById('selectedCount').textContent = count;
            document.getElementById('deleteBtn').disabled = count === 0;
            
            // Update row highlighting
            document.querySelectorAll('.file-row').forEach(row => {
                const checkbox = row.querySelector('.file-checkbox');
                if (checkbox.checked) {
                    row.classList.add('selected');
                } else {
                    row.classList.remove('selected');
                }
            });
            
            // Update select all checkbox
            const allCheckboxes = document.querySelectorAll('.file-checkbox');
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            selectAllCheckbox.checked = allCheckboxes.length > 0 && checkboxes.length === allCheckboxes.length;
        }
        
        function toggleAll(checkbox) {
            const checkboxes = document.querySelectorAll('.file-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateSelection();
        }
        
        function selectAll() {
            document.querySelectorAll('.file-checkbox').forEach(cb => cb.checked = true);
            document.getElementById('selectAllCheckbox').checked = true;
            updateSelection();
        }
        
        function deselectAll() {
            document.querySelectorAll('.file-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAllCheckbox').checked = false;
            updateSelection();
        }
    </script>
</body>
</html>
