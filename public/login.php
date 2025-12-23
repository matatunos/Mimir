<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../classes/TwoFactor.php';
require_once __DIR__ . '/../classes/DuoAuth.php';
require_once __DIR__ . '/../classes/SecurityValidator.php';
require_once __DIR__ . '/../classes/SecurityHeaders.php';
require_once __DIR__ . '/../includes/lang.php';

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

// Determine default and available languages for selector
$defaultLang = $configClass->get('default_language', 'es');
$availableLangs = get_available_languages();

// If login form posted a language selection, persist it into session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['lang'])) {
    $sel = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['lang']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['lang'] = $sel;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $security->sanitizeString($_POST['username'] ?? '');
    $password = $_POST['password'] ?? ''; // Don't sanitize password

    // Get client IP
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (empty($username) || empty($password)) {
        $error = t('error_enter_username_password');
    } else {
        // Check rate limiting - configurable attempts per IP in X minutes
        if (!$security->checkIPRateLimit($clientIP, 'failed_login', $ipRateThreshold, $ipRateWindow)) {
            $error = t('error_too_many_attempts', [intval($ipRateWindow)]);
        } elseif ($security->detectSQLInjection($username)) {
            $error = t('error_invalid_input');
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
                    $error = t('error_account_locked_until', [date('Y-m-d H:i:s', $lockedUntil)]);
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
                // Credentials valid, check if user must change password on first login
                if (!empty($user['force_password_change'])) {
                    // Mark forced-change in session and redirect to change password flow
                    $_SESSION['force_password_change_user_id'] = $user['id'];
                    $_SESSION['force_password_change_username'] = $user['username'];
                    header('Location: ' . BASE_URL . '/change_password.php?forced=1');
                    exit;
                }

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
                            $error = t('error_trusted_device_login');
                        }
                    } else {
                        // 2FA is enabled, set pending state
                        $_SESSION['2fa_user_id'] = $user['id'];
                        $_SESSION['2fa_pending'] = true;

                        // Get 2FA config
                        $config = $twoFactor->getUserConfig($user['id']);

                        if (!$config) {
                            $error = t('error_2fa_enabled_no_config_debug', [$user['id']]);
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
                                $error = t('error_duo_auth');
                            }
                        }
                    }
                } else {
                    // No 2FA, complete login normally
                    if ($auth->login($username, $password)) {
                        header('Location: ' . BASE_URL . '/index.php');
                        exit;
                    } else {
                        $error = t('error_login_try_again');
                    }
                }

            } else {
                // Local auth failed — try external auth (AD/LDAP)
                    if ($auth->login($username, $password)) {
                    // If 2FA was set as pending by the Auth layer (e.g. AD/LDAP users), handle it here
                    if (!empty($_SESSION['2fa_pending']) && !empty($_SESSION['2fa_user_id'])) {
                        $twoFactor = new TwoFactor();
                        $config = $twoFactor->getUserConfig($_SESSION['2fa_user_id']);

                        if (!$config) {
                            $error = t('error_2fa_enabled_no_config_debug', [$_SESSION['2fa_user_id']]);
                        } elseif ($config['method'] === 'totp') {
                            header('Location: ' . BASE_URL . '/login_2fa_totp.php');
                            exit;
                        } elseif ($config['method'] === 'duo') {
                            $duoAuth = new DuoAuth();
                            // Prefer stored duo_username if available
                            $duoUsername = $twoFactor->getDuoUsername($_SESSION['2fa_user_id']);
                            $authUrl = $duoAuth->generateAuthUrl($duoUsername ?: $username);

                            if ($authUrl) {
                                header('Location: ' . $authUrl);
                                exit;
                            } else {
                                $error = t('error_duo_auth');
                            }
                        }

                        // If we reach here, clear pending markers and fall through (to show error)
                        unset($_SESSION['2fa_pending']);
                        unset($_SESSION['2fa_user_id']);
                    }

                    header('Location: ' . BASE_URL . '/index.php');
                    exit;
                }

                // External auth also failed — log failed attempt

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

                $error = t('error_invalid_credentials');
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
<html lang="<?php echo htmlspecialchars($_SESSION['lang'] ?? 'es'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('login_title', [$siteName])); ?></title>
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
                        <img src="<?php echo BASE_URL . '/_asset.php?f=' . urlencode($logo); ?>" alt="<?php echo htmlspecialchars($siteName); ?>" style="max-height: 60px; max-width: 200px;">
                    <?php else: ?>
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path>
                            <polyline points="13 2 13 9 20 9"></polyline>
                        </svg>
                    <?php endif; ?>
                </div>
                <?php if (!$logo): ?>
                    <h1><?php echo htmlspecialchars($siteName); ?></h1>
                <?php endif; ?>
                <p style="margin-top: 0.5rem; color: var(--text-muted);"><?php echo htmlspecialchars(t('login_prompt')); ?></p>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username" class="form-label required"><?php echo htmlspecialchars(t('label_username')); ?></label>
                    <input type="text" id="username" name="username" class="form-control" required autofocus value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="password" class="form-label required"><?php echo htmlspecialchars(t('label_password')); ?></label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="lang"><?php echo htmlspecialchars(t('label_language')); ?></label>
                    <?php $selLang = session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['lang']) ? $_SESSION['lang'] : $defaultLang; ?>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <div id="lang-flag" style="line-height:1;">
                            <?php echo get_language_flag($selLang); ?>
                        </div>
                        <select id="lang" name="lang" class="form-control" style="flex:1;">
                        <?php
                        foreach ($availableLangs as $code => $name):
                            // Use emoji-only flags inside <option> to avoid raw HTML showing
                            $flag = get_language_flag_emoji($code);
                        ?>
                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $selLang === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars(($flag ? $flag . ' ' : '') . $name); ?></option>
                        <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-check">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember"><?php echo htmlspecialchars(t('remember_me')); ?></label>
                </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1.5rem;"><?php echo htmlspecialchars(t('login_button')); ?></button>
                    <?php if ((bool)$configClass->get('enable_email', '0')): ?>
                        <p style="margin-top:0.75rem; text-align:center;"><a href="<?php echo BASE_URL; ?>/password_reset_request.php"><?php echo htmlspecialchars(t('forgot_password')); ?></a></p>
                    <?php endif; ?>
                </form>
        </div>
    </div>
    <script>
    (function(){
        const sel = document.getElementById('lang');
        const flagContainer = document.getElementById('lang-flag');
        const applyTranslations = (data) => {
            if (!data) return;
            // Title
            if (data.login_title) document.title = data.login_title;
            // Prompt paragraph
            const p = document.querySelector('.login-header p');
            if (p && data.login_prompt) p.textContent = data.login_prompt;
            // Labels
            const lUser = document.querySelector('label[for="username"]');
            if (lUser && data.label_username) lUser.textContent = data.label_username;
            const lPass = document.querySelector('label[for="password"]');
            if (lPass && data.label_password) lPass.textContent = data.label_password;
            const lLang = document.querySelector('label[for="lang"]');
            if (lLang && data.label_language) lLang.textContent = data.label_language;
            const lRemember = document.querySelector('label[for="remember"]');
            if (lRemember && data.remember_me) lRemember.textContent = data.remember_me;
            const btn = document.querySelector('button.btn-primary');
            if (btn && data.login_button) btn.textContent = data.login_button;
            const fp = document.querySelector('a[href$="password_reset_request.php"]');
            if (fp && data.forgot_password) fp.textContent = data.forgot_password;
            // Flag HTML
            if (flagContainer && data.flag_html) flagContainer.innerHTML = data.flag_html;
        };

        const fetchAndApply = (code) => {
            fetch('lang_ajax.php?lang=' + encodeURIComponent(code))
                .then(r => r.json())
                .then(applyTranslations)
                .catch(()=>{});
        };

        sel.addEventListener('change', function(){
            const code = this.value;
            // Persist selection to session via form submit (server-side already handles POST),
            // but we also update UI immediately via AJAX.
            fetchAndApply(code);
        });

        // Ensure UI matches selected language immediately
        fetchAndApply(sel.value);
    })();
    // Make Enter in password field trigger the login button click
    (function(){
        const pwd = document.getElementById('password');
        if (!pwd) return;
        pwd.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                const btn = document.querySelector('button.btn-primary[type="submit"]');
                if (btn) {
                    btn.click();
                } else if (this.form) {
                    this.form.submit();
                }
            }
        });
    })();
    </script>
</body>
</html>
