<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/TwoFactor.php';
require_once __DIR__ . '/../../classes/DuoAuth.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireAdmin();
$adminUser = $auth->getUser();
$twoFactor = new TwoFactor();
$duoAuth = new DuoAuth();
$userClass = new User();
$logger = new Logger();

$success = '';
$error = '';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    } else {
        $action = $_POST['action'] ?? '';
        $userId = intval($_POST['user_id'] ?? 0);
        
        if ($action === 'force_enable') {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("UPDATE users SET require_2fa = 1 WHERE id = ?");
            $stmt->execute([$userId]);
            if ($stmt->execute()) {
                $user = $userClass->getById($userId);
                $logger->log($adminUser['id'], '2fa_required', 'user', $userId, "2FA marcado como obligatorio para {$user['username']}");
                $success = 'Usuario marcado para requerir 2FA';
            } else {
                $error = 'Error al actualizar usuario';
            }
        } elseif ($action === 'force_disable') {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("UPDATE users SET require_2fa = 0 WHERE id = ?");
            $stmt->execute([$userId]);
            if ($stmt->execute()) {
                $user = $userClass->getById($userId);
                $logger->log($adminUser['id'], '2fa_optional', 'user', $userId, "2FA ya no es obligatorio para {$user['username']}");
                $success = '2FA ya no es obligatorio para este usuario';
            } else {
                $error = 'Error al actualizar usuario';
            }
        } elseif ($action === 'reset_2fa') {
            if ($twoFactor->disable($userId)) {
                $user = $userClass->getById($userId);
                $logger->log($adminUser['id'], '2fa_reset', 'user', $userId, "2FA reseteado para {$user['username']}");
                $success = '2FA reseteado correctamente';
                
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => $success]);
                    exit;
                }
            } else {
                $error = 'Error al resetear 2FA';
                
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $error]);
                    exit;
                }
            }
        } elseif ($action === 'clear_lockout') {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("DELETE FROM 2fa_attempts WHERE user_id = ? AND success = 0");
            $stmt->execute([$userId]);
            if ($stmt->execute()) {
                $user = $userClass->getById($userId);
                $logger->log($adminUser['id'], '2fa_unlock', 'user', $userId, "Bloqueo 2FA eliminado para {$user['username']}");
                $success = 'Bloqueo eliminado correctamente';
            } else {
                $error = 'Error al eliminar bloqueo';
            }
        } elseif ($action === 'setup_totp') {
            // Generate TOTP secret and QR for user
            $user = $userClass->getById($userId);
            if ($user) {
                $secret = $twoFactor->generateSecret();
                $backupCodes = $twoFactor->generateBackupCodes(10);
                
                // Enable 2FA for the user
                if ($twoFactor->enable($userId, 'totp', [
                    'secret' => $secret,
                    'backup_codes' => $backupCodes
                ])) {
                    // Store in session for display
                    $_SESSION['admin_setup_2fa'] = [
                        'user_id' => $userId,
                        'username' => $user['username'],
                        'secret' => $secret,
                        'backup_codes' => $backupCodes,
                        'qr_code' => $twoFactor->generateQRCode($user['username'], $secret)
                    ];
                    $logger->log($adminUser['id'], '2fa_admin_setup', 'user', $userId, "Admin configuró 2FA TOTP para {$user['username']}");
                    header('Location: ' . BASE_URL . '/admin/2fa_management.php?show_qr=1');
                    exit;
                }
            }
        } elseif ($action === 'setup_duo') {
            // Enable Duo 2FA for user
            $user = $userClass->getById($userId);
            if ($user) {
                // Check if Duo is configured
                $duoConfig = $duoAuth->getConfig();
                if (!$duoConfig['is_configured']) {
                    $error = 'Duo no está configurado en el sistema';
                } else {
                    if ($twoFactor->enable($userId, 'duo', [])) {
                        $logger->log($adminUser['id'], '2fa_admin_setup', 'user', $userId, "Admin configuró 2FA Duo para {$user['username']}");
                        $success = "Duo Security configurado para {$user['username']}. El usuario lo usará en su próximo login.";
                    } else {
                        $error = 'Error al configurar Duo';
                    }
                }
            }
        } elseif ($action === 'send_qr_email') {
            // Send QR code by email
            $user = $userClass->getById($userId);
            if ($user && !empty($user['email'])) {
                $config = $twoFactor->getUserConfig($userId);
                if ($config && $config['method'] === 'totp') {
                    $qrCode = $twoFactor->generateQRCode($user['username'], $config['totp_secret']);
                    
                    // Get backup codes if available
                    $backupCodesHtml = '';
                    if (!empty($config['backup_codes'])) {
                        $backupCodesHtml = '<h3 style="color: #333;">Códigos de Respaldo</h3>';
                        $backupCodesHtml .= '<p>Guarda estos códigos en un lugar seguro. Cada uno puede usarse una sola vez si no tienes acceso a tu app autenticadora:</p>';
                        $backupCodesHtml .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 20px 0;">';
                        foreach ($config['backup_codes'] as $code) {
                            $backupCodesHtml .= '<div style="background: #f5f5f5; padding: 10px; font-family: monospace; border-radius: 4px; text-align: center;">' . htmlspecialchars($code) . '</div>';
                        }
                        $backupCodesHtml .= '</div>';
                    }
                    
                    // Prepare HTML email
                    $to = $user['email'];
                    $subject = 'Configuración 2FA - Mimir';
                    
                    // Create HTML message
                    $htmlMessage = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #007bff; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #fff; padding: 30px; border: 1px solid #ddd; border-radius: 0 0 8px 8px; }
        .qr-container { text-align: center; margin: 30px 0; padding: 20px; background: #f9f9f9; border-radius: 8px; }
        .qr-container img { max-width: 250px; border: 2px solid #007bff; border-radius: 8px; }
        .secret-code { background: #f5f5f5; padding: 15px; margin: 20px 0; border-left: 4px solid #007bff; font-family: monospace; font-size: 18px; letter-spacing: 2px; text-align: center; }
        .instructions { background: #e7f3ff; padding: 15px; border-radius: 8px; margin: 20px 0; }
        .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="margin: 0;"><i class="fas fa-lock"></i> Configuración 2FA</h1>
        </div>
        <div class="content">
            <p>Hola <strong>' . htmlspecialchars($user['full_name'] ?: $user['username']) . '</strong>,</p>
            
            <p>El administrador ha configurado la <strong>autenticación de dos factores (2FA)</strong> para tu cuenta en Mimir.</p>
            
            <div class="qr-container">
                <h3 style="margin-top: 0;">Escanea este código QR</h3>
                <img src="' . $qrCode . '" alt="QR Code 2FA">
                <p style="margin-bottom: 0; color: #666;"><small>Usa Google Authenticator, Authy, Microsoft Authenticator, etc.</small></p>
            </div>
            
            <div class="instructions">
                <h3 style="margin-top: 0;">📱 Instrucciones</h3>
                <ol style="margin: 0;">
                    <li>Descarga una app de autenticación en tu teléfono (si no tienes una)</li>
                    <li>Abre la app y selecciona "Añadir cuenta" o escanear código QR</li>
                    <li>Escanea el código QR de arriba</li>
                    <li>La app generará códigos de 6 dígitos cada 30 segundos</li>
                    <li>Usa estos códigos al iniciar sesión en Mimir</li>
                </ol>
            </div>
            
            <h3 style="color: #333;">Código Secreto Manual</h3>
            <p>Si no puedes escanear el QR, introduce este código manualmente en tu app:</p>
            <div class="secret-code">' . $config['totp_secret'] . '</div>
            
            ' . $backupCodesHtml . '
            
            <div style="margin-top: 30px; padding: 15px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">
                <strong>⚠️ Importante:</strong> Imprime o guarda esta información de forma segura. Los códigos de respaldo te permitirán acceder si pierdes tu teléfono.
            </div>
        </div>
        <div class="footer">
            <p>Este es un mensaje automático del sistema Mimir.<br>
            Si no solicitaste esta configuración, contacta al administrador.</p>
        </div>
    </div>
</body>
</html>';
                    
                    // Create plain text version
                    $textMessage = "Hola {$user['full_name']},\n\n";
                    $textMessage .= "El administrador ha configurado la autenticación de dos factores para tu cuenta.\n\n";
                    $textMessage .= "CODIGO SECRETO: {$config['totp_secret']}\n\n";
                    $textMessage .= "Instrucciones:\n";
                    $textMessage .= "1. Descarga una app de autenticación (Google Authenticator, Authy, etc.)\n";
                    $textMessage .= "2. Abre la app y añade una nueva cuenta\n";
                    $textMessage .= "3. Introduce el código secreto de arriba\n";
                    $textMessage .= "4. Usa los códigos de 6 dígitos al iniciar sesión\n\n";
                    
                    if (!empty($config['backup_codes'])) {
                        $textMessage .= "CODIGOS DE RESPALDO (guárdalos de forma segura):\n";
                        foreach ($config['backup_codes'] as $code) {
                            $textMessage .= "- $code\n";
                        }
                        $textMessage .= "\n";
                    }
                    
                    $textMessage .= "Saludos,\nEquipo Mimir";
                    
                    // Create multipart email
                    $boundary = md5(time());
                    $headers = "From: Mimir <noreply@" . parse_url(BASE_URL, PHP_URL_HOST) . ">\r\n";
                    $headers .= "Reply-To: noreply@" . parse_url(BASE_URL, PHP_URL_HOST) . "\r\n";
                    $headers .= "MIME-Version: 1.0\r\n";
                    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
                    $headers .= "X-Mailer: PHP/" . phpversion();
                    
                    $body = "--{$boundary}\r\n";
                    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
                    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
                    $body .= $textMessage . "\r\n\r\n";
                    
                    $body .= "--{$boundary}\r\n";
                    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
                    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
                    $body .= $htmlMessage . "\r\n\r\n";
                    
                    $body .= "--{$boundary}--";
                    
                    if (mail($to, $subject, $body, $headers)) {
                        $logger->log($adminUser['id'], '2fa_email_sent', 'user', $userId, "Código QR enviado por email a {$user['username']}");
                        $success = 'Email enviado correctamente a ' . htmlspecialchars($user['email']);
                    } else {
                        $error = 'Error al enviar el email';
                    }
                } else {
                    $error = '2FA TOTP no configurado para este usuario';
                }
            } else {
                $error = 'Usuario sin email configurado';
            }
        }
    }
}

// Get all users with 2FA info
$db = Database::getInstance()->getConnection();
$stmt = $db->query("
    SELECT 
        u.id,
        u.username,
        u.email,
        u.full_name,
        u.require_2fa,
        u.is_active,
        uf.method,
        uf.is_enabled as has_2fa,
        uf.created_at as 2fa_since,
        (SELECT COUNT(*) FROM 2fa_attempts WHERE user_id = u.id) as total_attempts,
        (SELECT COUNT(*) FROM 2fa_attempts WHERE user_id = u.id AND success = 1) as successful_attempts,
        (SELECT COUNT(*) FROM 2fa_attempts WHERE user_id = u.id AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)) as recent_failures
    FROM users u
    LEFT JOIN user_2fa uf ON u.id = uf.user_id
    ORDER BY u.username
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$stats = [
    'total_users' => count($users),
    'with_2fa' => 0,
    'totp' => 0,
    'duo' => 0,
    'required' => 0,
    'locked' => 0
];

foreach ($users as $user) {
    if ($user['has_2fa']) $stats['with_2fa']++;
    if ($user['method'] === 'totp') $stats['totp']++;
    if ($user['method'] === 'duo') $stats['duo']++;
    if ($user['require_2fa']) $stats['required']++;
    if ($user['recent_failures'] >= 5) $stats['locked']++;
}

renderPageStart('Gestión 2FA', 'users', true);
renderHeader('Gestión de Autenticación 2FA', $adminUser);
?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}
.stat-card {
    background: var(--bg-secondary);
    padding: 1.5rem;
    border-radius: 0.5rem;
    text-align: center;
}
.stat-value {
    font-size: 2rem;
    font-weight: bold;
    color: var(--primary);
}
.stat-label {
    color: var(--text-muted);
    margin-top: 0.5rem;
}
.user-status {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.875rem;
}
.status-enabled { background: var(--success); color: white; }
.status-disabled { background: var(--danger); color: white; }
.status-required { background: var(--warning); color: var(--text-primary); }
.status-locked { background: #ff4444; color: white; }
</style>

<div class="content">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-lock"></i> Gestión de Autenticación 2FA</h1>
            <p style="color: var(--text-muted);">Administra la configuración 2FA de todos los usuarios</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['admin_setup_2fa']) && isset($_GET['show_qr'])): ?>
        <?php $setup = $_SESSION['admin_setup_2fa']; ?>
        <div class="card" style="border: 2px solid var(--success); margin-bottom: 2rem;">
            <div class="card-header" style="background: var(--success); color: white;">
                <h2 class="card-title" style="margin: 0;">✅ 2FA Configurado para <?php echo htmlspecialchars($setup['username']); ?></h2>
            </div>
            <div class="card-body">
                <div style="display: grid; grid-template-columns: auto 1fr; gap: 2rem; align-items: start;">
                    <div style="text-align: center;">
                        <img src="<?php echo $setup['qr_code']; ?>" alt="QR Code" style="max-width: 250px; border: 1px solid var(--border-color); border-radius: 0.5rem;">
                        <p style="margin-top: 1rem; color: var(--text-muted);">Escanea con app autenticadora</p>
                    </div>
                    <div>
                        <h3>Código Secreto</h3>
                        <div style="background: var(--bg-secondary); padding: 1rem; border-radius: 0.5rem; font-family: monospace; font-size: 1.2rem; margin-bottom: 1rem;">
                            <?php echo $setup['secret']; ?>
                        </div>
                        
                        <h3>Códigos de Respaldo (Guárdalos de forma segura)</h3>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; background: var(--bg-secondary); padding: 1rem; border-radius: 0.5rem;">
                            <?php foreach ($setup['backup_codes'] as $code): ?>
                                <div style="font-family: monospace; padding: 0.5rem; background: var(--bg-primary); border-radius: 0.25rem;">
                                    <?php echo htmlspecialchars($code); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
                            <button onclick="window.print()" class="btn btn-outline">
                                🖨️ Imprimir
                            </button>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="send_qr_email">
                                <input type="hidden" name="user_id" value="<?php echo $setup['user_id']; ?>">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-envelope"></i> Enviar por Email
                                </button>
                            </form>
                            <a href="<?php echo BASE_URL; ?>/admin/2fa_management.php" class="btn btn-success">
                                <i class="fas fa-check"></i> Continuar
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['admin_setup_2fa']); ?>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['with_2fa']; ?>/<?php echo $stats['total_users']; ?></div>
            <div class="stat-label">Con 2FA Activo</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['totp']; ?></div>
            <div class="stat-label">Usando TOTP</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['duo']; ?></div>
            <div class="stat-label">Usando Duo</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['required']; ?></div>
            <div class="stat-label">2FA Obligatorio</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: var(--danger);"><?php echo $stats['locked']; ?></div>
            <div class="stat-label">Bloqueados</div>
        </div>
    </div>

    <!-- Users Table -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Usuarios y Estado 2FA</h2>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th style="width: 150px;">Estado 2FA</th>
                        <th style="width: 120px;">Método</th>
                        <th style="width: 120px;">Obligatorio</th>
                        <th style="width: 150px;">Intentos</th>
                        <th style="width: 250px; text-align: right;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                <?php if ($user['full_name']): ?>
                                    <br><small style="color: var(--text-muted);"><?php echo htmlspecialchars($user['full_name']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['has_2fa']): ?>
                                    <span class="user-status status-enabled"><i class="fas fa-check"></i> Activo</span>
                                    <?php if ($user['2fa_since']): ?>
                                        <br><small style="color: var(--text-muted);">
                                            Desde <?php echo date('d/m/Y', strtotime($user['2fa_since'])); ?>
                                        </small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="user-status status-disabled">✗ Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['method'] === 'totp'): ?>
                                    📱 TOTP
                                <?php elseif ($user['method'] === 'duo'): ?>
                                    <i class="fas fa-shield-alt"></i> Duo
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['require_2fa']): ?>
                                    <span class="user-status status-required">⚠ Sí</span>
                                <?php else: ?>
                                    No
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['total_attempts'] > 0): ?>
                                    <i class="fas fa-check"></i> <?php echo $user['successful_attempts']; ?> / 
                                    ✗ <?php echo $user['total_attempts'] - $user['successful_attempts']; ?>
                                    <?php if ($user['recent_failures'] >= 5): ?>
                                        <br><span class="user-status status-locked"><i class="fas fa-lock"></i> Bloqueado</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: flex; gap: 0.5rem; justify-content: flex-end; flex-wrap: wrap;">
                                    <?php if (!$user['require_2fa']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="force_enable">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-warning" title="Forzar 2FA">
                                                ⚠ Forzar
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="force_disable">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline" title="Hacer opcional">
                                                ○ Opcional
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($user['has_2fa']): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('¿Seguro? El usuario deberá volver a configurar 2FA.')">
                                            <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="reset_2fa">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Resetear 2FA">
                                                🔄 Reset
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <?php if (!$user['has_2fa']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="setup_totp">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-success" title="Configurar TOTP">
                                                📱 Setup TOTP
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($user['has_2fa'] && $user['method'] === 'totp' && !empty($user['email'])): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="send_qr_email">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-info" title="Enviar QR por Email">
                                                <i class="fas fa-envelope"></i> Enviar QR
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($user['recent_failures'] >= 5): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="clear_lockout">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-primary" title="Desbloquear">
                                                🔓 Desbloquear
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Configuration Info -->
    <div class="card" style="margin-top: 2rem;">
        <div class="card-header">
            <h2 class="card-title">Configuración del Sistema</h2>
        </div>
        <div class="card-body">
            <?php
            $config2fa = $twoFactor->getConfig();
            $duoConfig = $duoAuth->getConfig();
            ?>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                <div>
                    <h3>TOTP (Aplicaciones Autenticadoras)</h3>
                    <ul style="list-style: none; padding: 0;">
                        <li>📛 <strong>Emisor:</strong> <?php echo htmlspecialchars($config2fa['totp_issuer']); ?></li>
                        <li><i class="fas fa-clock"></i> <strong>Período de gracia:</strong> <?php echo $config2fa['grace_period_hours']; ?> horas</li>
                        <li>🖥️ <strong>Confianza dispositivo:</strong> <?php echo $config2fa['device_trust_days']; ?> días</li>
                        <li>🚫 <strong>Máx intentos:</strong> <?php echo $config2fa['max_attempts']; ?></li>
                        <li><i class="fas fa-lock"></i> <strong>Bloqueo:</strong> <?php echo $config2fa['lockout_minutes']; ?> minutos</li>
                    </ul>
                </div>
                <div>
                    <h3>Duo Security</h3>
                    <?php if ($duoConfig['is_configured']): ?>
                        <ul style="list-style: none; padding: 0;">
                            <li>✅ <strong>Estado:</strong> Configurado</li>
                            <li>🌐 <strong>API Host:</strong> <?php echo htmlspecialchars($duoConfig['api_hostname']); ?></li>
                            <li><i class="fas fa-link"></i> <strong>Redirect URI:</strong> <small><?php echo htmlspecialchars($duoConfig['redirect_uri']); ?></small></li>
                            <li>
                                <?php if ($duoConfig['is_healthy']): ?>
                                    ✅ <strong>Health Check:</strong> <span style="color: var(--success);">OK</span>
                                <?php else: ?>
                                    ❌ <strong>Health Check:</strong> <span style="color: var(--danger);">Failed</span>
                                <?php endif; ?>
                            </li>
                        </ul>
                    <?php else: ?>
                        <p style="color: var(--text-muted);">
                            ⚠️ Duo no está configurado. Ve a <a href="<?php echo BASE_URL; ?>/admin/config.php">Configuración</a> para añadir credenciales.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                <a href="<?php echo BASE_URL; ?>/admin/config.php" class="btn btn-outline"><i class="fas fa-cog"></i> Editar Configuración</a>
            </div>
        </div>
    </div>
</div>

<?php renderPageEnd(); ?>
