<?php
// Redirect to new unified admin dashboard
header('Location: admin_dashboard.php');
exit;

// Handle configuration updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_config') {
        $configs = $_POST['config'] ?? [];
        foreach ($configs as $key => $value) {
            $type = $_POST['config_type'][$key] ?? 'string';
            SystemConfig::set($key, $value, $type);
        }
        $message = 'Configuration updated successfully';
        $messageType = 'success';
        SystemConfig::clearCache();
    } elseif ($_POST['action'] === 'update_user') {
        $userId = $_POST['user_id'] ?? null;
        $role = $_POST['role'] ?? 'user';
        $quota = $_POST['storage_quota'] ?? 1073741824;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        if ($userId) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("UPDATE users SET role = ?, storage_quota = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$role, $quota, $isActive, $userId]);
            $message = 'User updated successfully';
            $messageType = 'success';
            
            AuditLog::log(Auth::getUserId(), 'user_updated', 'user', $userId, "Admin updated user settings");
        }
    }
}

// Get statistics
$db = Database::getInstance()->getConnection();

$stats = [];
$stmt = $db->query("SELECT COUNT(*) as total FROM users");
$stats['total_users'] = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM files");
$stats['total_files'] = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COALESCE(SUM(file_size), 0) as total FROM files");
$stats['total_storage'] = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM public_shares WHERE is_active = 1");
$stats['active_shares'] = $stmt->fetch()['total'];

// Get users
$users = [];
if ($tab === 'users') {
    $stmt = $db->query("SELECT * FROM users ORDER BY created_at DESC");
    $users = $stmt->fetchAll();
}

// Get configuration
$configs = [];
if ($tab === 'settings') {
    $configs = SystemConfig::getAll();
}

// Get audit logs
$logs = [];
if ($tab === 'audit') {
    $page = $_GET['page'] ?? 1;
    $perPage = 50;
    $offset = ($page - 1) * $perPage;
    $logs = AuditLog::getLogs([], $perPage, $offset);
    $totalLogs = AuditLog::getLogsCount([]);
    $totalPages = ceil($totalLogs / $perPage);
}

$siteName = SystemConfig::get('site_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml($siteName); ?> - Admin Panel</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="navbar">
        <div class="navbar-brand"><?php echo escapeHtml($siteName); ?> Admin</div>
        <div class="navbar-menu">
            <a href="dashboard.php">My Files</a>
            <a href="shares.php">Shares</a>
            <a href="admin.php" class="active">Admin</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="admin-panel">
            <div class="admin-sidebar">
                <a href="admin_dashboard.php">📊 Dashboard</a>
                <a href="users.php">👥 Users</a>
                <a href="?tab=settings" class="<?php echo $tab === 'settings' ? 'active' : ''; ?>">⚙️ Settings</a>
                <a href="ldap_config.php">🔐 LDAP / AD Config</a>
                <a href="?tab=audit" class="<?php echo $tab === 'audit' ? 'active' : ''; ?>">📋 Audit Log</a>
            </div>
            
            <div class="admin-content">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?>"><?php echo escapeHtml($message); ?></div>
                <?php endif; ?>
                
                <?php if ($tab === 'dashboard'): ?>
                    <h1>Dashboard</h1>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <h3>Total Users</h3>
                            <p class="stat-value"><?php echo $stats['total_users']; ?></p>
                        </div>
                        <div class="stat-card">
                            <h3>Total Files</h3>
                            <p class="stat-value"><?php echo $stats['total_files']; ?></p>
                        </div>
                        <div class="stat-card">
                            <h3>Total Storage</h3>
                            <p class="stat-value"><?php echo formatBytes($stats['total_storage']); ?></p>
                        </div>
                        <div class="stat-card">
                            <h3>Active Shares</h3>
                            <p class="stat-value"><?php echo $stats['active_shares']; ?></p>
                        </div>
                    </div>
                
                <?php elseif ($tab === 'users'): ?>
                    <h1>User Management</h1>
                    <table class="file-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Storage</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo escapeHtml($user['username']); ?></td>
                                <td><?php echo escapeHtml($user['email']); ?></td>
                                <td><?php echo escapeHtml($user['role']); ?></td>
                                <td><?php echo formatBytes($user['storage_used']); ?> / <?php echo formatBytes($user['storage_quota']); ?></td>
                                <td>
                                    <?php if ($user['is_active']): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo timeAgo($user['created_at']); ?></td>
                                <td>
                                    <button class="btn btn-sm" onclick="editUser(<?php echo $user['id']; ?>)">Edit</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                
                <?php elseif ($tab === 'settings'): ?>
                    <h1>System Settings</h1>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_config">
                        
                        <?php foreach ($configs as $config): ?>
                        <div class="form-group">
                            <label for="config_<?php echo escapeHtml($config['config_key']); ?>">
                                <?php echo escapeHtml(ucwords(str_replace('_', ' ', $config['config_key']))); ?>
                            </label>
                            <?php if (!empty($config['description'])): ?>
                                <p class="help-text"><?php echo escapeHtml($config['description']); ?></p>
                            <?php endif; ?>
                            
                            <?php if ($config['config_type'] === 'boolean'): ?>
                                <input type="checkbox" 
                                       id="config_<?php echo escapeHtml($config['config_key']); ?>" 
                                       name="config[<?php echo escapeHtml($config['config_key']); ?>]" 
                                       value="true"
                                       <?php echo ($config['config_value'] === 'true' || $config['config_value'] === '1') ? 'checked' : ''; ?>>
                            <?php else: ?>
                                <input type="text" 
                                       id="config_<?php echo escapeHtml($config['config_key']); ?>" 
                                       name="config[<?php echo escapeHtml($config['config_key']); ?>]" 
                                       value="<?php echo escapeHtml($config['config_value']); ?>">
                            <?php endif; ?>
                            
                            <input type="hidden" 
                                   name="config_type[<?php echo escapeHtml($config['config_key']); ?>]" 
                                   value="<?php echo escapeHtml($config['config_type']); ?>">
                        </div>
                        <?php endforeach; ?>
                        
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </form>
                
                <?php elseif ($tab === 'audit'): ?>
                    <h1>Audit Log</h1>
                    <table class="file-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Entity</th>
                                <th>Details</th>
                                <th>IP Address</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo $log['id']; ?></td>
                                <td><?php echo escapeHtml($log['username'] ?? 'System'); ?></td>
                                <td><?php echo escapeHtml($log['action']); ?></td>
                                <td><?php echo escapeHtml($log['entity_type']); ?> #<?php echo $log['entity_id']; ?></td>
                                <td><?php echo escapeHtml($log['details']); ?></td>
                                <td><?php echo escapeHtml($log['ip_address']); ?></td>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if (isset($totalPages) && $totalPages > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="?tab=audit&page=<?php echo $i; ?>" class="<?php echo ($page == $i) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="js/script.js"></script>
    <script>
    function editUser(userId) {
        // Simple implementation - in production, use a modal
        alert('User edit functionality - would open modal for user ID: ' + userId);
    }
    </script>
</body>
</html>
