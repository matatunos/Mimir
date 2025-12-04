<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<style>
.admin-layout {
    display: flex;
    gap: 0;
    margin-top: 20px;
}

.admin-sidebar {
    width: 250px;
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    height: fit-content;
    position: sticky;
    top: 80px;
}

.admin-sidebar h3 {
    margin: 0 0 20px 0;
    font-size: 18px;
    color: #333;
    padding-bottom: 10px;
    border-bottom: 2px solid #f0f0f0;
}

.admin-sidebar a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 15px;
    color: #666;
    text-decoration: none;
    border-radius: 6px;
    margin-bottom: 5px;
    transition: all 0.2s;
    font-size: 15px;
}

.admin-sidebar a:hover {
    background: #f8f9fa;
    color: #007bff;
}

.admin-sidebar a.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 600;
}

.admin-content {
    flex: 1;
    min-width: 0;
}

@media (max-width: 768px) {
    .admin-layout {
        flex-direction: column;
    }
    
    .admin-sidebar {
        width: 100%;
        position: relative;
        top: 0;
    }
}
</style>

<div class="admin-sidebar">
    <h3>⚙️ Admin Panel</h3>
    <a href="admin_dashboard.php" class="<?php echo $current_page === 'admin_dashboard.php' ? 'active' : ''; ?>">
        <span>📊</span>
        <span>Dashboard</span>
    </a>
    <a href="users.php" class="<?php echo $current_page === 'users.php' ? 'active' : ''; ?>">
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
