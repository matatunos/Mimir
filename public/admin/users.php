<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/User.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();
$userClass = new User();
// Ensure DB connection is available for any early operations (bulk actions may run before
// the later $db assignment). Initialize here to avoid undefined variable errors.
$db = Database::getInstance()->getConnection();

// Handle bulk actions (support select_all via filters)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bulk_action']) && (!empty($_POST['user_ids']) || (isset($_POST['select_all']) && $_POST['select_all'] == '1'))) {
    $action = $_POST['bulk_action'];
    $userIds = is_array($_POST['user_ids'] ?? null) ? array_map('intval', $_POST['user_ids']) : [];

    // If select_all flag provided, build the user ID list according to provided filters
    if (isset($_POST['select_all']) && $_POST['select_all'] == '1') {
        $filters = $_POST['filters'] ?? '';
        $farr = [];
        if ($filters !== '') parse_str($filters, $farr);

        $search_f = $farr['search'] ?? '';
        $filterRole_f = $farr['role'] ?? '';
        $filterActive_f = $farr['active'] ?? '';
        $filter2FA_f = $farr['twofa'] ?? '';
        $filterInactive_f = $farr['inactive'] ?? '';

        $whereA = ["1=1"];
        $paramsA = [];
        if ($search_f) {
            $whereA[] = "(u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
            $paramsA[] = "%$search_f%";
            $paramsA[] = "%$search_f%";
            $paramsA[] = "%$search_f%";
        }
        if ($filterRole_f) {
            $whereA[] = "u.role = ?";
            $paramsA[] = $filterRole_f;
        }
        if ($filterActive_f === 'yes') {
            $whereA[] = "u.is_active = 1";
        } elseif ($filterActive_f === 'no') {
            $whereA[] = "u.is_active = 0";
        }
        if ($filter2FA_f === 'yes') {
            $whereA[] = "EXISTS (SELECT 1 FROM user_2fa WHERE user_id = u.id AND is_enabled = 1)";
        } elseif ($filter2FA_f === 'no') {
            $whereA[] = "NOT EXISTS (SELECT 1 FROM user_2fa WHERE user_id = u.id AND is_enabled = 1)";
        } elseif ($filter2FA_f === 'required') {
            $whereA[] = "u.require_2fa = 1";
        }
        if ($filterInactive_f) {
            $days = intval($filterInactive_f);
            $whereA[] = "(SELECT MAX(created_at) FROM activity_log WHERE user_id = u.id) < DATE_SUB(NOW(), INTERVAL ? DAY) OR (SELECT MAX(created_at) FROM activity_log WHERE user_id = u.id) IS NULL";
            $paramsA[] = $days;
        }

        $whereClauseA = implode(' AND ', $whereA);
        $stmtA = $db->prepare("SELECT u.id FROM users u WHERE $whereClauseA");
        $stmtA->execute($paramsA);
        $rows = $stmtA->fetchAll(PDO::FETCH_ASSOC);
        $userIds = array_map(function($r){ return intval($r['id']); }, $rows);
    }
    $success = 0;
    $errors = 0;
    
        try {
        foreach ($userIds as $userId) {
            if ($userId === $user['id']) continue; // Skip current user
            
            $targetUser = $userClass->getById($userId);
            if (!$targetUser) {
                $errors++;
                continue;
            }
            
            switch ($action) {
                case 'activate':
                    $db = Database::getInstance()->getConnection();
                    $stmt = $db->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
                    if ($stmt->execute([$userId])) $success++;
                    else $errors++;
                    break;
                    
                case 'deactivate':
                    $db = Database::getInstance()->getConnection();
                    $stmt = $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
                    if ($stmt->execute([$userId])) $success++;
                    else $errors++;
                    break;
                    
                case 'require_2fa':
                    $db = Database::getInstance()->getConnection();
                    $stmt = $db->prepare("UPDATE users SET require_2fa = 1 WHERE id = ?");
                    if ($stmt->execute([$userId])) $success++;
                    else $errors++;
                    break;
                    
                case 'unrequire_2fa':
                    $db = Database::getInstance()->getConnection();
                    // Update users table
                    $stmt = $db->prepare("UPDATE users SET require_2fa = 0 WHERE id = ?");
                    $stmt->execute([$userId]);
                    // Also disable 2FA
                    $stmt = $db->prepare("UPDATE user_2fa SET is_enabled = 0 WHERE user_id = ?");
                    if ($stmt->execute([$userId])) $success++;
                    else $errors++;
                    break;
                    
                case 'delete':
                    if ($userClass->delete($userId)) $success++;
                    else $errors++;
                    break;
            }
        }
        
        $message = "Acción completada: $success exitosos";
        if ($errors > 0) $message .= ", $errors errores";
        header('Location: ' . BASE_URL . '/admin/users.php?success=' . urlencode($message));
        exit;
        
    } catch (Exception $e) {
        header('Location: ' . BASE_URL . '/admin/users.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

$search = $_GET['search'] ?? '';
$filterRole = $_GET['role'] ?? '';
$filterActive = $_GET['active'] ?? '';
$filter2FA = $_GET['twofa'] ?? '';
$filterInactive = $_GET['inactive'] ?? '';
$sortBy = $_GET['sort'] ?? 'created_at';
$sortDir = $_GET['dir'] ?? 'desc';
// Normalize sort direction and default to DESC
$sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';
// Backwards compatibility: some parts used $sortOrder variable; keep for now but use $sortDir in SQL
$sortOrder = $sortDir;
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = isset($_GET['per_page']) ? max(10, min(100, intval($_GET['per_page']))) : 25;
$offset = ($page - 1) * $perPage;

// Build query
$db = Database::getInstance()->getConnection();
$where = ["1=1"];
$params = [];

if ($search) {
    $where[] = "(u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filterRole) {
    $where[] = "u.role = ?";
    $params[] = $filterRole;
}

if ($filterActive === 'yes') {
    $where[] = "u.is_active = 1";
} elseif ($filterActive === 'no') {
    $where[] = "u.is_active = 0";
}

if ($filter2FA === 'yes') {
    $where[] = "EXISTS (SELECT 1 FROM user_2fa WHERE user_id = u.id AND is_enabled = 1)";
} elseif ($filter2FA === 'no') {
    $where[] = "NOT EXISTS (SELECT 1 FROM user_2fa WHERE user_id = u.id AND is_enabled = 1)";
} elseif ($filter2FA === 'required') {
    $where[] = "u.require_2fa = 1";
}

if ($filterInactive) {
    $days = intval($filterInactive);
    $where[] = "(SELECT MAX(created_at) FROM activity_log WHERE user_id = u.id) < DATE_SUB(NOW(), INTERVAL ? DAY) OR (SELECT MAX(created_at) FROM activity_log WHERE user_id = u.id) IS NULL";
    $params[] = $days;
}

$whereClause = implode(' AND ', $where);

// Valid sort columns
$validSort = ['id','username', 'full_name', 'email', 'role', 'created_at', 'storage_quota', 'last_activity'];
if (!in_array($sortBy, $validSort)) $sortBy = 'created_at';
// $sortDir already normalized above

// Get users with last activity
$orderByColumn = ($sortBy === 'last_activity') ? 'last_activity' : "u.$sortBy";
$stmt = $db->prepare("
    SELECT 
        u.*,
        COALESCE(uf.is_enabled, 0) as twofa_enabled,
        COALESCE(uf.method, 'none') as twofa_method,
        (SELECT MAX(created_at) FROM activity_log WHERE user_id = u.id) as last_activity,
        COALESCE((SELECT SUM(file_size) FROM files WHERE user_id = u.id), 0) as used_storage,
        (SELECT COUNT(*) FROM files WHERE user_id = u.id) as file_count
    FROM users u
    LEFT JOIN user_2fa uf ON u.id = uf.user_id AND uf.is_enabled = 1
    WHERE $whereClause
    ORDER BY $orderByColumn $sortOrder
    LIMIT ? OFFSET ?
");
$params[] = $perPage;
$params[] = $offset;
$stmt->execute($params);
$users = $stmt->fetchAll();

// Count total
$stmt = $db->prepare("SELECT COUNT(*) FROM users u WHERE $whereClause");
$stmt->execute(array_slice($params, 0, -2));
$totalUsers = $stmt->fetchColumn();
$totalPages = ceil($totalUsers / $perPage);

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

// Calculate 2FA statistics
$stats2FA = [
    'total' => $totalUsers,
    'with_2fa' => 0,
    'totp' => 0,
    'duo' => 0,
    'required' => 0
];

foreach ($users as $u) {
    if ($u['twofa_enabled']) $stats2FA['with_2fa']++;
    if ($u['twofa_method'] === 'totp') $stats2FA['totp']++;
    if ($u['twofa_method'] === 'duo') $stats2FA['duo']++;
    if ($u['require_2fa']) $stats2FA['required']++;
}

renderPageStart('Gestión de Usuarios & 2FA', 'users', true);
renderHeader('Gestión de Usuarios', $user);
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
    /* bulk actions bar moved to global CSS */
    @keyframes slideUp {
        from { transform: translateX(-50%) translateY(100px); opacity: 0; }
        to { transform: translateX(-50%) translateY(0); opacity: 1; }
    }
.user-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
}
/* Responsive adjustments to save horizontal space */
.users-table-compact { table-layout: fixed; width: 100%; }
.users-table-compact th, .users-table-compact td {
    padding: 0.05rem 0.14rem;
    vertical-align: middle;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.users-table-compact .truncate {
    display: inline-block;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
}
.table-responsive { overflow-x: hidden; -webkit-overflow-scrolling: touch; }
.user-checkbox { width: 14px; height: 14px; }

/* column widths as percentages to keep table within viewport */
    .col-id { width: 4%; }
    .col-username { width: 12%; }
    .col-name { width: 12%; }
    .col-email { width: 18%; }
    .col-role { width: 6%; }
    .col-status { width: 6%; }
    .col-2fa { width: 6%; }
    .col-storage { width: 8%; }
    .col-created { width: 8%; }
    .col-last { width: 10%; }
    .col-actions { width: 10%; min-width: 6rem; }

/* Keep action buttons on a single line; shrink when needed to avoid wrapping
    and horizontal overflow. Use small paddings and prevent wrapping inside the
    actions column while allowing the table to scroll horizontally if necessary. */
.col-actions { min-width: 0; }
.col-actions > div { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: nowrap; white-space: nowrap; overflow: hidden; }
.col-actions .btn { padding: 2px 4px; font-size: 0.78rem; flex: 0 0 auto; }

/* Do not hide columns automatically; prefer horizontal scrolling or a compact toggle.
   Hiding columns by media queries removed to preserve functionality. */

/* Compact mode removed: always show all columns to preserve admin functionality */
</style>

<div class="content">
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <style>
    .stat-2fa-card {
        background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%);
        border: 1px solid var(--border-color);
        padding: 1.75rem;
        border-radius: 1rem;
        text-align: center;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        position: relative;
        overflow: hidden;
    }
    .stat-2fa-card::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--primary);
        transform: scaleX(0);
        transition: transform 0.3s;
    }
    .stat-2fa-card:hover::after {
        transform: scaleX(1);
    }
    .stat-2fa-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0,0,0,0.12);
    }
    </style>
    
    <!-- 2FA Statistics -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <div class="stat-2fa-card">
            <div style="font-size: 2.5rem; font-weight: 800; background: linear-gradient(135deg, #4a90e2, #50c878); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                <?php echo $stats2FA['with_2fa']; ?>/<?php echo $stats2FA['total']; ?>
            </div>
            <div style="color: var(--text-muted); margin-top: 0.5rem; font-weight: 600; font-size: 0.875rem;">Con 2FA Activo</div>
        </div>
        <div class="stat-2fa-card">
            <div style="font-size: 2.5rem; font-weight: 800; background: linear-gradient(135deg, #50c878, #4a90e2); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                <?php echo $stats2FA['totp']; ?>
            </div>
            <div style="color: var(--text-muted); margin-top: 0.5rem; font-weight: 600; font-size: 0.875rem;">📱 Usando TOTP</div>
        </div>
        <div class="stat-2fa-card">
            <div style="font-size: 2.5rem; font-weight: 800; background: linear-gradient(135deg, #667eea, #764ba2); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                <?php echo $stats2FA['duo']; ?>
            </div>
            <div style="color: var(--text-muted); margin-top: 0.5rem; font-weight: 600; font-size: 0.875rem;"><i class="fas fa-shield-alt"></i> Usando Duo</div>
        </div>
        <div class="stat-2fa-card">
            <div style="font-size: 2.5rem; font-weight: 800; background: linear-gradient(135deg, #f093fb, #f5576c); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                <?php echo $stats2FA['required']; ?>
            </div>
            <div style="color: var(--text-muted); margin-top: 0.5rem; font-weight: 600; font-size: 0.875rem;">⚠️ 2FA Obligatorio</div>
        </div>
    </div>
    
    <!-- Filters Card -->
    <div class="card" style="margin-bottom: 2rem;">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-filter"></i> Filtros y Búsqueda</h3>
        </div>
        <div class="card-body">
            <form method="GET" id="filterForm">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <label><?php echo t('search'); ?></label>
                        <input type="text" name="search" class="form-control" placeholder="Nombre, email o usuario..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div>
                        <label>Rol</label>
                        <select name="role" class="form-control">
                            <option value="">Todos</option>
                            <option value="admin" <?php echo $filterRole === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                            <option value="user" <?php echo $filterRole === 'user' ? 'selected' : ''; ?>>Usuario</option>
                        </select>
                    </div>
                    <div>
                        <label>Estado</label>
                        <select name="active" class="form-control">
                            <option value="">Todos</option>
                            <option value="yes" <?php echo $filterActive === 'yes' ? 'selected' : ''; ?>>Activos</option>
                            <option value="no" <?php echo $filterActive === 'no' ? 'selected' : ''; ?>>Inactivos</option>
                        </select>
                    </div>
                    <div>
                        <label>2FA</label>
                        <select name="twofa" class="form-control">
                            <option value="">Todos</option>
                            <option value="yes" <?php echo $filter2FA === 'yes' ? 'selected' : ''; ?>>Con 2FA</option>
                            <option value="no" <?php echo $filter2FA === 'no' ? 'selected' : ''; ?>>Sin 2FA</option>
                            <option value="required" <?php echo $filter2FA === 'required' ? 'selected' : ''; ?>>2FA Obligatorio</option>
                        </select>
                    </div>
                    <div>
                        <label>Inactivo desde</label>
                        <select name="inactive" class="form-control">
                            <option value="">Sin filtrar</option>
                            <option value="10" <?php echo $filterInactive === '10' ? 'selected' : ''; ?>>10 días</option>
                            <option value="30" <?php echo $filterInactive === '30' ? 'selected' : ''; ?>>30 días</option>
                            <option value="90" <?php echo $filterInactive === '90' ? 'selected' : ''; ?>>3 meses</option>
                            <option value="180" <?php echo $filterInactive === '180' ? 'selected' : ''; ?>>6 meses</option>
                            <option value="365" <?php echo $filterInactive === '365' ? 'selected' : ''; ?>>1 año</option>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary">Aplicar Filtros</button>
                    <a href="<?php echo BASE_URL; ?>/admin/users.php" class="btn btn-secondary">Limpiar</a>
                    <div style="margin-left: auto; display: flex; align-items: center; gap: 0.5rem;">
                        <label style="margin: 0; white-space: nowrap;">Por página:</label>
                        <select name="per_page" class="form-control" style="width: auto;" onchange="this.form.submit()">
                            <option value="10" <?php echo $perPage === 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $perPage === 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $perPage === 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $perPage === 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                            <!-- Compact view option removed: always display full columns -->
                    </div>
                </div>
            </form>
            
            <?php if ($search || $filterRole || $filterActive || $filter2FA || $filterInactive): ?>
                <div style="margin-top: 1rem;">
                    <strong>Filtros activos:</strong>
                    <?php if ($search): ?>
                        <span class="filter-badge">Búsqueda: <?php echo htmlspecialchars($search); ?></span>
                    <?php endif; ?>
                    <?php if ($filterRole): ?>
                        <span class="filter-badge">Rol: <?php echo $filterRole === 'admin' ? 'Admin' : 'Usuario'; ?></span>
                    <?php endif; ?>
                    <?php if ($filterActive): ?>
                        <span class="filter-badge">Estado: <?php echo $filterActive === 'yes' ? 'Activos' : 'Inactivos'; ?></span>
                    <?php endif; ?>
                    <?php if ($filter2FA): ?>
                        <span class="filter-badge">2FA: <?php echo $filter2FA === 'yes' ? 'Sí' : ($filter2FA === 'required' ? 'Obligatorio' : 'No'); ?></span>
                    <?php endif; ?>
                    <?php if ($filterInactive): ?>
                        <span class="filter-badge">Inactivo: +<?php echo $filterInactive; ?> días</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
        <div class="card-header card-header--padded card-header--flex">
            <h2 class="card-title" style="color: var(--text-on-primary); font-weight: 700; font-size: 1.5rem;"><i class="fas fa-users"></i> Usuarios (<?php echo number_format($totalUsers); ?>)</h2>
            <div style="display: flex; gap: 0.5rem;">
                <button type="button" class="btn btn-primary" onclick="toggleSelectAll()">
                    <i class="fas fa-check-square"></i> Seleccionar
                </button>
                <a href="<?php echo BASE_URL; ?>/admin/user_create.php" class="btn btn-success"><i class="fas fa-plus"></i> <?php echo t('create'); ?> <?php echo t('user'); ?></a>
            </div>
        </div>
        <div class="card-body">

            <?php if (empty($users)): ?>
                <p style="text-align: center; padding: 3rem; color: var(--text-muted);">No hay usuarios</p>
            <?php else: ?>
                <div class="table-responsive">
                    <form method="POST" id="bulkActionForm">
                        <input type="hidden" name="bulk_action" id="bulkActionInput">
                        <input type="hidden" name="select_all" id="selectAllFlag" value="0">
                        <input type="hidden" name="filters" id="bulkFilters" value="">
                        <table class="table users-table-compact">
                            <thead>
                                    <th style="width: 40px;">
                                        <input type="checkbox" id="selectAll" onchange="updateSelectAll(this)">
                                    </th>
                                    <th style="width: 56px;" class="col-id">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'id', 'dir' => ($sortBy === 'id' && $sortDir === 'asc') ? 'desc' : 'asc'])); ?>" class="sort-link">
                                            ID
                                            <?php if ($sortBy === 'id'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'asc' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort" style="opacity: 0.3;"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="col-username">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'username', 'dir' => ($sortBy === 'username' && $sortDir === 'asc') ? 'desc' : 'asc'])); ?>" class="sort-link">
                                            Usuario
                                            <?php if ($sortBy === 'username'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'asc' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort" style="opacity: 0.3;"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="col-name">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'full_name', 'dir' => ($sortBy === 'full_name' && $sortDir === 'asc') ? 'desc' : 'asc'])); ?>" class="sort-link">
                                            Nombre
                                            <?php if ($sortBy === 'full_name'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'asc' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort" style="opacity: 0.3;"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="col-email">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'email', 'dir' => ($sortBy === 'email' && $sortDir === 'asc') ? 'desc' : 'asc'])); ?>" class="sort-link">
                                            Email
                                            <?php if ($sortBy === 'email'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'asc' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort" style="opacity: 0.3;"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="col-role">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'role', 'dir' => ($sortBy === 'role' && $sortDir === 'asc') ? 'desc' : 'asc'])); ?>" class="sort-link">
                                            Rol
                                            <?php if ($sortBy === 'role'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'asc' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort" style="opacity: 0.3;"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="col-status">Estado</th>
                                    <th class="col-2fa" style="width: 120px;">2FA</th>
                                    <th class="col-storage">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'storage_quota', 'dir' => ($sortBy === 'storage_quota' && $sortDir === 'asc') ? 'desc' : 'asc'])); ?>" class="sort-link">
                                            Alm.
                                            <?php if ($sortBy === 'storage_quota'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'asc' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort" style="opacity: 0.3;"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="col-created">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'created_at', 'dir' => ($sortBy === 'created_at' && $sortDir === 'asc') ? 'desc' : 'asc'])); ?>" class="sort-link">
                                            Reg.
                                            <?php if ($sortBy === 'created_at'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'asc' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort" style="opacity: 0.3;"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="col-last">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'last_activity', 'dir' => ($sortBy === 'last_activity' && $sortDir === 'asc') ? 'desc' : 'asc'])); ?>" class="sort-link">
                                            Últ. Act.
                                            <?php if ($sortBy === 'last_activity'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'asc' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort" style="opacity: 0.3;"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th style="width: 240px; text-align: right;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                <tr id="user-row-<?php echo $u['id']; ?>">
                                    <td>
                                        <?php if ($u['id'] != $user['id']): ?>
                                            <input type="checkbox" name="user_ids[]" value="<?php echo $u['id']; ?>" class="user-checkbox">
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, 'Roboto Mono', 'Courier New', monospace; color: var(--text-muted);">
                                        <code><?php echo intval($u['id']); ?></code>
                                    </td>
                                    <td>
                                    <div class="truncate" style="font-weight: 500;" title="<?php echo htmlspecialchars($u['username']); ?>"><?php echo htmlspecialchars($u['username']); ?></div>
                                    <?php if ($u['is_ldap']): ?><div style="font-size: 0.8125rem; color: var(--text-muted);"><i class="fas fa-lock"></i> LDAP</div><?php endif; ?>
                                </td>
                                <td class="col-name">
                                    <div class="truncate" title="<?php echo htmlspecialchars($u['full_name'] ?: '-'); ?>" style="font-weight:500;">
                                        <?php echo htmlspecialchars($u['full_name'] ?: '-'); ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                                        <?php echo $u['file_count']; ?> archivos
                                    </div>
                                </td>
                                <td><div class="truncate" title="<?php echo htmlspecialchars($u['email'] ?? '-'); ?>"><?php echo htmlspecialchars($u['email'] ?? '-'); ?></div></td>
                                <td>
                                    <span class="badge <?php echo $u['role'] === 'admin' ? 'badge-primary' : 'badge-secondary'; ?>">
                                        <?php echo $u['role'] === 'admin' ? 'Administrador' : 'Usuario'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $u['is_active'] ? 'badge-success' : 'badge-secondary'; ?>" id="status-badge-<?php echo $u['id']; ?>">
                                        <?php echo $u['is_active'] ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($u['twofa_enabled']): ?>
                                        <?php if ($u['twofa_method'] === 'totp'): ?>
                                            <span class="badge badge-success" title="TOTP activo">📱 TOTP</span>
                                        <?php elseif ($u['twofa_method'] === 'duo'): ?>
                                            <span class="badge badge-info" title="Duo activo"><i class="fas fa-shield-alt"></i> Duo</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" title="Sin 2FA">Sin 2FA</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-storage">
                                    <?php 
                                    $usedGB = ($u['used_storage'] ?? 0) / 1024 / 1024 / 1024;
                                    $quotaGB = (isset($u['storage_quota']) && $u['storage_quota'] !== null) ? ($u['storage_quota'] / 1024 / 1024 / 1024) : null;
                                    $percentage = ($quotaGB !== null && $quotaGB > 0) ? ($usedGB / $quotaGB) * 100 : 0;
                                    ?>
                                    <div style="font-size: 0.875rem;">
                                        <?php echo number_format($usedGB, 2); ?> / <?php echo $quotaGB !== null ? number_format($quotaGB, 1) . ' GB' : '<strong>Ilimitado</strong>'; ?>
                                    </div>
                                    <?php if ($quotaGB !== null): ?>
                                        <div style="background: var(--bg-secondary); border-radius: 0.25rem; height: 6px; margin-top: 0.25rem; overflow: hidden;">
                                            <div style="background: <?php echo $percentage > 90 ? '#e74c3c' : ($percentage > 75 ? '#f39c12' : 'var(--primary)'); ?>; height: 100%; width: <?php echo min($percentage, 100); ?>%;"></div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="col-created">
                                    <div><?php echo date('d/m/Y', strtotime($u['created_at'])); ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo date('H:i', strtotime($u['created_at'])); ?></div>
                                </td>
                                <td class="col-last">
                                    <?php if ($u['last_activity']): ?>
                                        <?php 
                                        $lastActivity = strtotime($u['last_activity']);
                                        $daysDiff = floor((time() - $lastActivity) / 86400);
                                        ?>
                                        <div><?php echo date('d/m/Y', $lastActivity); ?></div>
                                        <div style="font-size: 0.75rem; color: <?php echo $daysDiff > 30 ? '#e74c3c' : 'var(--text-muted)'; ?>;">
                                            <?php 
                                            if ($daysDiff == 0) echo 'Hoy';
                                            elseif ($daysDiff == 1) echo 'Ayer';
                                            elseif ($daysDiff < 7) echo "Hace $daysDiff días";
                                            elseif ($daysDiff < 30) echo 'Hace ' . floor($daysDiff/7) . ' semanas';
                                            elseif ($daysDiff < 365) echo 'Hace ' . floor($daysDiff/30) . ' meses';
                                            else echo 'Hace ' . floor($daysDiff/365) . ' años';
                                            ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="color: var(--text-muted); font-style: italic;">Nunca</div>
                                    <?php endif; ?>
                                </td>
                                <td class="col-actions" style="text-align: right; white-space: nowrap;">
                                    <div style="display: flex; gap: 0.25rem; justify-content: flex-end;">
                                        <a href="<?php echo BASE_URL; ?>/admin/user_edit.php?id=<?php echo $u['id']; ?>" 
                                           class="btn btn-sm btn-outline" 
                                           title="Editar"><i class="fas fa-edit"></i></a>
                                        
                                        <?php if ($u['id'] != $user['id']): ?>
                                            <button type="button" onclick="toggleUserStatus(<?php echo $u['id']; ?>, <?php echo $u['is_active'] ? 'false' : 'true'; ?>)" 
                                                    class="btn btn-sm btn-outline" 
                                                    id="toggle-btn-<?php echo $u['id']; ?>"
                                                    title="<?php echo $u['is_active'] ? 'Desactivar' : 'Activar'; ?>">
                                                <?php echo $u['is_active'] ? '🚫' : '✅'; ?>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if (!$u['is_ldap']): ?>
                                            <button type="button" onclick="resetUserPassword(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>')" 
                                                                        class="btn btn-sm btn-outline" 
                                                                        title="<?php echo t('reset'); ?>">🔑</button>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($u['email']) || $u['twofa_enabled']): ?>
                                            <div class="dropdown" style="display: inline-block;">
                                                <button type="button" class="btn btn-sm btn-info dropdown-toggle" title="Gestionar 2FA"><i class="fas fa-lock"></i></button>
                                                <div class="dropdown-menu">
                                                    <?php if (!empty($u['email'])): ?>
                                                        <button type="button" onclick="setup2FA(<?php echo $u['id']; ?>, 'totp', '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>')" class="dropdown-item">📱 Setup TOTP</button>
                                                        <button type="button" onclick="setup2FA(<?php echo $u['id']; ?>, 'duo', '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>')" class="dropdown-item"><i class="fas fa-shield-alt"></i> Setup Duo</button>
                                                    <?php endif; ?>
                                                    <?php if ($u['twofa_enabled']): ?>
                                                        <?php if (!empty($u['email'])): ?>
                                                            <div class="dropdown-divider"></div>
                                                        <?php endif; ?>
                                                        <?php if ($u['twofa_method'] === 'totp' && !empty($u['email'])): ?>
                                                            <button type="button" onclick="send2FAEmail(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>')" class="dropdown-item"><i class="fas fa-envelope"></i> Enviar QR</button>
                                                        <?php endif; ?>
                                                        <button type="button" onclick="reset2FA(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>')" class="dropdown-item" style="color: var(--danger);">🔄 Desactivar 2FA</button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($u['id'] != $user['id']): ?>
                                            <button type="button" onclick="deleteUser(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>')" 
                                                    class="btn btn-sm btn-danger" 
                                                    title="Eliminar"><i class="fas fa-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                </div>

                <!-- Compact Bulk Actions Bar (match user UI style) -->
                <div class="bulk-actions-bar" id="bulkActionsBar" style="display: none;">
                    <span id="selectedCount">0</span>
                    <div style="display:inline-flex; align-items:center; gap:0.4rem; margin-left:0.5rem;">
                        <button type="button" class="btn btn-sm btn-success" onclick="executeBulkAction('activate')" title="<?php echo t('activate'); ?> <?php echo t('users'); ?>">
                            <i class="fas fa-check-circle"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-warning" onclick="executeBulkAction('deactivate')" title="<?php echo t('deactivate'); ?> <?php echo t('users'); ?>">
                            <i class="fas fa-ban"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-info" onclick="executeBulkAction('require_2fa')" title="Requerir 2FA">
                            <i class="fas fa-shield-alt"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="executeBulkAction('unrequire_2fa')" title="No requerir 2FA">
                            <i class="fas fa-shield-alt"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="executeBulkAction('delete')" title="Eliminar usuarios">
                            <i class="fas fa-trash"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline" onclick="cancelBulkSelection()" title="<?php echo t('cancel'); ?>">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <button type="button" class="btn btn-outline btn-outline--on-dark" id="selectAllMatchingBtn" style="margin-left:0.5rem; padding:0.35rem 0.6rem; font-size:0.85rem;">
                        <?php echo htmlspecialchars(t('select_all_matching', [$totalUsers])); ?>
                    </button>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php 
                    $paginationParams = $_GET;
                    unset($paginationParams['page']);
                    $queryString = http_build_query($paginationParams);
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&<?php echo $queryString; ?>">« Anterior</a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&<?php echo $queryString; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&<?php echo $queryString; ?>">Siguiente »</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo $auth->generateCsrfToken(); ?>';
// total number of users matching current filters (server-side)
var totalFilteredUsers = <?php echo intval($totalUsers); ?>;

// Bulk selection management
function updateSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.user-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    updateBulkActionsBar();
}

function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAll');
    selectAllCheckbox.checked = !selectAllCheckbox.checked;
    updateSelectAll(selectAllCheckbox);
}

document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.user-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBulkActionsBar);
    });
    const selBtn = document.getElementById('selectAllMatchingBtn');
    if (selBtn) selBtn.addEventListener('click', selectAllFiltered);
});

// Compact view feature removed — all columns are always visible for admin users.

function updateBulkActionsBar() {
    const checkboxes = document.querySelectorAll('.user-checkbox:checked');
    const count = checkboxes.length;
    const bar = document.getElementById('bulkActionsBar');
    const countSpan = document.getElementById('selectedCount');
    
    if (count > 0) {
        bar.style.display = 'flex';
        countSpan.textContent = count;
    } else {
        bar.style.display = 'none';
    }
    
    // Update select all checkbox state
    const allCheckboxes = document.querySelectorAll('.user-checkbox');
    const selectAllCheckbox = document.getElementById('selectAll');
    selectAllCheckbox.checked = allCheckboxes.length > 0 && count === allCheckboxes.length;
}

function cancelBulkSelection() {
    const checkboxes = document.querySelectorAll('.user-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateBulkActionsBar();
    // Clear select_all mode and filters
    const selectAllFlag = document.getElementById('selectAllFlag'); if (selectAllFlag) selectAllFlag.value = '0';
    const filtersInput = document.getElementById('bulkFilters'); if (filtersInput) filtersInput.value = '';
}

function executeBulkAction(action) {
    const checkboxes = document.querySelectorAll('.user-checkbox:checked');
    const userIds = Array.from(checkboxes).map(cb => cb.value);
    const selectAllFlag = document.getElementById('selectAllFlag');
    const selectAllActive = selectAllFlag && selectAllFlag.value === '1';

    // If not in select-all mode and no individual users selected, warn.
    if (!selectAllActive && userIds.length === 0) {
        Mimir.showAlert(<?php echo json_encode(t('no_users_selected')); ?>, 'warning');
        return;
    }

    // Use totalFilteredUsers when select-all is active, otherwise number of checked boxes
    const count = selectAllActive ? totalFilteredUsers : userIds.length;
    let message = '';
    switch(action) {
        case 'activate':
            message = <?php echo json_encode(t('confirm_activate_n_users')); ?>.replace('%s', count);
            break;
        case 'deactivate':
            message = <?php echo json_encode(t('confirm_deactivate_n_users')); ?>.replace('%s', count);
            break;
        case 'require_2fa':
            message = <?php echo json_encode(t('confirm_require_2fa_n_users')); ?>.replace('%s', count);
            break;
        case 'unrequire_2fa':
            message = <?php echo json_encode(t('confirm_unrequire_2fa_n_users')); ?>.replace('%s', count);
            break;
        case 'delete':
            message = <?php echo json_encode(t('confirm_delete_n_users')); ?>.replace('%s', count);
            break;
    }
    
    if (confirm(message)) {
        // If select_all mode is active, set hidden inputs accordingly
        const selectAllFlag = document.getElementById('selectAllFlag');
        const filtersInput = document.getElementById('bulkFilters');
        if (selectAllFlag && selectAllFlag.value === '1') {
            // include current query string (without leading ?)
            filtersInput.value = window.location.search.length ? window.location.search.substring(1) : '';
        } else {
            // ensure filters empty when not selecting all
            if (filtersInput) filtersInput.value = '';
        }
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkActionForm').submit();
    }
}

// Select all matching (filtered) users
function selectAllFiltered() {
    // mark that user wants to select all users matching filters
    document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = true);
    const selectAllFlag = document.getElementById('selectAllFlag'); if (selectAllFlag) selectAllFlag.value = '1';
    document.getElementById('selectedCount').textContent = '<?php echo $totalUsers; ?>';
    document.getElementById('bulkActionsBar').style.display = 'flex';
}

function toggleUserStatus(userId, activate) {
    if (!confirm(activate ? <?php echo json_encode(t('confirm_activate_user')); ?> : <?php echo json_encode(t('confirm_deactivate_user')); ?>)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'toggle_active');
    formData.append('user_id', userId);
    formData.append('csrf_token', csrfToken);
    
    fetch('<?php echo BASE_URL; ?>/admin/user_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(Mimir.parseJsonResponse)
    .then(data => {
        if (data.success) {
            const badge = document.getElementById('status-badge-' + userId);
            const button = document.getElementById('toggle-btn-' + userId);
            
            if (data.is_active) {
                badge.className = 'badge badge-success';
                badge.textContent = 'Activo';
                button.textContent = '🚫';
                button.title = '<?php echo t('deactivate'); ?>';
                button.onclick = () => toggleUserStatus(userId, false);
            } else {
                badge.className = 'badge badge-secondary';
                badge.textContent = 'Inactivo';
                button.textContent = '✅';
                button.title = '<?php echo t('activate'); ?>';
                button.onclick = () => toggleUserStatus(userId, true);
            }
            
            Mimir.showAlert(data.message, 'success');
        } else {
            Mimir.showAlert(data.message, 'error');
        }
    })
    .catch(error => {
        Mimir.showAlert(<?php echo json_encode(t('error_change_user_state')); ?>, 'error');
        console.error('Error:', error);
    });
}

function resetUserPassword(userId, username) {
    // Create modal HTML
    const modalHtml = `
        <div id="passwordResetModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 10000;">
            <div style="background: #ffffff; padding: 2rem; border-radius: 1rem; max-width: 500px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                <h3 style="margin: 0 0 1.5rem 0; color: #1a1a1a;"><i class="fas fa-key"></i> Resetear Contraseña</h3>
                <p style="color: #666; margin-bottom: 1.5rem;">Usuario: <strong style="color: #1a1a1a;">${username}</strong></p>
                
                <div style="margin-bottom: 1.5rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; padding: 0.75rem; background: #f5f5f5; border-radius: 0.5rem; margin-bottom: 0.5rem; border: 2px solid #e0e0e0;">
                        <input type="radio" name="password_type" value="random" checked onchange="togglePasswordInput()">
                        <span style="color: #1a1a1a;"><i class="fas fa-random"></i> Generar contraseña aleatoria</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; padding: 0.75rem; background: #f5f5f5; border-radius: 0.5rem; border: 2px solid #e0e0e0;">
                        <input type="radio" name="password_type" value="custom" onchange="togglePasswordInput()">
                        <span style="color: #1a1a1a;"><i class="fas fa-edit"></i> Establecer contraseña personalizada</span>
                    </label>
                </div>
                
                <div id="customPasswordField" style="display: none; margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; color: #333; font-weight: 600;">Nueva contraseña:</label>
                    <input type="text" id="customPassword" class="form-control" placeholder="Mínimo 6 caracteres" style="width: 100%; padding: 0.75rem; border: 2px solid #e0e0e0; border-radius: 0.5rem; font-size: 1rem; color: #1a1a1a; background: #fff;">
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" onclick="closePasswordModal()" class="btn btn-secondary" style="background: #6c757d; color: white; border: none; padding: 0.625rem 1.25rem; border-radius: 0.5rem; cursor: pointer; font-weight: 600;">
                        <i class="fas fa-times"></i> <?php echo t('cancel'); ?>
                    </button>
                    <button type="button" onclick="executePasswordReset(${userId}, '${username}')" class="btn btn-primary" style="background: #4a90e2; color: white; border: none; padding: 0.625rem 1.25rem; border-radius: 0.5rem; cursor: pointer; font-weight: 600;">
                        <i class="fas fa-check"></i> Resetear
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function togglePasswordInput() {
    const customField = document.getElementById('customPasswordField');
    const isCustom = document.querySelector('input[name="password_type"]:checked').value === 'custom';
    customField.style.display = isCustom ? 'block' : 'none';
    if (isCustom) {
        document.getElementById('customPassword').focus();
    }
}

function closePasswordModal() {
    const modal = document.getElementById('passwordResetModal');
    if (modal) modal.remove();
}

function executePasswordReset(userId, username) {
    const passwordType = document.querySelector('input[name="password_type"]:checked').value;
    const customPassword = document.getElementById('customPassword').value;
    
    if (passwordType === 'custom') {
        if (!customPassword || customPassword.length < 6) {
            Mimir.showAlert(<?php echo json_encode(t('error_password_min_length')); ?> || 'La contraseña debe tener al menos 6 caracteres', 'error');
            return;
        }
    }
    
    const formData = new FormData();
    formData.append('action', 'reset_password');
    formData.append('user_id', userId);
    formData.append('csrf_token', csrfToken);
    
    if (passwordType === 'custom') {
        formData.append('new_password', customPassword);
    }
    
    closePasswordModal();
    
    fetch('<?php echo BASE_URL; ?>/admin/user_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(Mimir.parseJsonResponse)
    .then(data => {
        if (data.success) {
            let message = `Contraseña actualizada para "${username}"\n\n`;
            if (passwordType === 'random') {
                message += `Nueva contraseña: ${data.new_password}\n\n⚠️ Guarda esta contraseña, no se mostrará de nuevo.`;
            } else {
                message += `Se ha establecido la contraseña personalizada.`;
            }
            alert(message);
            Mimir.showAlert(data.message, 'success');
        } else {
            Mimir.showAlert(data.message, 'error');
        }
    })
    .catch(error => {
        Mimir.showAlert(<?php echo json_encode(t('error_reset_password')); ?>, 'error');
        console.error('Error:', error);
    });
}

function deleteUser(userId, username) {
    if (!confirm(<?php echo json_encode(t('confirm_delete_user_named')); ?>.replace('%s', username))) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('user_id', userId);
    formData.append('csrf_token', csrfToken);
    
    fetch('<?php echo BASE_URL; ?>/admin/user_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                location.href = '<?php echo BASE_URL; ?>/admin/users.php?success=' + encodeURIComponent(data.message);
            } else {
                Mimir.showAlert(data.message, 'error');
            }
        } catch (e) {
            // If JSON parse fails but response received, assume success and reload
            console.log('Parse error but operation likely succeeded, reloading...');
            location.href = '<?php echo BASE_URL; ?>/admin/users.php?success=Usuario eliminado correctamente';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Mimir.showAlert(<?php echo json_encode(t('error_delete_user')); ?>, 'error');
    });
}

function setup2FA(userId, method, username) {
    if (!confirm(<?php echo json_encode(t('confirm_setup_2fa')); ?>.replace('{method}', method.toUpperCase()).replace('{username}', username))) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', method === 'totp' ? 'setup_totp_ajax' : 'setup_duo_ajax');
    formData.append('user_id', userId);
    formData.append('csrf_token', csrfToken);
    
    fetch('<?php echo BASE_URL; ?>/admin/user_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(Mimir.parseJsonResponse)
    .then(data => {
        if (data.success) {
            if (method === 'totp') {
                // Show QR code and backup codes in modal
                showTOTPSetupModal(data.username, data.qr_code, data.backup_codes, userId);
            } else {
                // For Duo, reload immediately without delay
                location.href = location.href.split('?')[0] + '?success=' + encodeURIComponent(data.message);
            }
        } else {
            Mimir.showAlert(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Mimir.showAlert(<?php echo json_encode(t('error_configure_2fa')); ?>, 'error');
    });
}

function showTOTPSetupModal(username, qrCode, backupCodes, userId) {
    const backupCodesHtml = backupCodes.map(code => 
        `<div style="background: #f5f5f5; padding: 8px; font-family: monospace; border-radius: 4px; text-align: center; border: 1px solid #e0e0e0;">${code}</div>`
    ).join('');
    
    const modalHtml = `
        <div id="totpSetupModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 10000; overflow-y: auto;">
            <div style="background: #ffffff; padding: 2rem; border-radius: 1rem; max-width: 600px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3); margin: 2rem;">
                <h3 style="margin: 0 0 1rem 0; color: #1a1a1a;"><i class="fas fa-qrcode"></i> Configuración TOTP para ${username}</h3>
                
                <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 0.5rem; margin-bottom: 1.5rem;">
                    <p style="color: #333; margin-bottom: 1rem;"><strong>1. Escanea este código QR con tu app autenticadora:</strong></p>
                    <div style="text-align: center; background: white; padding: 1rem; border-radius: 0.5rem;">
                        <img src="${qrCode}" alt="QR Code" style="max-width: 250px; height: auto;">
                    </div>
                    <p style="color: #666; font-size: 0.875rem; margin-top: 0.5rem; text-align: center;">
                        <i class="fas fa-mobile-alt"></i> Google Authenticator, Authy, Microsoft Authenticator, etc.
                    </p>
                </div>
                
                <div style="background: #fff3cd; padding: 1rem; border-radius: 0.5rem; border-left: 4px solid #ffc107; margin-bottom: 1.5rem;">
                    <p style="color: #856404; margin: 0; font-size: 0.875rem;">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Importante:</strong> Guarda los códigos de respaldo en un lugar seguro. Podrás usarlos si pierdes acceso a tu app autenticadora.
                    </p>
                </div>
                
                <div style="margin-bottom: 1.5rem;">
                    <p style="color: #333; margin-bottom: 0.75rem; font-weight: 600;"><strong>2. Códigos de Respaldo:</strong></p>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                        ${backupCodesHtml}
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: space-between; align-items: center;">
                    <button type="button" onclick="sendTOTPEmail(${userId}, '${username}')" class="btn btn-secondary" style="background: #28a745; color: white; border: none; padding: 0.625rem 1.25rem; border-radius: 0.5rem; cursor: pointer; font-weight: 600;">
                        <i class="fas fa-envelope"></i> Enviar por Email
                    </button>
                    <button type="button" onclick="closeTOTPModal()" class="btn btn-primary" style="background: #4a90e2; color: white; border: none; padding: 0.625rem 1.5rem; border-radius: 0.5rem; cursor: pointer; font-weight: 600;">
                        <i class="fas fa-check"></i> Entendido
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function sendTOTPEmail(userId, username) {
    const formData = new FormData();
    formData.append('action', 'send_totp_email');
    formData.append('user_id', userId);
    formData.append('csrf_token', csrfToken);
    
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
    
    fetch('<?php echo BASE_URL; ?>/admin/user_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(Mimir.parseJsonResponse)
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (data.success) {
            Mimir.showAlert(data.message, 'success');
        } else {
            Mimir.showAlert(data.message, 'error');
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        Mimir.showAlert(<?php echo json_encode(t('error_send_email')); ?>, 'error');
        console.error('Error:', error);
    });
}

function closeTOTPModal() {
    const modal = document.getElementById('totpSetupModal');
    if (modal) modal.remove();
    Mimir.showAlert(<?php echo json_encode(t('2fa_totp_configured')); ?>, 'success');
    setTimeout(() => {
        location.reload(true); // Force reload from server
    }, 1000);
}

function reset2FA(userId, username) {
    if (!confirm(<?php echo json_encode(t('confirm_disable_2fa_user_named')); ?>.replace('%s', username))) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'reset_2fa');
    formData.append('user_id', userId);
    formData.append('csrf_token', csrfToken);
    
    fetch('<?php echo BASE_URL; ?>/admin/2fa_management.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(Mimir.parseJsonResponse)
    .then(data => {
        if (data.success) {
            Mimir.showAlert(<?php echo json_encode(t('2fa_disabled_success')); ?>, 'success');
            setTimeout(() => {
                location.reload(true); // Force reload from server
            }, 1000);
        } else {
            Mimir.showAlert(data.message || <?php echo json_encode(t('error_disable_2fa')); ?>, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Mimir.showAlert(<?php echo json_encode(t('error_disable_2fa')); ?> || 'Error al desactivar 2FA', 'error');
    });
}

function send2FAEmail(userId, username) {
    if (!confirm(<?php echo json_encode(t('confirm_send_qr_user_named')); ?>.replace('%s', username))) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'send_qr_email');
    formData.append('user_id', userId);
    formData.append('csrf_token', csrfToken);
    
    fetch('<?php echo BASE_URL; ?>/admin/2fa_management.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        Mimir.showAlert(<?php echo json_encode(t('email_sent_success')); ?>, 'success');
    })
    .catch(error => {
        console.error('Error:', error);
        Mimir.showAlert(<?php echo json_encode(t('error_send_email')); ?>, 'error');
    });
}

// Dropdown functionality
function initDropdowns() {
    document.querySelectorAll('.dropdown-toggle').forEach(button => {
        // Remove existing listeners to avoid duplicates
        button.replaceWith(button.cloneNode(true));
    });
    
    document.querySelectorAll('.dropdown-toggle').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const dropdown = this.parentElement;
            const isOpen = dropdown.classList.contains('open');
            
            // Close all dropdowns
            document.querySelectorAll('.dropdown').forEach(d => d.classList.remove('open'));
            
            // Toggle current
            if (!isOpen) {
                dropdown.classList.add('open');
                
                // Position the dropdown menu
                const menu = dropdown.querySelector('.dropdown-menu');
                if (menu) {
                    const rect = this.getBoundingClientRect();
                    menu.style.top = (rect.bottom + window.scrollY) + 'px';
                    menu.style.left = (rect.right - menu.offsetWidth + window.scrollX) + 'px';
                }
            }
        });
    });
}

// Initialize on load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDropdowns);
} else {
    initDropdowns();
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    // Don't close if clicking inside a dropdown menu
    if (!e.target.closest('.dropdown-menu')) {
        document.querySelectorAll('.dropdown').forEach(d => d.classList.remove('open'));
    }
});

// Close dropdown after clicking a dropdown item
document.addEventListener('click', function(e) {
    if (e.target.closest('.dropdown-item')) {
        setTimeout(() => {
            document.querySelectorAll('.dropdown').forEach(d => d.classList.remove('open'));
        }, 100);
    }
});
</script>

<style>
.dropdown {
    position: relative;
}

.dropdown-menu {
    display: none;
    position: fixed;
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 0.375rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    min-width: 180px;
    z-index: 9999;
    margin-top: 0.25rem;
}

.dropdown.open .dropdown-menu {
    display: block;
}

.dropdown-item {
    display: block;
    width: 100%;
    padding: 0.5rem 1rem;
    text-align: left;
    background: none;
    border: none;
    color: var(--text-primary);
    cursor: pointer;
    transition: background 0.2s;
    white-space: nowrap;
}

.dropdown-item:hover {
    background: var(--bg-hover);
}

.dropdown-item:first-child {
    border-radius: 0.375rem 0.375rem 0 0;
}

.dropdown-item:last-child {
    border-radius: 0 0 0.375rem 0.375rem;
}

.dropdown-divider {
    height: 1px;
    background: var(--border-color);
    margin: 0.25rem 0;
}

.dropdown-toggle::after {
    content: ' ▼';
    font-size: 0.7em;
}
</style>

<?php renderPageEnd(); ?>
