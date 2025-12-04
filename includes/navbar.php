<div class="navbar">
    <div class="navbar-brand">
        <a href="dashboard.php" style="color: white; text-decoration: none;">
            <?php echo SystemConfig::get('site_name', APP_NAME); ?>
        </a>
    </div>
    <div class="navbar-menu">
        <a href="dashboard.php">📁 My Files</a>
        <a href="shares.php">🔗 Shares</a>
        <?php if (Auth::isAdmin()): ?>
            <a href="admin_dashboard.php">📊 Dashboard</a>
            <a href="users.php">👥 Users</a>
            <a href="admin.php">⚙️ Settings</a>
        <?php endif; ?>
        <a href="logout.php">🚪 Logout</a>
    </div>
    <div class="navbar-user">
        <?php if (isset($_SESSION['username'])): ?>
            <span style="margin-right: 10px;">
                <?php if (Auth::isAdmin()): ?>
                    <span style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: bold;">ADMIN</span>
                <?php endif; ?>
                <?php echo escapeHtml($_SESSION['username']); ?>
            </span>
        <?php endif; ?>
    </div>
</div>
