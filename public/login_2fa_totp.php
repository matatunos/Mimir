<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/TwoFactor.php';
require_once __DIR__ . '/../classes/Logger.php';

// Session already started by Auth class
$auth = new Auth();
$twoFactor = new TwoFactor();
$logger = new Logger();

// Check if there's a pending 2FA verification
if (!isset($_SESSION['2fa_pending']) || !isset($_SESSION['2fa_user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$userId = $_SESSION['2fa_user_id'];
$error = '';

// Get user info
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: ' . BASE_URL . '/login.php?error=' . urlencode('Usuario no encontrado'));
    exit;
}

// Check if user is locked out
if ($twoFactor->isLockedOut($userId)) {
    $error = 'Demasiados intentos fallidos. Intenta de nuevo más tarde.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $code = $_POST['code'] ?? '';
    $useBackup = isset($_POST['use_backup']);
    $trustDevice = isset($_POST['trust_device']);
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    if (empty($code)) {
        $error = 'Por favor, introduce el código';
    } else {
        $config = $twoFactor->getUserConfig($userId);
        
        if (!$config) {
            $error = '2FA no configurado correctamente';
        } else {
            $valid = false;
            
            if ($useBackup) {
                // Verify backup code
                $valid = $twoFactor->verifyBackupCode($userId, $code);
                $method = 'totp_backup';
            } else {
                // Verify TOTP code
                $valid = $twoFactor->verifyTOTP($config['totp_secret'], $code);
                $method = 'totp';
            }
            
            // Log attempt
            $twoFactor->logAttempt($userId, $method, $valid, $ip, $userAgent);
            
            if ($valid) {
                // 2FA successful
                unset($_SESSION['2fa_pending']);
                unset($_SESSION['2fa_user_id']);
                
                // Trust device if requested
                if ($trustDevice) {
                    $deviceHash = $twoFactor->getDeviceHash();
                    $twoFactor->addTrustedDevice($userId, $deviceHash);
                }
                
                // Complete login using Auth class
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['is_ldap'] = $user['is_ldap'];
                $_SESSION['created'] = time();
                
                // Store session in database
                $db = Database::getInstance()->getConnection();
                $sessionId = session_id();
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $sessionData = json_encode($_SESSION);
                
                $stmt = $db->prepare("
                    INSERT INTO sessions (id, user_id, ip_address, user_agent, data) 
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        user_id = VALUES(user_id),
                        ip_address = VALUES(ip_address),
                        user_agent = VALUES(user_agent),
                        data = VALUES(data),
                        last_activity = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$sessionId, $user['id'], $ip, $userAgent, $sessionData]);
                
                // Update last login
                $stmt = $db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                $logger->log($userId, 'login_2fa_success', 'user', $userId, "Login con 2FA exitoso");
                
                header('Location: ' . BASE_URL . '/index.php');
                exit;
            } else {
                $error = $useBackup ? 'Código de respaldo incorrecto' : 'Código incorrecto';
                
                // Check if now locked out
                if ($twoFactor->isLockedOut($userId)) {
                    $error = 'Demasiados intentos fallidos. Cuenta bloqueada temporalmente.';
                    $logger->log($userId, 'login_2fa_locked', 'user', $userId, "Usuario bloqueado por intentos 2FA fallidos");
                }
            }
        }
    }
}
?>
<?php
require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../includes/layout.php';

$configClass = new Config();
$siteName = $configClass->get('site_name', 'Mimir');
$primaryColor = $configClass->get('brand_primary_color', '#1e40af');
$primaryTextColor = getTextColorForBackground($primaryColor);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación 2FA - <?php echo htmlspecialchars($siteName); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
    :root {
        --brand-primary: <?php echo htmlspecialchars($primaryColor); ?> !important;
        --primary-color: <?php echo htmlspecialchars($primaryColor); ?> !important;
    }
    .btn-primary, button.btn-primary {
        background: <?php echo htmlspecialchars($primaryColor); ?> !important;
        color: <?php echo htmlspecialchars($primaryTextColor); ?> !important;
    }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>🔐 Mimir</h1>
                <p>Verificación de Dos Factores</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div style="text-align: center; margin-bottom: 1.5rem;">
                <div style="font-size: 3rem; margin-bottom: 0.5rem;">🔑</div>
                <p style="color: var(--text-muted);">
                    Hola, <strong><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></strong>
                </p>
                <p style="font-size: 0.875rem; color: var(--text-muted);">
                    Introduce el código de tu aplicación de autenticación
                </p>
            </div>
            
            <form method="POST" id="totpForm">
                <div class="form-group">
                    <label for="code">Código de verificación</label>
                    <input 
                        type="text" 
                        id="code" 
                        name="code" 
                        class="form-control" 
                        placeholder="000000"
                        maxlength="8"
                        pattern="[0-9]{6,8}"
                        autocomplete="one-time-code"
                        inputmode="numeric"
                        style="font-size: 1.5rem; text-align: center; letter-spacing: 0.5rem; font-family: monospace;"
                        required 
                        autofocus
                    >
                </div>
                
                <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem;">
                    <input type="checkbox" id="trust_device" name="trust_device" value="1">
                    <label for="trust_device" style="margin: 0; font-weight: normal; cursor: pointer;">
                        Confiar en este dispositivo por 30 días
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">Verificar</button>
            </form>
            
            <div style="text-align: center; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
                <button 
                    type="button" 
                    class="btn btn-outline btn-sm" 
                    onclick="toggleBackupCode()"
                    style="font-size: 0.875rem;"
                >
                    📋 Usar código de respaldo
                </button>
            </div>
            
            <div style="text-align: center; margin-top: 1rem;">
                <a href="<?php echo BASE_URL; ?>/logout.php" style="color: var(--text-muted); font-size: 0.875rem;">
                    ← Volver al login
                </a>
            </div>
        </div>
    </div>
    
    <script>
    let useBackup = false;
    
    function toggleBackupCode() {
        useBackup = !useBackup;
        const form = document.getElementById('totpForm');
        const codeInput = document.getElementById('code');
        
        if (useBackup) {
            form.insertAdjacentHTML('afterbegin', '<input type="hidden" name="use_backup" value="1">');
            codeInput.placeholder = 'Código de respaldo';
            codeInput.maxLength = 16;
            codeInput.pattern = '[0-9a-f]{8,16}';
            codeInput.style.letterSpacing = '0.3rem';
        } else {
            const backupInput = form.querySelector('input[name="use_backup"]');
            if (backupInput) backupInput.remove();
            codeInput.placeholder = '000000';
            codeInput.maxLength = 8;
            codeInput.pattern = '[0-9]{6,8}';
            codeInput.style.letterSpacing = '0.5rem';
        }
        
        codeInput.value = '';
        codeInput.focus();
    }
    
    // Auto-submit on 6 digits
    document.getElementById('code').addEventListener('input', function(e) {
        if (!useBackup && e.target.value.length === 6) {
            // Small delay to show the complete code
            setTimeout(() => {
                document.getElementById('totpForm').submit();
            }, 300);
        }
    });
    </script>
</body>
</html>
