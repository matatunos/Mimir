<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<link rel="stylesheet" href="css/admin-ui.css">

<div class="admin-sidebar">
    <h3>⚙️ Admin Panel</h3>
    <a href="admin_dashboard.php" class="<?php echo $current_page === 'admin_dashboard.php' ? 'active' : ''; ?>">
        <span>📊</span>
        <span>Dashboard</span>
    </a>
    <a href="admin_dashboard.php" class="<?php echo $current_page === 'admin_dashboard.php' ? 'active' : ''; ?>">
        <span>👥</span>
        <span>User Management</span>
    </a>
    <a href="system_settings.php" class="<?php echo $current_page === 'system_settings.php' ? 'active' : ''; ?>">
        <span>⚙️</span>
        <span>System Settings</span>
    </a>
    <a href="ldap_config.php" class="<?php echo $current_page === 'ldap_config.php' ? 'active' : ''; ?>">
        <span>🔐</span>
        <span>LDAP / AD Config</span>
    </a>
    <a href="audit_logs.php" class="<?php echo $current_page === 'audit_logs.php' ? 'active' : ''; ?>">
        <span>📋</span>
        <span>Audit Logs</span>
    </a>
    <hr style="border: 0; border-top: 1px solid #f0f0f0; margin: 15px 0;">
    <a href="dashboard.php">
        <span>←</span>
        <span>Back to My Files</span>
    </a>
</div>
