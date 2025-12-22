<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/Config.php';

$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();
$userClass = new User();
$logger = new Logger();

$error = '';
$success = '';

$config = new Config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Toggle global config protection (admin only)
    if (isset($_POST['toggle_config_protection_action']) && $user['role'] === 'admin') {
        $token = $_POST['csrf_token'] ?? '';
        if (!$auth->validateCsrfToken($token)) {
            $error = 'Token de seguridad inválido';
        } else {
            $val = ($_POST['toggle_config_protection_action'] === 'enable') ? '1' : '0';
            $ok = $config->set('enable_config_protection', $val, 'boolean');
            if ($ok) {
                $config->reload();
                $logger->log($user['id'], 'toggle_config_protection', 'config', null, ($val === '1' ? 'enabled' : 'disabled'));
                $success = 'Protección de configuración actualizada';
                // refresh user object in session if needed
            } else {
                $error = 'No se pudo actualizar la configuración';
            }
        }
    } else {
        // Password change flow
        if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $error = 'Token de seguridad inválido';
        } elseif ($user['is_ldap']) {
            $error = 'Los usuarios LDAP no pueden cambiar su contraseña aquí';
        } else {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($currentPassword) || empty($newPassword)) {
                $error = 'Todos los campos son obligatorios';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Las contraseñas nuevas no coinciden';
            } elseif (strlen($newPassword) < 6) {
                $error = 'La contraseña debe tener al menos 6 caracteres';
            } elseif (!password_verify($currentPassword, $user['password'])) {
                $error = 'La contraseña actual es incorrecta';
            } else {
                try {
                    $userClass->changePassword($user['id'], $newPassword);
                    $logger->log($user['id'], 'password_change', 'user', $user['id'], 'Usuario cambió su contraseña');
                    $success = 'Contraseña actualizada correctamente';
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
            }
        }
    }
}

renderPageStart(t('user_profile'), 'profile', $user['role'] === 'admin');
renderHeader(t('user_profile'), $user);
?>

<div class="content">
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div style="max-width: 700px; margin: 0 auto;">
        <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 1.5rem;">
            <div class="card-header" style="padding: 1.5rem;">
                <h2 class="card-title" style="font-weight: 700; font-size: 1.5rem; margin: 0;"><?php echo t('account_info_title'); ?></h2>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label><?php echo t('label_username_full'); ?></label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                </div>
                
                <div class="form-group">
                    <label><?php echo t('label_full_name'); ?></label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" disabled>
                </div>
                
                <div class="form-group">
                    <label><?php echo t('label_email'); ?></label>
                    <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" disabled>
                </div>
                
                <div class="form-group">
                    <label><?php echo t('label_role'); ?></label>
                    <input type="text" class="form-control" value="<?php echo $user['role'] === 'admin' ? t('role_admin') : t('role_user'); ?>" disabled>
                </div>
                
                <?php if ($user['is_ldap']): ?>
                    <div class="alert alert-info">
                        <?php echo t('ldap_managed_notice'); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$user['is_ldap']): ?>
        <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
            <div class="card-header" style="padding: 1.5rem;">
                <h2 class="card-title" style="font-weight: 700; font-size: 1.5rem; margin: 0;"><i class="fas fa-lock"></i> Cambiar Contraseña</h2>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                    
                        <div class="form-group">
                            <label><?php echo t('label_current_password'); ?></label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                    
                    <div class="form-group">
                        <label><?php echo t('label_new_password'); ?> *</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6">
                        <small style="color: var(--text-muted);"><?php echo sprintf(t('password_min_hint'), 6); ?></small>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('label_confirm_password'); ?> *</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="6">
                    </div>
                    
                    <button type="submit" class="btn btn-primary"><?php echo t('update_password_button'); ?></button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($user['role'] === 'admin'): ?>
        <div class="card" style="margin-top:1.5rem; border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
            <div class="card-header" style="padding: 1.5rem;">
                <h2 class="card-title" style="font-weight: 700; font-size: 1.5rem; margin: 0;"><i class="fas fa-shield-alt"></i> Protección de Configuración</h2>
            </div>
            <div class="card-body">
                <?php
                    $currentProtection = (bool)$config->get('enable_config_protection', '0');
                ?>
                <p><?php echo t('config_protection_status'); ?>: <strong><?php echo $currentProtection ? t('enabled') : t('disabled'); ?></strong></p>
                <form method="POST" style="display:inline-block; margin-right:0.5rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                    <input type="hidden" name="toggle_config_protection_action" value="<?php echo $currentProtection ? 'disable' : 'enable'; ?>">
                    <button type="submit" class="btn <?php echo $currentProtection ? 'btn-danger' : 'btn-primary'; ?>">
                        <?php echo $currentProtection ? t('disable_protection') : t('enable_protection'); ?>
                    </button>
                </form>
                <p style="color:var(--text-muted); margin-top:0.75rem;"><?php echo t('config_protection_desc'); ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php renderPageEnd(); ?>
