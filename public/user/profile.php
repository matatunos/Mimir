<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();
$userClass = new User();
$logger = new Logger();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

renderPageStart('Mi Perfil', 'profile', $user['role'] === 'admin');
renderHeader('Mi Perfil', $user);
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
            <div class="card-header" style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 1.5rem;">
                <h2 class="card-title" style="color: white; font-weight: 700; font-size: 1.5rem;">👤 Información de la Cuenta</h2>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label>Nombre de usuario</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                </div>
                
                <div class="form-group">
                    <label>Nombre completo</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" disabled>
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" disabled>
                </div>
                
                <div class="form-group">
                    <label>Rol</label>
                    <input type="text" class="form-control" value="<?php echo $user['role'] === 'admin' ? 'Administrador' : 'Usuario'; ?>" disabled>
                </div>
                
                <?php if ($user['is_ldap']): ?>
                    <div class="alert alert-info">
                        Esta cuenta está gestionada por LDAP/Active Directory
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$user['is_ldap']): ?>
        <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
            <div class="card-header" style="background: linear-gradient(135deg, #f093fb, #f5576c); color: white; padding: 1.5rem;">
                <h2 class="card-title" style="color: white; font-weight: 700; font-size: 1.5rem;"><i class="fas fa-lock"></i> Cambiar Contraseña</h2>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                    
                    <div class="form-group">
                        <label>Contraseña actual *</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Nueva contraseña *</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6">
                        <small style="color: var(--text-muted);">Mínimo 6 caracteres</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Confirmar nueva contraseña *</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="6">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Actualizar Contraseña</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php renderPageEnd(); ?>
