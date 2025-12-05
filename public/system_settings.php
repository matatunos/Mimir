<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireAdmin();

$message = '';
$messageType = '';

// Handle configuration updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_config') {
        SystemConfig::set('site_name', $_POST['site_name'] ?? APP_NAME);
        SystemConfig::set('allow_registration', isset($_POST['allow_registration']) ? 1 : 0);
        SystemConfig::set('default_storage_quota', intval($_POST['default_storage_quota'] ?? 1) * 1073741824);
        SystemConfig::set('max_file_size', intval($_POST['max_file_size'] ?? 100) * 1048576);
        SystemConfig::set('max_share_time_days', intval($_POST['max_share_time_days'] ?? 30));
        SystemConfig::set('site_logo', $_POST['site_logo'] ?? '');
        SystemConfig::set('footer_links', $_POST['footer_links'] ?? '[]', 'json');
        $message = 'Configuration updated successfully';
        $messageType = 'success';
        AuditLog::log(Auth::getUserId(), 'system_config_updated', 'system', null, 'System configuration updated');
    }
}

// Get current configuration
$config = [
    'site_name' => SystemConfig::get('site_name', APP_NAME),
    'allow_registration' => SystemConfig::get('allow_registration', false),
    'default_storage_quota' => SystemConfig::get('default_storage_quota', 1073741824) / 1073741824,
    'max_file_size' => SystemConfig::get('max_file_size', MAX_FILE_SIZE_DEFAULT) / 1048576,
    'max_share_time_days' => SystemConfig::get('max_share_time_days', MAX_SHARE_TIME_DAYS_DEFAULT),
    'site_logo' => SystemConfig::get('site_logo', ''),
    'footer_links' => SystemConfig::get('footer_links', []),
];

$pageTitle = 'System Settings';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="css/settings.css">
    <link rel="stylesheet" href="css/extracted/system_settings.css">
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-header">
                <h1><i class="fas fa-cog"></i> System Settings</h1>
                <p>Configure global system settings and preferences</p>
            </div>

            <?php if ($message): ?>
                <div class="admin-card" data-msg-type="<?php echo $messageType; ?>">
                    <?php echo escapeHtml($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="settings-grid">
                <input type="hidden" name="action" value="update_config">
                
                <!-- General Settings -->
                <div class="settings-section">
                    <div class="settings-section-header">
                        <h2><i class="fas fa-globe"></i> General Settings</h2>
                    </div>
                    <div class="settings-section-body">
                        <div class="form-group">
                            <label for="site_name">Site Name</label>
                            <input type="text" id="site_name" name="site_name" class="form-control" value="<?php echo escapeHtml($config['site_name']); ?>">
                            <small class="form-help">Displayed in the header and page titles</small>
                        </div>
                        <div class="form-group">
                            <label for="site_logo">Logo URL</label>
                            <input type="text" id="site_logo" name="site_logo" class="form-control" value="<?php echo escapeHtml($config['site_logo']); ?>">
                            <small class="form-help">URL or path to the logo image (shown in header)</small>
                        </div>
                        <div class="form-group">
                            <label for="footer_links">Footer Links (JSON array)</label>
                            <textarea id="footer_links" name="footer_links" class="form-control" rows="2"><?php echo escapeHtml(json_encode($config['footer_links'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
                            <small class="form-help">Example: [ { "label": "Contacto", "url": "mailto:info@dominio.com" }, { "label": "Aviso Legal", "url": "/legal" } ]</small>
                        </div>
                        <div class="form-group">
                                <div class="checkbox-group">
                                <input type="checkbox" id="allow_registration" name="allow_registration" value="1" <?php echo $config['allow_registration'] ? 'checked' : ''; ?>>
                                <div>
                                    <label for="allow_registration">Allow Public Registration</label>
                                    <small class="form-help form-help-mt">Allow new users to register without invitation</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Storage Settings -->
                <div class="settings-section">
                    <div class="settings-section-header">
                        <h2><i class="fas fa-hdd"></i> Storage Settings</h2>
                    </div>
                    <div class="settings-section-body">
                        <div class="form-group">
                            <label for="default_storage_quota">Default Storage Quota (GB)</label>
                            <input type="number" id="default_storage_quota" name="default_storage_quota" class="form-control" value="<?php echo $config['default_storage_quota']; ?>" min="1" max="1000">
                            <small class="form-help">Default storage quota for new users</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="max_file_size">Maximum File Size (MB)</label>
                            <input type="number" id="max_file_size" name="max_file_size" class="form-control" value="<?php echo $config['max_file_size']; ?>" min="1" max="10000">
                            <small class="form-help">Maximum size for individual file uploads</small>
                        </div>
                    </div>
                </div>
                
                <!-- Share Settings -->
                <div class="settings-section">
                    <div class="settings-section-header">
                        <h2><i class="fas fa-share-alt"></i> Share Settings</h2>
                    </div>
                    <div class="settings-section-body">
                        <div class="form-group">
                            <label for="max_share_time_days">Maximum Share Time (Days)</label>
                            <input type="number" id="max_share_time_days" name="max_share_time_days" class="form-control" value="<?php echo $config['max_share_time_days']; ?>" min="1" max="365">
                            <small class="form-help">Maximum time a share link can be active</small>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                    </div>
                </div>
            </form>

            <!-- System Information -->
            <div class="settings-section">
                <div class="settings-section-header">
                    <h2><i class="fas fa-info-circle"></i> System Information</h2>
                </div>
                <div class="settings-section-body">
                    <table class="info-table">
                        <tr>
                            <td>PHP Version</td>
                            <td><?php echo PHP_VERSION; ?></td>
                        </tr>
                        <tr>
                            <td>Database</td>
                            <td>
                                <?php 
                                $db = Database::getInstance()->getConnection();
                                $version = $db->query('SELECT VERSION()')->fetchColumn();
                                echo $version;
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Upload Max Size (PHP)</td>
                            <td><?php echo ini_get('upload_max_filesize'); ?></td>
                        </tr>
                        <tr>
                            <td>Post Max Size (PHP)</td>
                            <td><?php echo ini_get('post_max_size'); ?></td>
                        </tr>
                        <tr>
                            <td>Memory Limit (PHP)</td>
                            <td><?php echo ini_get('memory_limit'); ?></td>
                        </tr>
                        <tr>
                            <td>LDAP Extension</td>
                            <td>
                                <?php if (function_exists('ldap_connect')): ?>
                                    <span class="status-badge success">
                                        <i class="fas fa-check-circle"></i> Installed
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge danger">
                                        <i class="fas fa-times-circle"></i> Not installed
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
    </div>
    </div>
</body>
</html>
