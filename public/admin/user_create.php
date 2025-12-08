<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use OTPHP\TOTP;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();
$userClass = new User();
$logger = new Logger();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $storageQuota = intval($_POST['storage_quota'] ?? 10) * 1024 * 1024 * 1024; // Convert GB to bytes
        $twoFactorMethod = $_POST['2fa_method'] ?? 'none';
        $require2FA = isset($_POST['require_2fa']);
        $sendEmail = isset($_POST['send_email']);
        
        if (empty($username) || empty($password)) {
            $error = 'Usuario y contraseña son obligatorios';
        } elseif (strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres';
        } elseif ($sendEmail && empty($email)) {
            $error = 'Se requiere un email para enviar las credenciales';
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email inválido';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                
                // Crear usuario
                $userId = $userClass->create([
                    'username' => $username,
                    'password' => $password,
                    'full_name' => $fullName,
                    'email' => $email,
                    'role' => $role,
                    'storage_quota' => $storageQuota,
                    'is_active' => 1,
                    'require_2fa' => $require2FA
                ]);
                
                $logger->log($user['id'], 'user_create', 'user', $userId, "Usuario creado: $username");
                
                // Configurar 2FA si se seleccionó
                $qrCodeData = null;
                $duoMessage = '';
                
                if ($twoFactorMethod === 'totp') {
                    // Generar secret TOTP
                    $totp = TOTP::generate();
                    $totp->setLabel($username);
                    $totp->setIssuer(SITE_NAME ?? 'Mimir');
                    $secret = $totp->getSecret();
                    
                    // Guardar en BD
                    $stmt = $db->prepare("
                        INSERT INTO user_2fa (user_id, method, secret, is_enabled, created_at)
                        VALUES (?, 'totp', ?, 1, NOW())
                    ");
                    $stmt->execute([$userId, $secret]);
                    
                    // Generar QR code
                    $qrCode = QrCode::create($totp->getProvisioningUri());
                    $writer = new PngWriter();
                    $result = $writer->write($qrCode);
                    $qrCodeData = base64_encode($result->getString());
                    
                    $logger->log($user['id'], '2fa_setup', 'user', $userId, "2FA TOTP configurado para usuario: $username");
                    
                } elseif ($twoFactorMethod === 'duo') {
                    // Guardar configuración Duo
                    $stmt = $db->prepare("
                        INSERT INTO user_2fa (user_id, method, is_enabled, created_at)
                        VALUES (?, 'duo', 1, NOW())
                    ");
                    $stmt->execute([$userId]);
                    
                    $duoMessage = 'El usuario deberá configurar Duo Security en su primer inicio de sesión.';
                    $logger->log($user['id'], '2fa_setup', 'user', $userId, "2FA Duo configurado para usuario: $username");
                }
                
                // Enviar email si se solicitó
                if ($sendEmail && !empty($email)) {
                    $emailBody = "<h2>¡Bienvenido a " . (SITE_NAME ?? 'Mimir') . "!</h2>";
                    $emailBody .= "<p>Se ha creado una cuenta para ti con las siguientes credenciales:</p>";
                    $emailBody .= "<ul>";
                    $emailBody .= "<li><strong>Usuario:</strong> " . htmlspecialchars($username) . "</li>";
                    $emailBody .= "<li><strong>Contraseña:</strong> " . htmlspecialchars($password) . "</li>";
                    $emailBody .= "<li><strong>URL:</strong> <a href='" . BASE_URL . "'>" . BASE_URL . "</a></li>";
                    $emailBody .= "</ul>";
                    
                    if ($twoFactorMethod === 'totp' && $qrCodeData) {
                        $emailBody .= "<h3>Autenticación de Dos Factores (TOTP)</h3>";
                        $emailBody .= "<p>Tu cuenta tiene activada la autenticación de dos factores. Escanea este código QR con tu aplicación de autenticación (Google Authenticator, Authy, etc.):</p>";
                        $emailBody .= "<p style='text-align: center;'><img src='data:image/png;base64," . $qrCodeData . "' alt='QR Code' style='max-width: 300px;' /></p>";
                        $emailBody .= "<p><strong>Código secreto manual:</strong> <code>" . $totp->getSecret() . "</code></p>";
                    } elseif ($twoFactorMethod === 'duo') {
                        $emailBody .= "<h3>Autenticación de Dos Factores (Duo)</h3>";
                        $emailBody .= "<p>" . $duoMessage . "</p>";
                    }
                    
                    $emailBody .= "<p>Por favor, cambia tu contraseña después del primer inicio de sesión.</p>";
                    $emailBody .= "<hr><p style='color: #666; font-size: 0.875rem;'>Este es un mensaje automático, por favor no responder.</p>";
                    
                    $headers = "MIME-Version: 1.0\r\n";
                    $headers .= "Content-type: text/html; charset=utf-8\r\n";
                    $headers .= "From: " . (SMTP_FROM_EMAIL ?? 'noreply@' . $_SERVER['HTTP_HOST']) . "\r\n";
                    
                    if (mail($email, 'Cuenta creada en ' . (SITE_NAME ?? 'Mimir'), $emailBody, $headers)) {
                        $logger->log($user['id'], 'email_sent', 'user', $userId, "Email de bienvenida enviado a: $email");
                    }
                }
                
                $successMsg = 'Usuario creado correctamente';
                if ($twoFactorMethod !== 'none') {
                    $successMsg .= ' con 2FA activado (' . strtoupper($twoFactorMethod) . ')';
                }
                if ($sendEmail) {
                    $successMsg .= '. Email enviado a ' . $email;
                }
                
                header('Location: ' . BASE_URL . '/admin/users.php?success=' . urlencode($successMsg));
                exit;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

renderPageStart('Crear Usuario', 'users', true);
renderHeader('Crear Nuevo Usuario', $user);
?>

<div class="content">
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div style="max-width: 700px; margin: 0 auto;">
        <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
            <div class="card-header" style="background: linear-gradient(135deg, #30cfd0, #330867); color: white; padding: 1.5rem;">
                <h2 class="card-title" style="color: white; font-weight: 700; font-size: 1.5rem;"><i class="fas fa-plus"></i> Datos del Usuario</h2>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                    
                    <div class="form-group">
                        <label>Nombre de usuario *</label>
                        <input type="text" name="username" class="form-control" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                        <small style="color: var(--text-muted);">Solo letras, números y guiones</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Contraseña *</label>
                        <input type="password" name="password" class="form-control" required minlength="6">
                        <small style="color: var(--text-muted);">Mínimo 6 caracteres</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Nombre completo</label>
                        <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Rol *</label>
                        <select name="role" class="form-control" required>
                            <option value="user" <?php echo ($_POST['role'] ?? '') === 'user' ? 'selected' : ''; ?>>Usuario</option>
                            <option value="admin" <?php echo ($_POST['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Cuota de almacenamiento (GB) *</label>
                        <input type="number" name="storage_quota" class="form-control" required value="<?php echo htmlspecialchars($_POST['storage_quota'] ?? '10'); ?>" min="1" max="1000">
                    </div>
                    
                    <hr style="margin: 1.5rem 0; border: none; border-top: 2px solid var(--border-color);">
                    
                    <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 1rem; color: var(--text-main);"><i class="fas fa-lock"></i> Autenticación de Dos Factores</h3>
                    
                    <div class="form-group">
                        <label>Método 2FA</label>
                        <select name="2fa_method" id="2fa_method" class="form-control">
                            <option value="none" <?php echo ($_POST['2fa_method'] ?? '') === 'none' ? 'selected' : ''; ?>>Sin 2FA</option>
                            <option value="totp" <?php echo ($_POST['2fa_method'] ?? '') === 'totp' ? 'selected' : ''; ?>>TOTP (Google Authenticator, Authy)</option>
                            <option value="duo" <?php echo ($_POST['2fa_method'] ?? '') === 'duo' ? 'selected' : ''; ?>>Duo Security</option>
                        </select>
                        <small style="color: var(--text-muted);">Selecciona el método de autenticación de dos factores</small>
                    </div>
                    
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="checkbox" name="require_2fa" value="1" <?php echo isset($_POST['require_2fa']) ? 'checked' : ''; ?>>
                            <span>Requerir 2FA obligatoriamente</span>
                        </label>
                        <small style="color: var(--text-muted); display: block; margin-left: 1.75rem;">El usuario no podrá iniciar sesión sin configurar 2FA</small>
                    </div>
                    
                    <div id="totp_info" style="display: none; background: linear-gradient(135deg, rgba(74, 144, 226, 0.1), rgba(80, 200, 120, 0.1)); padding: 1rem; border-radius: 0.5rem; border-left: 4px solid #4a90e2; margin-top: 1rem;">
                        <p style="margin: 0; font-size: 0.875rem; color: var(--text-main);"><strong>ℹ️ TOTP:</strong> Se generará un código QR que el usuario deberá escanear con su aplicación de autenticación. Si se envía email, el QR se incluirá en el mensaje.</p>
                    </div>
                    
                    <div id="duo_info" style="display: none; background: linear-gradient(135deg, rgba(155, 89, 182, 0.1), rgba(231, 76, 60, 0.1)); padding: 1rem; border-radius: 0.5rem; border-left: 4px solid #9b59b6; margin-top: 1rem;">
                        <p style="margin: 0; font-size: 0.875rem; color: var(--text-main);"><strong>ℹ️ Duo:</strong> El usuario deberá configurar Duo Security en su primer inicio de sesión siguiendo las instrucciones en pantalla.</p>
                    </div>
                    
                    <hr style="margin: 1.5rem 0; border: none; border-top: 2px solid var(--border-color);">
                    
                    <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 1rem; color: var(--text-main);"><i class="fas fa-envelope"></i> Notificación</h3>
                    
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="checkbox" name="send_email" id="send_email" value="1" <?php echo isset($_POST['send_email']) ? 'checked' : ''; ?>>
                            <span>Enviar credenciales por correo electrónico</span>
                        </label>
                        <small style="color: var(--text-muted); display: block; margin-left: 1.75rem;">Se enviará un email con las credenciales de acceso y el código QR de 2FA si aplica</small>
                    </div>
                    
                    <div id="email_warning" style="display: none; background: linear-gradient(135deg, rgba(255, 152, 0, 0.1), rgba(255, 193, 7, 0.1)); padding: 1rem; border-radius: 0.5rem; border-left: 4px solid #ff9800; margin-top: 1rem;">
                        <p style="margin: 0; font-size: 0.875rem; color: var(--text-main);"><strong>⚠️ Atención:</strong> Asegúrate de que el campo email esté completado para poder enviar las credenciales.</p>
                    </div>
                    
                    <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-primary" style="padding: 0.875rem 2rem; font-weight: 700;"><i class="fas fa-plus"></i> Crear Usuario</button>
                        <a href="<?php echo BASE_URL; ?>/admin/users.php" class="btn btn-outline" style="padding: 0.875rem 2rem; font-weight: 600;">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle 2FA info messages
document.getElementById('2fa_method').addEventListener('change', function() {
    const totpInfo = document.getElementById('totp_info');
    const duoInfo = document.getElementById('duo_info');
    
    totpInfo.style.display = 'none';
    duoInfo.style.display = 'none';
    
    if (this.value === 'totp') {
        totpInfo.style.display = 'block';
    } else if (this.value === 'duo') {
        duoInfo.style.display = 'block';
    }
});

// Toggle email warning
document.getElementById('send_email').addEventListener('change', function() {
    const emailWarning = document.getElementById('email_warning');
    const emailInput = document.querySelector('input[name="email"]');
    
    if (this.checked) {
        emailWarning.style.display = 'block';
        emailInput.required = true;
    } else {
        emailWarning.style.display = 'none';
        emailInput.required = false;
    }
});

// Check initial state
if (document.getElementById('send_email').checked) {
    document.getElementById('email_warning').style.display = 'block';
    document.querySelector('input[name="email"]').required = true;
}

// Check 2FA method initial state
const method = document.getElementById('2fa_method').value;
if (method === 'totp') {
    document.getElementById('totp_info').style.display = 'block';
} else if (method === 'duo') {
    document.getElementById('duo_info').style.display = 'block';
}
</script>

<?php renderPageEnd(); ?>
