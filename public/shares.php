<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireLogin();

$userId = Auth::getUserId();
$shareManager = new ShareManager();

// Handle share deletion
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_share') {
        $shareId = $_POST['share_id'] ?? null;
        if ($shareId) {
            try {
                $shareManager->deleteShare($shareId, $userId);
                $message = 'Share deleted successfully';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Failed to delete share: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($_POST['action'] === 'deactivate_share') {
        $shareId = $_POST['share_id'] ?? null;
        if ($shareId) {
            try {
                $shareManager->deactivateShare($shareId, $userId);
                $message = 'Share deactivated successfully';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Failed to deactivate share: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

$shares = $shareManager->getUserShares($userId);
$siteName = SystemConfig::get('site_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml($siteName); ?> - My Shares</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="navbar">
        <div class="navbar-brand"><?php echo escapeHtml($siteName); ?></div>
        <div class="navbar-menu">
            <a href="dashboard.php">My Files</a>
            <a href="shares.php" class="active">Shares</a>
            <?php if (Auth::isAdmin()): ?>
            <a href="admin.php">Admin</a>
            <?php endif; ?>
            <a href="logout.php">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="main-content">
            <h1>My Shares</h1>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>"><?php echo escapeHtml($message); ?></div>
            <?php endif; ?>
            
            <?php if (empty($shares)): ?>
                <p class="empty-message">No shares yet. <a href="dashboard.php">Upload and share files</a></p>
            <?php else: ?>
                <table class="file-table">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Link</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shares as $share): ?>
                        <tr>
                            <td><?php echo escapeHtml($share['original_filename']); ?></td>
                            <td>
                                <?php if ($share['share_type'] === 'time'): ?>
                                    Time-based (expires: <?php echo date('Y-m-d', strtotime($share['expires_at'])); ?>)
                                <?php else: ?>
                                    Downloads (<?php echo $share['current_downloads']; ?>/<?php echo $share['max_downloads']; ?>)
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($share['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo timeAgo($share['created_at']); ?></td>
                            <td>
                                <?php if ($share['is_active']): ?>
                                    <input type="text" value="<?php echo BASE_URL . '/share.php?token=' . escapeHtml($share['share_token']); ?>" readonly onclick="this.select()" style="width: 300px;">
                                <?php else: ?>
                                    <span class="text-muted">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($share['is_active']): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="deactivate_share">
                                        <input type="hidden" name="share_id" value="<?php echo $share['id']; ?>">
                                        <button type="submit" class="btn btn-sm" onclick="return confirm('Deactivate this share?')">Deactivate</button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_share">
                                    <input type="hidden" name="share_id" value="<?php echo $share['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this share?')">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="js/script.js"></script>
</body>
</html>
