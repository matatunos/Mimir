<?php
require_once '../includes/init.php';

// Check admin access
Auth::requireAdmin();

$db = Database::getInstance()->getConnection();

// Handle user actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_user':
                $userId = $_POST['user_id'] ?? null;
                $role = $_POST['role'] ?? 'user';
                $quota = $_POST['storage_quota'] ?? 1073741824;
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                
                if ($userId) {
                    $stmt = $db->prepare("UPDATE users SET role = ?, storage_quota = ?, is_active = ? WHERE id = ?");
                    $stmt->execute([$role, $quota, $isActive, $userId]);
                    $message = 'Usuario actualizado correctamente';
                    $messageType = 'success';
                    
                    AuditLog::log(Auth::getUserId(), 'user_updated', 'user', $userId, "Admin updated user settings");
                }
                break;
                
            case 'delete_user':
                $userId = $_POST['user_id'] ?? null;
                
                if ($userId && $userId != Auth::getUserId()) {
                    // Delete user's files first
                    $stmt = $db->prepare("SELECT file_path FROM files WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    $files = $stmt->fetchAll();
                    
                    foreach ($files as $file) {
                        $filePath = UPLOAD_DIR . '/' . $file['file_path'];
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                    }
                    
                    // Delete database records
                    $db->prepare("DELETE FROM public_shares WHERE file_id IN (SELECT id FROM files WHERE user_id = ?)")->execute([$userId]);
                    $db->prepare("DELETE FROM files WHERE user_id = ?")->execute([$userId]);
                    $db->prepare("DELETE FROM audit_logs WHERE user_id = ?")->execute([$userId]);
                    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
                    
                    $message = 'Usuario eliminado correctamente';
                    $messageType = 'success';
                    
                    AuditLog::log(Auth::getUserId(), 'user_deleted', 'user', $userId, "Admin deleted user");
                } else {
                    $message = 'No puedes eliminar tu propio usuario';
                    $messageType = 'error';
                }
                break;
                
            case 'bulk_delete':
                $userIds = $_POST['user_ids'] ?? [];
                $currentUserId = Auth::getUserId();
                $deletedCount = 0;
                
                foreach ($userIds as $userId) {
                    if ($userId == $currentUserId) continue; // Skip current user
                    
                    // Delete user's files
                    $stmt = $db->prepare("SELECT file_path FROM files WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    $files = $stmt->fetchAll();
                    
                    foreach ($files as $file) {
                        $filePath = UPLOAD_DIR . '/' . $file['file_path'];
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                    }
                    
                    // Delete database records
                    $db->prepare("DELETE FROM public_shares WHERE file_id IN (SELECT id FROM files WHERE user_id = ?)")->execute([$userId]);
                    $db->prepare("DELETE FROM files WHERE user_id = ?")->execute([$userId]);
                    $db->prepare("DELETE FROM audit_logs WHERE user_id = ?")->execute([$userId]);
                    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
                    
                    $deletedCount++;
                    AuditLog::log(Auth::getUserId(), 'user_deleted', 'user', $userId, "Admin bulk deleted user");
                }
                
                $message = "$deletedCount usuario(s) eliminado(s) correctamente";
                $messageType = 'success';
                break;
        }
    }
}

// Get filter parameters
$filterRole = $_GET['role'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterSearch = $_GET['search'] ?? '';
$sortBy = $_GET['sort'] ?? 'created_at';
$sortOrder = $_GET['order'] ?? 'DESC';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build query
$where = ["1=1"];
$params = [];

if ($filterRole) {
    $where[] = "u.role = ?";
    $params[] = $filterRole;
}

if ($filterStatus === 'active') {
    $where[] = "u.is_active = 1";
} elseif ($filterStatus === 'inactive') {
    $where[] = "u.is_active = 0";
}

if ($filterSearch) {
    $where[] = "(u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)";
    $searchParam = "%$filterSearch%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = implode(" AND ", $where);

// Validate sort column
$allowedSort = ['id', 'username', 'email', 'role', 'created_at', 'last_login', 'storage_used', 'file_count'];
if (!in_array($sortBy, $allowedSort)) {
    $sortBy = 'created_at';
}
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Get total count
$stmt = $db->prepare("SELECT COUNT(*) as total FROM users u WHERE $whereClause");
$stmt->execute($params);
$totalUsers = $stmt->fetch()['total'];
$totalPages = ceil($totalUsers / $perPage);

// Get users with file count
$stmt = $db->prepare("
    SELECT 
        u.*,
        COUNT(f.id) as file_count,
        COALESCE(SUM(f.file_size), 0) as storage_used_calc
    FROM users u
    LEFT JOIN files f ON u.id = f.user_id
    WHERE $whereClause
    GROUP BY u.id
    ORDER BY $sortBy $sortOrder
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get statistics
$stmt = $db->query("SELECT COUNT(*) as total FROM users");
$totalUsersCount = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE is_active = 1");
$activeUsersCount = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role = 'admin'");
$adminCount = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$activeLastMonth = $stmt->fetch()['total'];

$siteName = SystemConfig::get('site_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - <?php echo escapeHtml($siteName); ?></title>
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
            gap: 0.75rem;
            text-decoration: none;
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .navbar-menu a:hover {
            background: #f1f5f9;
            color: #667eea;
        }
        
        .navbar-menu a.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .container {
            max-width: 1600px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            font-size: 2rem;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .page-header p {
            color: #64748b;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }
        
        .stat-icon.blue {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .stat-icon.green {
            background: linear-gradient(135deg, #34d399 0%, #10b981 100%);
        }
        
        .stat-icon.orange {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        
        .stat-icon.red {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 0.875rem;
        }
        
        .filters-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .filters-header h2 {
            font-size: 1.25rem;
            color: #1e293b;
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
            font-size: 0.875rem;
            font-weight: 500;
            color: #64748b;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9375rem;
            transition: all 0.3s;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .filter-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.4);
        }
        
        .btn-secondary {
            background: #f1f5f9;
            color: #64748b;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        
        .users-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .users-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .users-header h2 {
            font-size: 1.25rem;
            color: #1e293b;
        }
        
        .bulk-actions {
            display: none;
            gap: 1rem;
            align-items: center;
        }
        
        .bulk-actions.show {
            display: flex;
        }
        
        .table-wrapper {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #475569;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
            user-select: none;
        }
        
        th:hover {
            background: #f1f5f9;
        }
        
        th i {
            margin-left: 0.5rem;
            opacity: 0.5;
        }
        
        td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        
        tr:hover {
            background: #fafafa;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            flex-shrink: 0;
        }
        
        .user-details {
            flex: 1;
            min-width: 0;
        }
        
        .user-name {
            font-weight: 600;
            color: #1e293b;
        }
        
        .user-email {
            font-size: 0.8125rem;
            color: #64748b;
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 600;
        }
        
        .badge-admin {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-user {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-icon {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            background: #f1f5f9;
            color: #64748b;
        }
        
        .btn-icon:hover {
            background: #e2e8f0;
        }
        
        .btn-icon.edit {
            color: #667eea;
        }
        
        .btn-icon.edit:hover {
            background: #eef2ff;
        }
        
        .btn-icon.delete {
            color: #ef4444;
        }
        
        .btn-icon.delete:hover {
            background: #fee2e2;
        }
        
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
        }
        
        .pagination-info {
            color: #64748b;
            font-size: 0.875rem;
        }
        
        .pagination-buttons {
            display: flex;
            gap: 0.5rem;
        }
        
        .pagination-buttons a,
        .pagination-buttons span {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            color: #64748b;
            background: #f8fafc;
            transition: all 0.3s;
        }
        
        .pagination-buttons a:hover {
            background: #e2e8f0;
            color: #1e293b;
        }
        
        .pagination-buttons .active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .modal-header h3 {
            font-size: 1.5rem;
            color: #1e293b;
        }
        
        .modal-close {
            width: 32px;
            height: 32px;
            border: none;
            background: #f1f5f9;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
        }
        
        .modal-close:hover {
            background: #e2e8f0;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #475569;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9375rem;
            transition: all 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-group-checkbox {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .form-group-checkbox input {
            width: auto;
        }
        
        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }
        
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .progress-bar {
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: width 0.3s;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="navbar-content">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-cloud"></i>
                <?php echo escapeHtml($siteName); ?>
            </a>
            <div class="navbar-menu">
                <a href="dashboard.php">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="admin_dashboard.php">
                    <i class="fas fa-chart-line"></i> Panel Admin
                </a>
                <a href="admin_users.php" class="active">
                    <i class="fas fa-users"></i> Usuarios
                </a>
                <a href="admin_files.php">
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
            <h1><i class="fas fa-users-cog"></i> Gestión de Usuarios</h1>
            <p>Administra todos los usuarios del sistema</p>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo escapeHtml($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon blue">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo number_format($totalUsersCount); ?></div>
                        <div class="stat-label">Total Usuarios</div>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon green">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo number_format($activeUsersCount); ?></div>
                        <div class="stat-label">Usuarios Activos</div>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon orange">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo number_format($adminCount); ?></div>
                        <div class="stat-label">Administradores</div>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon red">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo number_format($activeLastMonth); ?></div>
                        <div class="stat-label">Activos (30 días)</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="filters-card">
            <div class="filters-header">
                <h2><i class="fas fa-filter"></i> Filtros</h2>
            </div>
            <form method="GET" action="">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>Buscar</label>
                        <input type="text" name="search" value="<?php echo escapeHtml($filterSearch); ?>" placeholder="Usuario, email o nombre...">
                    </div>
                    
                    <div class="filter-group">
                        <label>Rol</label>
                        <select name="role">
                            <option value="">Todos</option>
                            <option value="user" <?php echo $filterRole === 'user' ? 'selected' : ''; ?>>Usuario</option>
                            <option value="admin" <?php echo $filterRole === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Estado</label>
                        <select name="status">
                            <option value="">Todos</option>
                            <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Activos</option>
                            <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>>Inactivos</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Ordenar por</label>
                        <select name="sort">
                            <option value="created_at" <?php echo $sortBy === 'created_at' ? 'selected' : ''; ?>>Fecha de Registro</option>
                            <option value="last_login" <?php echo $sortBy === 'last_login' ? 'selected' : ''; ?>>Último Login</option>
                            <option value="username" <?php echo $sortBy === 'username' ? 'selected' : ''; ?>>Usuario</option>
                            <option value="email" <?php echo $sortBy === 'email' ? 'selected' : ''; ?>>Email</option>
                            <option value="storage_used" <?php echo $sortBy === 'storage_used' ? 'selected' : ''; ?>>Almacenamiento</option>
                            <option value="file_count" <?php echo $sortBy === 'file_count' ? 'selected' : ''; ?>>Archivos</option>
                        </select>
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Aplicar Filtros
                    </button>
                    <a href="admin_users.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpiar
                    </a>
                </div>
            </form>
        </div>
        
        <div class="users-card">
            <div class="users-header">
                <h2><i class="fas fa-list"></i> Usuarios (<?php echo number_format($totalUsers); ?>)</h2>
                <div class="bulk-actions" id="bulkActions">
                    <span id="selectedCount">0 seleccionados</span>
                    <button type="button" class="btn btn-danger" onclick="bulkDelete()">
                        <i class="fas fa-trash"></i> Eliminar Seleccionados
                    </button>
                </div>
            </div>
            
            <form id="bulkForm" method="POST">
                <input type="hidden" name="action" value="bulk_delete">
                
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" id="selectAll">
                                </th>
                                <th>Usuario</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Archivos</th>
                                <th>Almacenamiento</th>
                                <th>Último Login</th>
                                <th>Registro</th>
                                <th style="width: 100px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <?php if ($user['id'] != Auth::getUserId()): ?>
                                            <input type="checkbox" name="user_ids[]" value="<?php echo $user['id']; ?>" class="user-checkbox">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <?php echo strtoupper(substr($user['username'], 0, 2)); ?>
                                            </div>
                                            <div class="user-details">
                                                <div class="user-name"><?php echo escapeHtml($user['username']); ?></div>
                                                <div class="user-email"><?php echo escapeHtml($user['email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $user['role'] === 'admin' ? 'admin' : 'user'; ?>">
                                            <i class="fas fa-<?php echo $user['role'] === 'admin' ? 'user-shield' : 'user'; ?>"></i>
                                            <?php echo escapeHtml(ucfirst($user['role'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $user['is_active'] ? 'success' : 'danger'; ?>">
                                            <i class="fas fa-<?php echo $user['is_active'] ? 'check' : 'times'; ?>"></i>
                                            <?php echo $user['is_active'] ? 'Activo' : 'Inactivo'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($user['file_count']); ?></td>
                                    <td>
                                        <?php echo formatBytes($user['storage_used_calc']); ?>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo min(100, ($user['storage_used_calc'] / $user['storage_quota']) * 100); ?>%"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($user['last_login']) {
                                            echo date('d/m/Y H:i', strtotime($user['last_login']));
                                        } else {
                                            echo '<span style="color: #94a3b8;">Nunca</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn-icon edit" onclick="editUser(<?php echo htmlspecialchars(json_encode($user), ENT_QUOTES, 'UTF-8'); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($user['id'] != Auth::getUserId()): ?>
                                                <button type="button" class="btn-icon delete" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo escapeHtml($user['username']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <div class="pagination-info">
                        Página <?php echo $page; ?> de <?php echo $totalPages; ?> (<?php echo number_format($totalUsers); ?> usuarios)
                    </div>
                    <div class="pagination-buttons">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($filterSearch); ?>&role=<?php echo urlencode($filterRole); ?>&status=<?php echo urlencode($filterStatus); ?>&sort=<?php echo urlencode($sortBy); ?>&order=<?php echo urlencode($sortOrder); ?>">
                                <i class="fas fa-chevron-left"></i> Anterior
                            </a>
                        <?php endif; ?>
                        
                        <?php 
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        
                        for ($i = $start; $i <= $end; $i++): 
                        ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($filterSearch); ?>&role=<?php echo urlencode($filterRole); ?>&status=<?php echo urlencode($filterStatus); ?>&sort=<?php echo urlencode($sortBy); ?>&order=<?php echo urlencode($sortOrder); ?>" 
                               class="<?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($filterSearch); ?>&role=<?php echo urlencode($filterRole); ?>&status=<?php echo urlencode($filterStatus); ?>&sort=<?php echo urlencode($sortBy); ?>&order=<?php echo urlencode($sortOrder); ?>">
                                Siguiente <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Editar Usuario</h3>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="editForm" method="POST">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="editUserId">
                
                <div class="form-group">
                    <label>Usuario</label>
                    <input type="text" id="editUsername" readonly style="background: #f8fafc;">
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="editEmail" readonly style="background: #f8fafc;">
                </div>
                
                <div class="form-group">
                    <label>Rol</label>
                    <select name="role" id="editRole">
                        <option value="user">Usuario</option>
                        <option value="admin">Administrador</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Cuota de Almacenamiento (bytes)</label>
                    <input type="number" name="storage_quota" id="editQuota" required>
                </div>
                
                <div class="form-group form-group-checkbox">
                    <input type="checkbox" name="is_active" id="editActive" value="1">
                    <label for="editActive">Usuario Activo</label>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirmar Eliminación</h3>
                <button class="modal-close" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p style="margin-bottom: 1.5rem; color: #64748b;">
                ¿Estás seguro de que quieres eliminar al usuario <strong id="deleteUsername"></strong>?
                Esta acción eliminará todos sus archivos y no se puede deshacer.
            </p>
            <form id="deleteForm" method="POST">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="deleteUserId">
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Eliminar Usuario
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Select all checkbox
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
            updateBulkActions();
        });
        
        // Individual checkboxes
        document.querySelectorAll('.user-checkbox').forEach(cb => {
            cb.addEventListener('change', updateBulkActions);
        });
        
        function updateBulkActions() {
            const checked = document.querySelectorAll('.user-checkbox:checked').length;
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');
            
            if (checked > 0) {
                bulkActions.classList.add('show');
                selectedCount.textContent = checked + ' seleccionado' + (checked > 1 ? 's' : '');
            } else {
                bulkActions.classList.remove('show');
            }
        }
        
        function editUser(user) {
            document.getElementById('editUserId').value = user.id;
            document.getElementById('editUsername').value = user.username;
            document.getElementById('editEmail').value = user.email;
            document.getElementById('editRole').value = user.role;
            document.getElementById('editQuota').value = user.storage_quota;
            document.getElementById('editActive').checked = user.is_active == 1;
            
            document.getElementById('editModal').classList.add('show');
        }
        
        function closeModal() {
            document.getElementById('editModal').classList.remove('show');
        }
        
        function deleteUser(userId, username) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUsername').textContent = username;
            document.getElementById('deleteModal').classList.add('show');
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
        }
        
        function bulkDelete() {
            const checked = document.querySelectorAll('.user-checkbox:checked');
            if (checked.length === 0) return;
            
            if (confirm('¿Estás seguro de que quieres eliminar ' + checked.length + ' usuario(s)? Esta acción no se puede deshacer.')) {
                document.getElementById('bulkForm').submit();
            }
        }
        
        // Close modals on click outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('show');
                }
            });
        });
    </script>
</body>
</html>
