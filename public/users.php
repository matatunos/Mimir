<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireAdmin();

$message = '';
$messageType = '';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $db = Database::getInstance()->getConnection();
    
    if ($_POST['action'] === 'create_user') {
        $username = $_POST['username'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $quota = intval($_POST['storage_quota'] ?? 1) * 1073741824;
        
        try {
            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role, storage_quota, is_active) VALUES (?, ?, ?, ?, ?, 1)");
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $stmt->execute([$username, $email, $passwordHash, $role, $quota]);
            
            $userId = $db->lastInsertId();
            AuditLog::log(Auth::getUserId(), 'user_created', 'user', $userId, "Created user: $username");
            
            $message = 'User created successfully';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error creating user: Username or email already exists';
            $messageType = 'danger';
        }
    } elseif ($_POST['action'] === 'update_user') {
        $userId = $_POST['user_id'] ?? null;
        $role = $_POST['role'] ?? 'user';
        $quota = intval($_POST['storage_quota'] ?? 1) * 1073741824;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        if ($userId) {
            $stmt = $db->prepare("UPDATE users SET role = ?, storage_quota = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$role, $quota, $isActive, $userId]);
            
            AuditLog::log(Auth::getUserId(), 'user_updated', 'user', $userId, "Updated user settings");
            
            $message = 'User updated successfully';
            $messageType = 'success';
        }
    } elseif ($_POST['action'] === 'delete_user') {
        $userId = $_POST['user_id'] ?? null;
        
        if ($userId && $userId != Auth::getUserId()) {
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            
            AuditLog::log(Auth::getUserId(), 'user_deleted', 'user', $userId, "Deleted user");
            
            $message = 'User deleted successfully';
            $messageType = 'success';
        }
    } elseif ($_POST['action'] === 'reset_password') {
        $userId = $_POST['user_id'] ?? null;
        $newPassword = $_POST['new_password'] ?? '';
        
        if ($userId && strlen($newPassword) >= 6) {
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt->execute([$passwordHash, $userId]);
            
            AuditLog::log(Auth::getUserId(), 'password_reset', 'user', $userId, "Password reset by admin");
            
            $message = 'Password reset successfully';
            $messageType = 'success';
        }
    }
}

// Get all users
$db = Database::getInstance()->getConnection();

// Get admin users
$stmt = $db->query("SELECT * FROM users WHERE role = 'admin' ORDER BY username ASC");
$adminUsers = $stmt->fetchAll();

// Get regular users
$stmt = $db->query("SELECT * FROM users WHERE role = 'user' ORDER BY username ASC");
$regularUsers = $stmt->fetchAll();

$pageTitle = 'User Management';
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
    <link rel="stylesheet" href="css/users.css">
    <style>
        /* Modal Styles */
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .admin-header h1 {
            margin: 0 0 0.25rem 0;
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        .admin-header p {
            margin: 0;
            color: var(--gray-600);
            font-size: 0.875rem;
        }
        
        .admin-card {
            margin-bottom: 1.5rem;
        }
        
        .user-info-cell {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar-admin,
        .user-avatar-user {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
            color: white;
            flex-shrink: 0;
        }
        
        .user-avatar-admin {
            background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
        }
        
        .user-avatar-user {
            background: linear-gradient(135deg, var(--gray-500), var(--gray-700));
        }
        
        .user-info-content {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
        }
        
        .user-name {
            font-weight: 600;
            color: var(--gray-900);
            font-size: 0.938rem;
            line-height: 1.2;
        }
        
        .user-badges {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: nowrap;
        }
        
        .badge-separator {
            color: var(--gray-400);
            font-size: 0.75rem;
            line-height: 1;
            user-select: none;
        }
        
        .storage-info {
            min-width: 140px;
        }
        
        .storage-text {
            font-size: 0.813rem;
            color: var(--gray-700);
            margin-bottom: 0.375rem;
            white-space: nowrap;
        }
        
        .storage-bar {
            width: 100%;
            height: 6px;
            background: var(--gray-200);
            border-radius: 3px;
            overflow: hidden;
        }
        
        .storage-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-500), var(--primary-600));
            transition: width 0.3s ease;
        }
        
        .text-muted {
            color: var(--gray-600);
            font-size: 0.875rem;
        }
        
        .btn-group {
            display: flex;
            gap: 0.375rem;
        }
        
        .btn-group .btn {
            padding: 0.375rem 0.625rem;
            font-size: 0.813rem;
        }
        
        .badge {
            padding: 0.25rem 0.625rem;
            font-size: 0.688rem;
            font-weight: 600;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        
        .badge-admin {
            background: linear-gradient(135deg, var(--primary-600), var(--primary-700));
            color: white;
        }
        
        .badge-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }
        
        .badge-info {
            background: var(--blue-100);
            color: var(--blue-700);
        }
        
        .badge-success {
            background: var(--green-100);
            color: var(--green-700);
        }
        
        .badge-danger {
            background: var(--red-100);
            color: var(--red-700);
        }
        
        .badge-primary {
            background: var(--primary-100);
            color: var(--primary-700);
        }
        /* End of modal styles - other user management styles are in users.css */
    </style>
</head>
<body>
    <div style="width:100%;max-width:1400px;margin:0 auto;">
        <div id="user-menu" style="background:#fff;box-shadow:0 2px 10px rgba(0,0,0,0.07);padding:1.5rem 2rem 1rem 2rem;display:flex;align-items:center;justify-content:space-between;border-radius:18px 18px 0 0;">
            <div style="font-size:1.5rem;font-weight:700;color:#667eea;"><i class="fas fa-users"></i> Usuarios</div>
            <div style="display:flex;gap:1.5rem;">
                <a href="users.php" style="color:#64748b;text-decoration:none;font-weight:500;"><i class="fas fa-users"></i> Usuarios</a>
                <a href="shares.php" style="color:#64748b;text-decoration:none;font-weight:500;"><i class="fas fa-share-alt"></i> Compartidos</a>
                <a href="logout.php" style="color:#64748b;text-decoration:none;font-weight:500;"><i class="fas fa-sign-out-alt"></i> Salir</a>
            </div>
        </div>
        <div id="user-content" style="background:#fff;border-radius:0 0 18px 18px;padding:2rem;box-shadow:0 2px 10px rgba(0,0,0,0.04);">
                <div class="admin-header">
                    <div>
                        <h1>👥 User Management</h1>
                        <p>Manage system users and permissions</p>
                    </div>
                    <button class="btn btn-primary" onclick="showCreateUserModal()">
                        <i class="fas fa-user-plus"></i> Create User
                    </button>
                </div>

        <?php if ($message): ?>
            <div class="admin-card" style="background: <?php echo $messageType === 'success' ? '#d1fae5' : '#fee2e2'; ?>; border-left: 4px solid <?php echo $messageType === 'success' ? '#059669' : '#dc2626'; ?>; color: <?php echo $messageType === 'success' ? '#065f46' : '#991b1b'; ?>; margin-bottom: 1.5rem;">
                <?php echo escapeHtml($message); ?>
            </div>
        <?php endif; ?>

        <!-- Administrators Section -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h2>
                    <i class="fas fa-shield-alt"></i>
                    Administrators
                </h2>
                <span class="badge badge-primary"><?php echo count($adminUsers); ?> users</span>
            </div>
            <div class="admin-card-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th>User</th>
                            <th>Email</th>
                            <th style="width: 180px;">Storage</th>
                            <th style="width: 100px;">Status</th>
                            <th style="width: 150px;">Last Login</th>
                            <th style="width: 110px;">Created</th>
                            <th style="width: 140px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($adminUsers as $user): ?>
                        <tr>
                            <td class="text-muted">#<?php echo $user['id']; ?></td>
                            <td>
                                <div class="user-info-cell">
                                    <div class="user-avatar-admin">
                                        <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                                    </div>
                                    <div class="user-info-content">
                                        <div class="user-name"><?php echo escapeHtml($user['username']); ?></div>
                                        <div class="user-badges">
                                            <span class="badge badge-admin">ADMIN</span>
                                            <span class="badge-separator">•</span>
                                            <?php if (empty($user['password_hash'])): ?>
                                                <span class="badge badge-info">LDAP</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">LOCAL</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo escapeHtml($user['email']); ?></td>
                            <td>
                                <div class="storage-info">
                                    <div class="storage-text">
                                        <strong><?php echo formatBytes($user['storage_used']); ?></strong> / <?php echo formatBytes($user['storage_quota']); ?>
                                    </div>
                                    <div class="storage-bar">
                                        <div class="storage-bar-fill" style="width: <?php echo $user['storage_quota'] > 0 ? min(100, ($user['storage_used'] / $user['storage_quota']) * 100) : 0; ?>%;"></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($user['is_active']): ?>
                                    <span class="badge badge-success">ACTIVE</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">INACTIVE</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted">
                                <?php 
                                if ($user['last_login']) {
                                    echo date('M d, Y H:i', strtotime($user['last_login']));
                                } else {
                                    echo 'Never';
                                }
                                ?>
                            </td>
                            <td class="text-muted"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                            <td>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-secondary" onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if (!empty($user['password_hash'])): ?>
                                        <button class="btn btn-sm btn-secondary" onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo escapeHtml($user['username']); ?>')" title="Reset Password">
                                            <i class="fas fa-key"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($user['id'] != Auth::getUserId()): ?>
                                        <button class="btn btn-sm btn-danger" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo escapeHtml($user['username']); ?>')" title="Delete">
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
        </div>

        <!-- Regular Users Section -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h2>
                    <i class="fas fa-users"></i>
                    Regular Users
                </h2>
                <span class="badge badge-secondary"><?php echo count($regularUsers); ?> users</span>
            </div>
            <div class="admin-card-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th>User</th>
                            <th>Email</th>
                            <th style="width: 180px;">Storage</th>
                            <th style="width: 100px;">Status</th>
                            <th style="width: 150px;">Last Login</th>
                            <th style="width: 110px;">Created</th>
                            <th style="width: 140px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regularUsers as $user): ?>
                        <tr>
                            <td class="text-muted">#<?php echo $user['id']; ?></td>
                            <td>
                                <div class="user-info-cell">
                                    <div class="user-avatar-user">
                                        <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                                    </div>
                                    <div class="user-info-content">
                                        <div class="user-name"><?php echo escapeHtml($user['username']); ?></div>
                                        <div class="user-badges">
                                            <span class="badge badge-secondary">USER</span>
                                            <span class="badge-separator">•</span>
                                            <?php if (empty($user['password_hash'])): ?>
                                                <span class="badge badge-info">LDAP</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">LOCAL</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo escapeHtml($user['email']); ?></td>
                            <td>
                                <div class="storage-info">
                                    <div class="storage-text">
                                        <strong><?php echo formatBytes($user['storage_used']); ?></strong> / <?php echo formatBytes($user['storage_quota']); ?>
                                    </div>
                                    <div class="storage-bar">
                                        <div class="storage-bar-fill" style="width: <?php echo $user['storage_quota'] > 0 ? min(100, ($user['storage_used'] / $user['storage_quota']) * 100) : 0; ?>%;"></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($user['is_active']): ?>
                                    <span class="badge badge-success">ACTIVE</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">INACTIVE</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted">
                                <?php 
                                if ($user['last_login']) {
                                    echo date('M d, Y H:i', strtotime($user['last_login']));
                                } else {
                                    echo 'Never';
                                }
                                ?>
                            </td>
                            <td class="text-muted"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                            <td>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-secondary" onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if (!empty($user['password_hash'])): ?>
                                        <button class="btn btn-sm btn-secondary" onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo escapeHtml($user['username']); ?>')" title="Reset Password">
                                            <i class="fas fa-key"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-danger" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo escapeHtml($user['username']); ?>')" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

            </div>
        </div>
    </div>

    <!-- Create User Modal -->
    <div id="createUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create New User</h2>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_user">
                
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Password * (min 6 characters)</label>
                    <input type="password" name="password" class="form-control" required minlength="6">
                </div>
                
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" class="form-control">
                        <option value="user">User</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Storage Quota (GB)</label>
                    <input type="number" name="storage_quota" class="form-control" value="1" min="1" max="1000">
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('createUserModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit User</h2>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="edit_username" class="form-control" disabled>
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="text" id="edit_email" class="form-control" disabled>
                </div>
                
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="edit_role" class="form-control">
                        <option value="user">User</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Storage Quota (GB)</label>
                    <input type="number" name="storage_quota" id="edit_storage_quota" class="form-control" min="1" max="1000">
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_active" id="edit_is_active" value="1">
                        Account Active
                    </label>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editUserModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="resetPasswordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Reset Password</h2>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="reset_user_id">
                
                <p>Reset password for user: <strong id="reset_username"></strong></p>
                
                <div class="form-group">
                    <label>New Password (min 6 characters)</label>
                    <input type="password" name="new_password" class="form-control" required minlength="6">
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('resetPasswordModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
    </div>

    <script>
        function showCreateUserModal() {
            document.getElementById('createUserModal').classList.add('active');
        }

        function editUser(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_username').value = user.username;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_role').value = user.role;
            document.getElementById('edit_storage_quota').value = Math.round(user.storage_quota / 1073741824);
            document.getElementById('edit_is_active').checked = user.is_active == 1;
            document.getElementById('editUserModal').classList.add('active');
        }

        function resetPassword(userId, username) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_username').textContent = username;
            document.getElementById('resetPasswordModal').classList.add('active');
        }

        function deleteUser(userId, username) {
            if (confirm('Are you sure you want to delete user "' + username + '"? This action cannot be undone and will delete all their files!')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="' + userId + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>

    <style>
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: white;
            margin: auto;
            padding: 0;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-light);
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--text-main);
        }

        .modal-content form {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .btn-group {
            display: flex;
            gap: 0.5rem;
        }
    </style>
</body>
</html>
