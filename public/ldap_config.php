<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireAdmin();

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'save_ldap') {
            // Save LDAP configuration
            SystemConfig::set('ldap_enabled', isset($_POST['ldap_enabled']) ? 1 : 0);
            SystemConfig::set('ldap_server', $_POST['ldap_server'] ?? '');
            SystemConfig::set('ldap_port', intval($_POST['ldap_port'] ?? 389));
            SystemConfig::set('ldap_base_dn', $_POST['ldap_base_dn'] ?? '');
            SystemConfig::set('ldap_user_dn', $_POST['ldap_user_dn'] ?? '');
            SystemConfig::set('ldap_use_ssl', isset($_POST['ldap_use_ssl']) ? 1 : 0);
            SystemConfig::set('ldap_use_starttls', isset($_POST['ldap_use_starttls']) ? 1 : 0);
            SystemConfig::set('ldap_admin_dn', $_POST['ldap_admin_dn'] ?? '');
            
            // Only update password if provided
            if (!empty($_POST['ldap_admin_password'])) {
                SystemConfig::set('ldap_admin_password', $_POST['ldap_admin_password']);
            }
            
            SystemConfig::set('ldap_user_filter', $_POST['ldap_user_filter'] ?? '(sAMAccountName={username})');
            SystemConfig::set('ldap_username_attr', $_POST['ldap_username_attr'] ?? 'sAMAccountName');
            SystemConfig::set('ldap_email_attr', $_POST['ldap_email_attr'] ?? 'mail');
            SystemConfig::set('ldap_displayname_attr', $_POST['ldap_displayname_attr'] ?? 'displayName');
            
            $message = 'LDAP configuration saved successfully';
            $messageType = 'success';
            
            AuditLog::log(Auth::getUserId(), 'ldap_config_updated', 'system', null, 'LDAP configuration updated');
        } elseif ($_POST['action'] === 'test_ldap') {
            $ldap = new LdapAuth();
            $result = $ldap->testConnection();
            $message = $result['message'];
            $messageType = $result['success'] ? 'success' : 'danger';
        }
    }
}

// Get current LDAP configuration
$ldapConfig = [
    'enabled' => SystemConfig::get('ldap_enabled', false),
    'server' => SystemConfig::get('ldap_server', ''),
    'port' => SystemConfig::get('ldap_port', 389),
    'base_dn' => SystemConfig::get('ldap_base_dn', ''),
    'user_dn' => SystemConfig::get('ldap_user_dn', ''),
    'use_ssl' => SystemConfig::get('ldap_use_ssl', false),
    'use_starttls' => SystemConfig::get('ldap_use_starttls', false),
    'admin_dn' => SystemConfig::get('ldap_admin_dn', ''),
    'user_filter' => SystemConfig::get('ldap_user_filter', '(sAMAccountName={username})'),
    'username_attr' => SystemConfig::get('ldap_username_attr', 'sAMAccountName'),
    'email_attr' => SystemConfig::get('ldap_email_attr', 'mail'),
    'displayname_attr' => SystemConfig::get('ldap_displayname_attr', 'displayName'),
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LDAP Configuration - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>
        
        <div class="admin-content">
                <h2>🔐 LDAP / Active Directory Configuration</h2>
                <p style="color: #666; margin-bottom: 20px;">Configure LDAP authentication for hybrid login (local + LDAP)</p>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="save_ldap">

                            <div class="mb-3 form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="ldap_enabled" name="ldap_enabled" value="1" <?php echo $ldapConfig['enabled'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="ldap_enabled">
                                    <strong>Enable LDAP Authentication</strong>
                                    <small class="d-block text-muted">Local users will always authenticate first</small>
                                </label>
                            </div>

                            <hr>

                            <h5>Server Settings</h5>
                            
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="mb-3">
                                        <label for="ldap_server" class="form-label">LDAP Server <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="ldap_server" name="ldap_server" 
                                               value="<?php echo htmlspecialchars($ldapConfig['server']); ?>" 
                                               placeholder="ldap.example.com or 192.168.1.100">
                                        <small class="form-text text-muted">Hostname or IP address of LDAP/AD server</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="ldap_port" class="form-label">Port <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="ldap_port" name="ldap_port" 
                                               value="<?php echo htmlspecialchars($ldapConfig['port']); ?>">
                                        <small class="form-text text-muted">389 (LDAP) or 636 (LDAPS)</small>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="ldap_base_dn" class="form-label">Base DN <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="ldap_base_dn" name="ldap_base_dn" 
                                       value="<?php echo htmlspecialchars($ldapConfig['base_dn']); ?>" 
                                       placeholder="dc=example,dc=com">
                                <small class="form-text text-muted">Base Distinguished Name for user searches</small>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3 form-check">
                                        <input class="form-check-input" type="checkbox" id="ldap_use_ssl" name="ldap_use_ssl" value="1" <?php echo $ldapConfig['use_ssl'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="ldap_use_ssl">Use SSL (LDAPS)</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3 form-check">
                                        <input class="form-check-input" type="checkbox" id="ldap_use_starttls" name="ldap_use_starttls" value="1" <?php echo $ldapConfig['use_starttls'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="ldap_use_starttls">Use StartTLS</label>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <h5>User Search Settings</h5>

                            <div class="mb-3">
                                <label for="ldap_user_dn" class="form-label">User DN Pattern (optional)</label>
                                <input type="text" class="form-control" id="ldap_user_dn" name="ldap_user_dn" 
                                       value="<?php echo htmlspecialchars($ldapConfig['user_dn']); ?>" 
                                       placeholder="cn={username},ou=users,dc=example,dc=com">
                                <small class="form-text text-muted">If specified, will be used directly. Leave empty to search.</small>
                            </div>

                            <div class="mb-3">
                                <label for="ldap_user_filter" class="form-label">User Search Filter</label>
                                <input type="text" class="form-control" id="ldap_user_filter" name="ldap_user_filter" 
                                       value="<?php echo htmlspecialchars($ldapConfig['user_filter']); ?>">
                                <small class="form-text text-muted">
                                    AD: (sAMAccountName={username}) | OpenLDAP: (uid={username})
                                </small>
                            </div>

                            <hr>

                            <h5>Bind Credentials (optional)</h5>
                            <p class="text-muted">Required if anonymous bind is disabled or for user search</p>

                            <div class="mb-3">
                                <label for="ldap_admin_dn" class="form-label">Bind DN</label>
                                <input type="text" class="form-control" id="ldap_admin_dn" name="ldap_admin_dn" 
                                       value="<?php echo htmlspecialchars($ldapConfig['admin_dn']); ?>" 
                                       placeholder="cn=admin,dc=example,dc=com">
                            </div>

                            <div class="mb-3">
                                <label for="ldap_admin_password" class="form-label">Bind Password</label>
                                <input type="password" class="form-control" id="ldap_admin_password" name="ldap_admin_password" 
                                       placeholder="Leave empty to keep current password">
                            </div>

                            <hr>

                            <h5>Attribute Mapping</h5>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="ldap_username_attr" class="form-label">Username Attribute</label>
                                        <input type="text" class="form-control" id="ldap_username_attr" name="ldap_username_attr" 
                                               value="<?php echo htmlspecialchars($ldapConfig['username_attr']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="ldap_email_attr" class="form-label">Email Attribute</label>
                                        <input type="text" class="form-control" id="ldap_email_attr" name="ldap_email_attr" 
                                               value="<?php echo htmlspecialchars($ldapConfig['email_attr']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="ldap_displayname_attr" class="form-label">Display Name Attribute</label>
                                        <input type="text" class="form-control" id="ldap_displayname_attr" name="ldap_displayname_attr" 
                                               value="<?php echo htmlspecialchars($ldapConfig['displayname_attr']); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Save Configuration
                                </button>
                            </div>
                        </form>

                        <hr>

                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="test_ldap">
                            <button type="submit" class="btn btn-secondary">
                                <i class="bi bi-plug"></i> Test Connection
                            </button>
                        </form>

                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header">
                        <h5><i class="bi bi-info-circle"></i> How It Works</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Hybrid Authentication:</strong></p>
                        <ol>
                            <li>User enters username and password</li>
                            <li>System first checks local database (admin and local users)</li>
                            <li>If local auth fails and LDAP is enabled, tries LDAP authentication</li>
                            <li>If LDAP auth succeeds, user is created/synced in local database</li>
                            <li>LDAP users cannot change password locally</li>
                        </ol>

                        <p><strong>Active Directory Example:</strong></p>
                        <ul>
                            <li>Server: <code>dc1.company.local</code></li>
                            <li>Port: <code>389</code></li>
                            <li>Base DN: <code>dc=company,dc=local</code></li>
                            <li>Bind DN: <code>cn=ldap_reader,ou=Service Accounts,dc=company,dc=local</code></li>
                            <li>User Filter: <code>(sAMAccountName={username})</code></li>
                        </ul>

                        <p><strong>OpenLDAP Example:</strong></p>
                        <ul>
                            <li>Server: <code>ldap.company.com</code></li>
                            <li>Port: <code>389</code></li>
                            <li>Base DN: <code>ou=users,dc=company,dc=com</code></li>
                            <li>User Filter: <code>(uid={username})</code></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
