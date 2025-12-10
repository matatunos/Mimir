<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../classes/TwoFactor.php';
require_once __DIR__ . '/../classes/DuoAuth.php';
require_once __DIR__ . '/../classes/SecurityValidator.php';
require_once __DIR__ . '/../classes/SecurityHeaders.php';

// Apply security headers
SecurityHeaders::applyAll();

$auth = new Auth();
$security = SecurityValidator::getInstance();

// Load config values for user lock behavior
$configClass = new Config();
$enableUserLock = $configClass->get('enable_user_lock', 1);
$userLockThreshold = $configClass->get('user_lock_threshold', 5);
$userLockWindow = $configClass->get('user_lock_window_minutes', 15);
$userLockDuration = $configClass->get('user_lock_duration_minutes', 15);
// IP rate limit configuration (configurable)
$ipRateThreshold = $configClass->get('ip_rate_limit_threshold', 5);
$ipRateWindow = $configClass->get('ip_rate_limit_window_minutes', 15);

// Check if already logged in (but not if 2FA is pending)
if ($auth->isLoggedIn() && empty($_SESSION['2fa_pending'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$error = $_GET['error'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $security->sanitizeString($_POST['username'] ?? '');
    $password = $_POST['password'] ?? ''; // Don't sanitize password

    // Get client IP
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (empty($username) || empty($password)) {
        $error = 'Por favor, introduce usuario y contraseña';
    } else {
        // Check rate limiting - configurable attempts per IP in X minutes
        if (!$security->checkIPRateLimit($clientIP, 'failed_login', $ipRateThreshold, $ipRateWindow)) {
            $error = 'Demasiados intentos fallidos desde tu IP (bloqueo temporal por IP). Por favor, espera ' . intval($ipRateWindow) . ' minutos antes de intentar de nuevo.';
        } elseif ($security->detectSQLInjection($username)) {
            $error = 'Entrada no válida detectada';
        } else {
            // First, verify username and password against local DB
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // If user exists, check locked_until
            if ($user && !empty($user['locked_until'])) {
                $lockedUntil = strtotime($user['locked_until']);
                if ($lockedUntil > time()) {
                    $error = 'Cuenta bloqueada hasta ' . date('Y-m-d H:i:s', $lockedUntil);
                    // Skip further verification
                    goto RENDER_LOGIN;
                } else {
                    // expired lock - clear it
                    $stmtClear = $db->prepare("UPDATE users SET locked_until = NULL WHERE id = ?");
                    $stmtClear->execute([$user['id']]);
                    $user['locked_until'] = null;
                }
            }

            // Local password verification
            if ($user && !empty($user['password']) && password_verify($password, $user['password'])) {
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
                        } else {
                            $error = 'Error al iniciar sesión en dispositivo confiable.';
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
                    } else {
                        $error = 'Error al iniciar sesión. Por favor, inténtalo de nuevo.';
                    }
                }

            } else {
                // Local auth failed — try external auth (AD/LDAP)
                if ($auth->login($username, $password)) {
                    header('Location: ' . BASE_URL . '/index.php');
                    exit;
                }

                // External auth also failed — log failed attempt
                // Add temporary debug log to help diagnose web vs CLI differences
                try {
                    $dbg = [];
                    $dbg['timestamp'] = date('c');
                    $dbg['username'] = $username;
                    $dbg['php_sapi'] = php_sapi_name();
                    $dbg['ldap_extension'] = function_exists('ldap_connect') ? 'yes' : 'no';

                    // Read AD/LDAP flags from DB to see what the web process is configured to use
                    try {
                        $cfgStmt = $db->prepare("SELECT config_key, config_value FROM config WHERE config_key IN ('enable_ldap','enable_ad','ad_host','ldap_host')");
                        $cfgStmt->execute();
                        $cfgRows = $cfgStmt->fetchAll();
                        foreach ($cfgRows as $r) $dbg[$r['config_key']] = $r['config_value'];
                    } catch (Exception $e) {
                        $dbg['cfg_error'] = $e->getMessage();
                    }

                    // Run testConnection on AD and LDAP (if possible)
                    if (function_exists('ldap_connect')) {
                        try {
                            require_once __DIR__ . '/../includes/ldap.php';
                            $ad = new LdapAuth('ad');
                            $ldap = new LdapAuth('ldap');
                            $dbg['ad_test'] = $ad->testConnection();
                            $dbg['ldap_test'] = $ldap->testConnection();
                        } catch (Exception $e) {
                            $dbg['ldap_test_error'] = $e->getMessage();
                        }
                    }

                    @file_put_contents('/tmp/mimir_ldap_debug.log', json_encode($dbg) . PHP_EOL, FILE_APPEND | LOCK_EX);
                } catch (Exception $e) {
                    error_log('Debug write failed: ' . $e->getMessage());
                }

                $stmtIns = $db->prepare("INSERT INTO security_events (event_type, username, severity, ip_address, user_agent, description, details) VALUES ('failed_login', ?, 'low', ?, ?, ?, ?)");
                $detailsJson = json_encode(['username' => $username, 'time' => date('Y-m-d H:i:s')]);
                $stmtIns->execute([
                    $username,
                    $clientIP,
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'Intento de login fallido',
                    $detailsJson
                ]);

                // If user lock enabled and user exists, count recent failed attempts and lock if threshold exceeded
                if ($user && $enableUserLock) {
                    // Count failed_login attempts using the dedicated username column (fallback to details JSON if needed)
                    $stmtCount = $db->prepare("SELECT COUNT(*) as attempts FROM security_events WHERE event_type='failed_login' AND username = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
                    $stmtCount->execute([$username, $userLockWindow]);
                    $res = $stmtCount->fetch(PDO::FETCH_ASSOC);
                    $attempts = $res['attempts'] ?? 0;

                    if ($attempts >= $userLockThreshold) {
                        // Lock user
                        $stmtLock = $db->prepare("UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?");
                        $stmtLock->execute([$userLockDuration, $user['id']]);

                        // Insert rate_limit event
                        $stmtRate = $db->prepare("INSERT INTO security_events (event_type, username, severity, ip_address, user_agent, description, details, action_taken) VALUES ('rate_limit', ?, 'high', ?, ?, ?, ?, 'user locked')");
                        $rateDetails = json_encode(['username' => $username, 'attempts' => $attempts, 'threshold' => $userLockThreshold]);
                        $stmtRate->execute([$username, $clientIP, $_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 'Usuario bloqueado por intentos fallidos', $rateDetails]);
                    }
                }

                $error = 'Usuario o contraseña incorrectos';
                // Sleep to slow down brute force attacks
                sleep(2);
            }
        }
    }
}

RENDER_LOGIN:

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
