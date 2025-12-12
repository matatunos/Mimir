<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Invitation.php';
require_once __DIR__ . '/../classes/SecurityValidator.php';
require_once __DIR__ . '/../classes/SecurityHeaders.php';
require_once __DIR__ . '/../classes/TwoFactor.php';
require_once __DIR__ . '/../classes/Logger.php';

// Apply security headers
SecurityHeaders::applyAll();

$security = SecurityValidator::getInstance();
$configClass = new Config();
$invitationClass = new Invitation();
$userClass = new User();
$auth = new Auth();

$error = '';
$success = '';

// Get token from GET or POST
$token = $_REQUEST['token'] ?? '';

// Load invitation (validates not used/expired)

$inv = null;
if ($token) {
    $inv = $invitationClass->getByToken($token);
}

if (!$inv) {
    $error = 'Token de invitación inválido o expirado.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inv) {
    // If invitation forces a username, ignore user input and use forced value
    $forcedUsername = $inv['forced_username'] ?? null;
    $username = $forcedUsername ? $security->sanitizeString($forcedUsername) : $security->sanitizeString($_POST['username'] ?? '');
    $fullName = $security->sanitizeString($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password_confirm'] ?? '';

    if (empty($username) || empty($password) || empty($password2)) {
        $error = 'Por favor rellena todos los campos obligatorios.';
    } elseif ($password !== $password2) {
        $error = 'Las contraseñas no coinciden.';
    } elseif (strlen($password) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } else {
        // Check username availability
        $existing = $userClass->getByUsername($username);
        if ($existing) {
            // If username was forced, instruct to contact admin; otherwise allow user to choose different
            if ($forcedUsername) {
                $error = 'El nombre de usuario forzado ya existe. Contacta con el administrador.';
            } else {
                $error = 'El usuario ya existe. Elige otro nombre de usuario.';
            }
        } else {
            // If an account already exists with the invited email, log and continue (duplicates allowed)
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT id, username FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$inv['email']]);
            $byEmail = $stmt->fetch();
            if ($byEmail) {
                $logger = new Logger();
                $existingUserId = $byEmail['id'] ?? null;
                $existingUsername = $byEmail['username'] ?? '';
                $logger->log($existingUserId, 'invitation_duplicate_email', 'invitation', $inv['id'] ?? null, "Invitation accepted for email that already has an account: {$inv['email']} (existing_user_id={$existingUserId}, username={$existingUsername})");
                // continue and attempt to create user with same email (DB must allow duplicates)
            }

            // Create user
            $data = [
                'username' => $username,
                'email' => $inv['email'],
                'password' => $password,
                'full_name' => $fullName,
                'role' => $inv['role'] ?? 'user',
                'is_active' => 1,
                'require_2fa' => (!empty($inv['force_2fa']) && $inv['force_2fa'] !== 'none') ? 1 : 0
            ];

            $newUserId = $userClass->create($data);
            if ($newUserId) {
                // Mark invitation used
                $invitationClass->markUsed($inv['id'], $newUserId);

                // Log the creation as performed via invitation (attribute to inviter if present)
                $logger = new Logger();
                $logger->log($inv['inviter_id'] ?? null, 'user_created_via_invite', 'user', $newUserId, "User created via invitation for {$inv['email']}");

                // Record who accepted the invitation: prefer existing user id (if email already had an account), otherwise the new user
                $actorId = $existingUserId ?? $newUserId;
                try {
                    $logger->log($actorId, 'invitation_accepted_by', 'invitation', $inv['id'] ?? null, "Invitation accepted for {$inv['email']} by user_id={$actorId}");
                } catch (Exception $e) {
                    error_log("Failed to log invitation_accepted_by: " . $e->getMessage());
                }

                // If 2FA forced and method is totp, prepare TOTP setup flow
                $force2fa = $inv['force_2fa'] ?? 'none';
                if ($force2fa === 'totp') {
                    $twoFactor = new TwoFactor();
                    // generate temporary secret and store in session for setup flow
                    $_SESSION['2fa_temp_secret'] = $twoFactor->generateSecret();
                }

                // Attempt to log user in
                if ($auth->login($username, $password)) {
                    // If 2FA forced, redirect to setup page immediately
                    if (!empty($inv['force_2fa']) && $inv['force_2fa'] !== 'none') {
                        if ($inv['force_2fa'] === 'totp') {
                            header('Location: ' . BASE_URL . '/user/2fa_setup.php?step=setup_totp_verify');
                            exit;
                        } elseif ($inv['force_2fa'] === 'duo') {
                            header('Location: ' . BASE_URL . '/user/2fa_setup.php?step=setup_duo');
                            exit;
                        }
                    }

                    header('Location: ' . BASE_URL . '/index.php');
                    exit;
                } else {
                    $success = 'Cuenta creada correctamente. Ahora puedes iniciar sesión.';
                }
            } else {
                // Capture DB error info for debugging (temporary)
                $dbConn = Database::getInstance()->getConnection();
                $dbErr = $dbConn->errorInfo();
                $dbErrMsg = isset($dbErr[2]) ? $dbErr[2] : 'Unknown DB error';
                error_log("User create failed (invite {$inv['id']}): " . $dbErrMsg);
                try {
                    $dbgLogger = new Logger();
                    $dbgLogger->log(null, 'user_create_failed', 'user', null, "User create failed for invite {$inv['id']}: {$dbErrMsg}", ['db_error' => $dbErr]);
                } catch (Exception $e) {
                    error_log("Logger failed while recording user_create_failed: " . $e->getMessage());
                }

                $error = 'Error creando la cuenta. Inténtalo de nuevo más tarde.';
            }
        }
    }
}

require_once __DIR__ . '/../includes/layout.php';

$siteName = $configClass->get('site_name', 'Mimir');
$logo = $configClass->get('site_logo', '');
$primaryColor = $configClass->get('brand_primary_color', '#1e40af');
$accentColor = $configClass->get('brand_accent_color', '#0ea5e9');
$primaryTextColor = getTextColorForBackground($primaryColor);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aceptar invitación - <?php echo htmlspecialchars($siteName); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
    :root {
        --brand-primary: <?php echo htmlspecialchars($primaryColor); ?> !important;
        --brand-accent: <?php echo htmlspecialchars($accentColor); ?> !important;
    }
    .btn-primary { background: <?php echo htmlspecialchars($primaryColor); ?> !important; color: <?php echo htmlspecialchars($primaryTextColor); ?> !important; }
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
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg>
                    <?php endif; ?>
                </div>
                <h1><?php echo htmlspecialchars($siteName); ?></h1>
                <p style="margin-top: 0.5rem; color: var(--text-muted);">Acepta la invitación para crear tu cuenta</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($inv): ?>
                <form method="POST" action="">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                            <?php if (!empty($inv['forced_username'])): ?>
                                <div class="form-group">
                                    <label class="form-label required">Usuario asignado</label>
                                    <div style="padding:0.5rem 0.75rem; background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:4px;"><?php echo htmlspecialchars($inv['forced_username']); ?></div>
                                    <input type="hidden" name="username" value="<?php echo htmlspecialchars($inv['forced_username']); ?>">
                                </div>
                            <?php else: ?>
                                <div class="form-group">
                                    <label for="username" class="form-label required">Usuario</label>
                                    <input type="text" id="username" name="username" class="form-control" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                                </div>
                            <?php endif; ?>
                    <div class="form-group">
                        <label for="full_name" class="form-label">Nombre completo</label>
                        <input type="text" id="full_name" name="full_name" class="form-control" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="password" class="form-label required">Contraseña</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="password_confirm" class="form-label required">Confirmar contraseña</label>
                        <input type="password" id="password_confirm" name="password_confirm" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%; margin-top:1rem;">Crear cuenta</button>
                </form>
            <?php else: ?>
                <p>El enlace de invitación no es válido o ha expirado.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
