<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireAdmin();
$adminUser = $auth->getUser();
$userClass = new User();
$logger = new Logger();

$userId = intval($_GET['id'] ?? 0);
if (!$userId) {
    header('Location: ' . BASE_URL . '/admin/users.php');
    exit;
}

$user = $userClass->getById($userId);
if (!$user) {
    header('Location: ' . BASE_URL . '/admin/users.php?error=' . urlencode('Usuario no encontrado'));
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $storageQuota = floatval($_POST['storage_quota'] ?? 10);
        $require2FA = isset($_POST['require_2fa']);
        $isActive = isset($_POST['is_active']);
        $newPassword = $_POST['new_password'] ?? '';
        
        if (empty($username) || empty($email)) {
            $error = 'Usuario y email son obligatorios';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email inválido';
        } elseif (!in_array($role, ['admin', 'user'])) {
            $error = 'Rol inválido';
        } else {
            try {
                $updateData = [
                    'username' => $username,
                    'email' => $email,
                    'full_name' => $fullName,
                    'role' => $role,
                    'storage_quota' => $storageQuota * 1024 * 1024 * 1024,
                    'require_2fa' => $require2FA,
                    'is_active' => $isActive
                ];
                
                if (!empty($newPassword)) {
                    if (strlen($newPassword) < 6) {
                        throw new Exception('La contraseña debe tener al menos 6 caracteres');
                    }
                    $updateData['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
                }
                
                if ($userClass->update($userId, $updateData)) {
                    $logger->log($adminUser['id'], 'user_update', 'user', $userId, "Usuario {$username} actualizado por administrador");
                    $success = 'Usuario actualizado correctamente';
                    $user = $userClass->getById($userId);
                } else {
                    $error = 'Error al actualizar el usuario';
                }
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$storageQuotaGB = $user['storage_quota'] / 1024 / 1024 / 1024;

renderPageStart('Editar Usuario', 'users', true);
renderHeader('Editar Usuario', $adminUser);
?>

<style>
.page-header-modern {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 2rem;
    border-radius: 1rem;
    margin-bottom: 2rem;
    box-shadow: 0 8px 24px rgba(102, 126, 234, 0.2);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.page-header-modern h1 {
    color: white;
    font-size: 2rem;
    font-weight: 800;
    margin: 0 0 0.5rem 0;
}
.page-header-modern p {
    color: rgba(255, 255, 255, 0.9);
    margin: 0;
}
</style>

<div class="content">
    <div class="page-header-modern">
        <div>
            <h1><i class="fas fa-edit"></i> Editar Usuario</h1>
            <p>Modificar información del usuario <?php echo htmlspecialchars($user['username']); ?></p>
        </div>
        <a href="<?php echo BASE_URL; ?>/admin/users.php" class="btn btn-outline" style="background: white; color: #667eea; border: none; font-weight: 600;">← Volver</a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
        <div class="card-header" style="background: linear-gradient(135deg, #4a90e2, #50c878); color: white; padding: 1.5rem;">
            <h2 class="card-title" style="color: white; font-weight: 700; font-size: 1.5rem;">📝 Información del Usuario</h2>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="username" class="form-label required">Usuario</label>
                        <input type="text" id="username" name="username" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" required <?php echo $user['is_ldap'] ? 'readonly' : ''; ?>>
                        <?php if ($user['is_ldap']): ?>
                            <small style="color: var(--text-muted);"><i class="fas fa-lock"></i> Usuario LDAP (no editable)</small>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label for="email" class="form-label required">Email</label>
                        <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="full_name" class="form-label">Nombre Completo</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="role" class="form-label required">Rol</label>
                        <select id="role" name="role" class="form-control" required>
                            <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>Usuario</option>
                            <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="storage_quota" class="form-label required">Cuota de Almacenamiento (GB)</label>
                        <input type="number" id="storage_quota" name="storage_quota" class="form-control" value="<?php echo number_format($storageQuotaGB, 2, '.', ''); ?>" min="0.1" step="0.1" required>
                    </div>
                </div>

                <?php if (!$user['is_ldap']): ?>
                <div class="form-group">
                    <label for="new_password" class="form-label">Nueva Contraseña</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" placeholder="Dejar en blanco para no cambiar">
                    <small style="color: var(--text-muted);">Mínimo 6 caracteres</small>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Opciones</label>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <div class="form-check">
                            <input type="checkbox" id="is_active" name="is_active" <?php echo $user['is_active'] ? 'checked' : ''; ?> <?php echo $userId === $adminUser['id'] ? 'disabled' : ''; ?>>
                            <label for="is_active">Usuario activo</label>
                        </div>
                        
                        <div class="form-check">
                            <input type="checkbox" id="require_2fa" name="require_2fa" <?php echo $user['require_2fa'] ? 'checked' : ''; ?>>
                            <label for="require_2fa">Requerir autenticación 2FA</label>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 0.75rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Cambios</button>
                    <a href="<?php echo BASE_URL; ?>/admin/users.php" class="btn btn-outline">Cancelar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fas fa-chart-bar"></i> Estadísticas</h2>
        </div>
        <div class="card-body">
            <?php
            $stats = $userClass->getStatistics($userId);
            $storageUsedGB = ($stats['total_size'] ?? 0) / 1024 / 1024 / 1024;
            $storagePercent = $storageQuotaGB > 0 ? min(100, ($storageUsedGB / $storageQuotaGB) * 100) : 0;
            ?>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                <div>
                    <div style="color: var(--text-muted); margin-bottom: 0.5rem;">Archivos Totales</div>
                    <div style="font-size: 2rem; font-weight: bold; color: var(--primary);"><?php echo number_format($stats['file_count'] ?? 0); ?></div>
                </div>
                
                <div>
                    <div style="color: var(--text-muted); margin-bottom: 0.5rem;">Almacenamiento Usado</div>
                    <div style="font-size: 2rem; font-weight: bold; color: var(--primary);"><?php echo number_format($storageUsedGB, 2); ?> GB</div>
                    <div style="margin-top: 0.5rem;">
                        <div style="background: var(--bg-secondary); height: 0.5rem; border-radius: 0.25rem; overflow: hidden;">
                            <div style="width: <?php echo $storagePercent; ?>%; height: 100%; background: var(--primary);"></div>
                        </div>
                        <small style="color: var(--text-muted);"><?php echo number_format($storagePercent, 1); ?>% de <?php echo number_format($storageQuotaGB, 2); ?> GB</small>
                    </div>
                </div>
                
                <div>
                    <div style="color: var(--text-muted); margin-bottom: 0.5rem;">Compartidos Activos</div>
                    <div style="font-size: 2rem; font-weight: bold; color: var(--success);"><?php echo number_format($stats['active_shares'] ?? 0); ?></div>
                </div>
                
                <div>
                    <div style="color: var(--text-muted); margin-bottom: 0.5rem;">Último Acceso</div>
                    <div style="font-size: 1.25rem; font-weight: bold;"><?php if ($user['last_login']) { echo date('d/m/Y H:i', strtotime($user['last_login'])); } else { echo 'Nunca'; } ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php renderPageEnd(); ?>
