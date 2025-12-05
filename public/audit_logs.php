<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireAdmin();

// Filters
$user_filter = $_GET['user'] ?? '';
$action_filter = $_GET['action'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Build filters
$filters = [];
if ($user_filter) $filters['user_id'] = $user_filter;
if ($action_filter) $filters['action'] = $action_filter;
if ($date_from) $filters['date_from'] = $date_from;
if ($date_to) $filters['date_to'] = $date_to;

// Get logs
$logs = AuditLog::getLogs($filters, $per_page, $offset);
$total_logs = AuditLog::getLogsCount($filters);
$total_pages = ceil($total_logs / $per_page);

// Get users for filter
$db = Database::getInstance()->getConnection();
$users = $db->query("SELECT id, username FROM users ORDER BY username")->fetchAll();

// Get unique actions
$actions = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Audit Logs';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="css/extracted/audit_logs.css">
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>
        
        <div class="admin-content">
                <h1>📋 Audit Logs</h1>
                <p class="small-muted mb-1">View all system activity and user actions</p>

                <!-- Filters -->
                <div class="chart-container mb-1">
                    <form method="GET" class="form-inline form-grid">
                        <div class="form-group">
                            <label class="label-strong">User</label>
                            <select name="user" class="form-control">
                                <option value="">All Users</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>" <?php echo $user_filter == $user['id'] ? 'selected' : ''; ?>>
                                        <?php echo escapeHtml($user['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="label-strong">Action</label>
                            <select name="action" class="form-control">
                                <option value="">All Actions</option>
                                <?php foreach ($actions as $action): ?>
                                    <option value="<?php echo $action; ?>" <?php echo $action_filter == $action ? 'selected' : ''; ?>>
                                        <?php echo escapeHtml($action); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="label-strong">Date From</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo escapeHtml($date_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="label-strong">Date To</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo escapeHtml($date_to); ?>">
                        </div>
                        
                        <div class="pagination-actions">
                            <button type="submit" class="btn btn-primary">🔍 Filter</button>
                            <a href="audit_logs.php" class="btn btn-secondary">Clear</a>
                        </div>
                    </form>
                </div>

                <!-- Results Info -->
                <div class="small-muted mb-1 results-info">
                    Showing <?php echo number_format(min($offset + 1, $total_logs)); ?> - <?php echo number_format(min($offset + $per_page, $total_logs)); ?> of <?php echo number_format($total_logs); ?> logs
                </div>

                <!-- Logs Table -->
                <div class="chart-container">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th class="w-50">ID</th>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($logs) > 0): ?>
                                <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo $log['id']; ?></td>
                                    <td class="time-muted time-nowrap">
                                        <?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?>
                                    </td>
                                    <td>
                                        <strong><?php echo escapeHtml($log['username'] ?? 'System'); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: <?php 
                                            if (strpos($log['action'], 'login') !== false) echo '#28a745';
                                            elseif (strpos($log['action'], 'delete') !== false) echo '#dc3545';
                                            elseif (strpos($log['action'], 'create') !== false) echo '#17a2b8';
                                            elseif (strpos($log['action'], 'upload') !== false) echo '#007bff';
                                            else echo '#6c757d';
                                        ?>">
                                            <?php echo escapeHtml($log['action']); ?>
                                        </span>
                                    </td>
                                    <td class="td-ellipsis">
                                        <?php echo escapeHtml($log['details'] ?? '-'); ?>
                                    </td>
                                    <td class="td-small"><?php echo escapeHtml($log['ip_address'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="no-logs">
                                        No logs found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div style="display: flex; justify-content: center; gap: 5px; margin-top: 20px;">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $user_filter ? '&user='.$user_filter : ''; ?><?php echo $action_filter ? '&action='.$action_filter : ''; ?><?php echo $date_from ? '&date_from='.$date_from : ''; ?><?php echo $date_to ? '&date_to='.$date_to : ''; ?>" class="btn btn-secondary">← Previous</a>
                    <?php endif; ?>
                    
                    <span style="padding: 10px 20px; background: #f8f9fa; border-radius: 4px;">
                        Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                    </span>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $user_filter ? '&user='.$user_filter : ''; ?><?php echo $action_filter ? '&action='.$action_filter : ''; ?><?php echo $date_from ? '&date_from='.$date_from : ''; ?><?php echo $date_to ? '&date_to='.$date_to : ''; ?>" class="btn btn-secondary">Next →</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </div>
</body>
</html>
