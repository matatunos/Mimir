<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/TwoFactor.php';
require_once __DIR__ . '/../classes/DuoAuth.php';

$auth = new Auth();

// Check if already logged in (but not if 2FA is pending)
if ($auth->isLoggedIn() && empty($_SESSION['2fa_pending'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$error = $_GET['error'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Por favor, introduce usuario y contraseña';
    } else {
        // First, verify username and password
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            // Credentials valid, check for 2FA
            $twoFactor = new TwoFactor();
            
            if ($twoFactor->isEnabled($user['id'])) {
                // Check if device is trusted
                $deviceHash = $twoFactor->getDeviceHash();
                if ($twoFactor->isDeviceTrusted($user['id'], $deviceHash)) {
                    // Trusted device, skip 2FA
                    if ($auth->login($username, $password)) {
                        header('Location: ' . BASE_URL . '/index.php');
                        exit;
                    }
                } else {
                    // 2FA is enabled, set pending state
                    $_SESSION['2fa_user_id'] = $user['id'];
                    $_SESSION['2fa_pending'] = true;
                    
                    // Get 2FA config
                    $config = $twoFactor->getUserConfig($user['id']);
                    
                    if (!$config) {
                        $error = 'ERROR DEBUG: 2FA habilitado pero sin configuración. User ID: ' . $user['id'];
                    } elseif ($config['method'] === 'totp') {
                        // Redirect to TOTP verification
                        header('Location: ' . BASE_URL . '/login_2fa_totp.php');
                        exit;
                    } elseif ($config['method'] === 'duo') {
                        // Generate Duo auth URL and redirect
                        $duoAuth = new DuoAuth();
                        $authUrl = $duoAuth->generateAuthUrl($user['username']);
                        
                        if ($authUrl) {
                            header('Location: ' . $authUrl);
                            exit;
                        } else {
                            $error = 'Error al iniciar autenticación Duo';
                        }
                    }
                }
            } else {
                // No 2FA, complete login normally
                if ($auth->login($username, $password)) {
                    header('Location: ' . BASE_URL . '/index.php');
                    exit;
                }
            }
        } else {
            $error = 'Usuario o contraseña incorrectos';
        }
    }
}
?>
<?php
require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../includes/layout.php';

$configClass = new Config();
$siteName = $configClass->get('site_name', 'Mimir');
$logo = $configClass->get('site_logo', '');
$primaryColor = $configClass->get('brand_primary_color', '#1e40af');
$accentColor = $configClass->get('brand_accent_color', '#0ea5e9');

// Calculate appropriate text colors for buttons
$primaryTextColor = getTextColorForBackground($primaryColor);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo htmlspecialchars($siteName); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
    :root {
        --brand-primary: <?php echo htmlspecialchars($primaryColor); ?> !important;
        --brand-accent: <?php echo htmlspecialchars($accentColor); ?> !important;
        --primary-color: <?php echo htmlspecialchars($primaryColor); ?> !important;
        --accent-color: <?php echo htmlspecialchars($accentColor); ?> !important;
    }
    
    /* Ensure proper text contrast on login button */
    .btn-primary,
    button.btn-primary {
        background: <?php echo htmlspecialchars($primaryColor); ?> !important;
        color: <?php echo htmlspecialchars($primaryTextColor); ?> !important;
    }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo">
                    <?php if ($logo): ?>
                        <img src="<?php echo BASE_URL . '/' . htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($siteName); ?>" style="max-height: 60px; max-width: 200px;">
                    <?php else: ?>
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path>
                            <polyline points="13 2 13 9 20 9"></polyline>
                        </svg>
                    <?php endif; ?>
                </div>
                <h1><?php echo htmlspecialchars($siteName); ?></h1>
                <p style="margin-top: 0.5rem; color: var(--text-muted);">Inicia sesión para continuar</p>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username" class="form-label required">Usuario</label>
                    <input type="text" id="username" name="username" class="form-control" required autofocus value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="password" class="form-label required">Contraseña</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                <div class="form-check">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember">Recordarme</label>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1.5rem;">Iniciar Sesión</button>
            </form>
        </div>
    </div>
</body>
</html>
