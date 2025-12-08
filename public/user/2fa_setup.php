<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/TwoFactor.php';
require_once __DIR__ . '/../../classes/DuoAuth.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();
$twoFactor = new TwoFactor();
$duoAuth = new DuoAuth();
$logger = new Logger();

$error = '';
$success = '';
$step = $_GET['step'] ?? 'choose';

// Get current 2FA configuration
$currentConfig = $twoFactor->getUserConfig($user['id']);
$isEnabled = $currentConfig && $currentConfig['is_enabled'];
$currentMethod = $currentConfig['method'] ?? null;
$isRequired = $twoFactor->isRequired($user['id']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'disable') {
            if ($isRequired) {
                $error = 'No puedes desactivar 2FA porque es obligatorio para tu cuenta';
            } else {
                if ($twoFactor->disable($user['id'])) {
                    $logger->log($user['id'], '2fa_disabled', 'user', $user['id'], "2FA desactivado");
                    $success = '2FA desactivado correctamente';
                    $isEnabled = false;
                    $currentConfig = null;
                } else {
                    $error = 'Error al desactivar 2FA';
                }
            }
        } elseif ($action === 'setup_totp_generate') {
            // Generate new secret and show QR
            $secret = $twoFactor->generateSecret();
            $_SESSION['2fa_temp_secret'] = $secret;
            $step = 'setup_totp_verify';
        } elseif ($action === 'setup_totp_verify') {
            // Verify TOTP code and enable
            $secret = $_SESSION['2fa_temp_secret'] ?? '';
            $code = $_POST['code'] ?? '';
            
            if (!$secret) {
                $error = 'Sesión expirada. Por favor, vuelve a empezar.';
                $step = 'choose';
            } elseif (empty($code)) {
                $error = 'Debes introducir el código';
                $step = 'setup_totp_verify';
            } elseif (!$twoFactor->verifyTOTP($secret, $code)) {
                $error = 'Código incorrecto. Verifica la hora de tu dispositivo.';
                $step = 'setup_totp_verify';
            } else {
                // Generate backup codes
                $backupCodes = $twoFactor->generateBackupCodes(10);
                
                // Enable 2FA
                if ($twoFactor->enable($user['id'], 'totp', [
                    'secret' => $secret,
                    'backup_codes' => $backupCodes
                ])) {
                    $_SESSION['2fa_backup_codes'] = $backupCodes;
                    unset($_SESSION['2fa_temp_secret']);
                    $logger->log($user['id'], '2fa_enabled', 'user', $user['id'], "2FA TOTP activado");
                    $step = 'setup_totp_complete';
                    $isEnabled = true;
                } else {
                    $error = 'Error al activar 2FA';
                    $step = 'setup_totp_verify';
                }
            }
        } elseif ($action === 'setup_duo') {
            $duoUsername = trim($_POST['duo_username'] ?? $user['username']);
            
            if (empty($duoUsername)) {
                $error = 'Debes proporcionar un nombre de usuario de Duo';
            } elseif (!$duoAuth->isConfigured()) {
                $error = 'Duo no está configurado en el sistema';
            } else {
                if ($twoFactor->enable($user['id'], 'duo', [
                    'duo_username' => $duoUsername
                ])) {
                    $logger->log($user['id'], '2fa_enabled', 'user', $user['id'], "2FA Duo activado");
                    $success = 'Duo 2FA configurado correctamente';
                    $isEnabled = true;
                    $currentConfig = $twoFactor->getUserConfig($user['id']);
                } else {
                    $error = 'Error al configurar Duo';
                }
            }
        }
    }
}

renderPageStart('Autenticación 2FA', 'profile', $user['role'] === 'admin');
renderHeader('Autenticación 2FA', $user);
?>

<style>
.method-card {
    border: 2px solid var(--border-color);
    border-radius: 0.5rem;
    padding: 1.5rem;
    margin-bottom: 1rem;
    cursor: pointer;
    transition: all 0.2s;
}
.method-card:hover {
    border-color: var(--primary);
    background: var(--bg-secondary);
}
.method-card.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.qr-container {
    text-align: center;
    padding: 2rem;
    background: white;
    border-radius: 0.5rem;
    margin: 1rem 0;
}
.backup-codes {
    background: var(--bg-secondary);
    padding: 1.5rem;
    border-radius: 0.5rem;
    margin: 1rem 0;
}
.backup-codes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 0.5rem;
    margin-top: 1rem;
}
.backup-code {
    background: var(--bg-primary);
    padding: 0.75rem;
    text-align: center;
    font-family: monospace;
    font-size: 1.1rem;
    border: 1px solid var(--border-color);
    border-radius: 0.25rem;
}
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 1rem;
    font-size: 0.875rem;
    font-weight: 600;
}
.status-enabled {
    background: var(--success);
    color: white;
}
.status-disabled {
    background: var(--danger);
    color: white;
}
.status-required {
    background: var(--warning);
    color: var(--text-primary);
}
</style>

<div class="content">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-lock"></i> Autenticación de Dos Factores (2FA)</h1>
            <p style="color: var(--text-muted);">Protege tu cuenta con una capa adicional de seguridad</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Current Status -->
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="card-title">Estado Actual</h2>
        </div>
        <div class="card-body">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div>
                    <?php if ($isEnabled): ?>
                        <span class="status-badge status-enabled"><i class="fas fa-check"></i> Activado</span>
                        <p style="margin: 0.5rem 0 0 0; color: var(--text-muted);">
                            Método: <strong><?php echo $currentMethod === 'totp' ? 'Aplicación Autenticadora (TOTP)' : 'Duo Security'; ?></strong>
                        </p>
                    <?php else: ?>
                        <span class="status-badge status-disabled">✗ Desactivado</span>
                    <?php endif; ?>
                    
                    <?php if ($isRequired): ?>
                        <br><span class="status-badge status-required" style="margin-top: 0.5rem;">⚠ Obligatorio</span>
                    <?php endif; ?>
                </div>
                
                <?php if ($isEnabled && !$isRequired): ?>
                    <form method="POST" style="margin-left: auto;" onsubmit="return confirm('¿Seguro que quieres desactivar 2FA? Tu cuenta será menos segura.')">
                        <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="disable">
                        <button type="submit" class="btn btn-danger">Desactivar 2FA</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($step === 'choose' && !$isEnabled): ?>
        <!-- Choose Method -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Configurar 2FA</h2>
            </div>
            <div class="card-body">
                <p style="margin-bottom: 1.5rem;">Elige un método de autenticación:</p>
                
                <div class="method-card" onclick="window.location.href='?step=setup_totp'">
                    <h3 style="margin: 0 0 0.5rem 0;">📱 Aplicación Autenticadora (TOTP)</h3>
                    <p style="margin: 0; color: var(--text-muted);">
                        Usa apps como Google Authenticator, Microsoft Authenticator, Authy, etc.
                        Genera códigos de 6 dígitos cada 30 segundos.
                    </p>
                    <p style="margin: 0.5rem 0 0 0;">
                        <strong><i class="fas fa-check"></i> Recomendado</strong> - Funciona sin conexión a internet
                    </p>
                </div>
                
                <div class="method-card <?php echo !$duoAuth->isConfigured() ? 'disabled' : ''; ?>" 
                     <?php if ($duoAuth->isConfigured()): ?>onclick="window.location.href='?step=setup_duo'"<?php endif; ?>>
                    <h3 style="margin: 0 0 0.5rem 0;"><i class="fas fa-shield-alt"></i> Duo Security</h3>
                    <p style="margin: 0; color: var(--text-muted);">
                        Autenticación push a tu móvil, SMS, o llamada telefónica.
                        Requiere configuración adicional del administrador.
                    </p>
                    <?php if (!$duoAuth->isConfigured()): ?>
                        <p style="margin: 0.5rem 0 0 0; color: var(--danger);">
                            <strong>✗ No disponible</strong> - Contacta con tu administrador
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    
    <?php elseif ($step === 'setup_totp' && !$isEnabled): ?>
        <!-- TOTP Setup - Generate -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Configurar Aplicación Autenticadora</h2>
            </div>
            <div class="card-body">
                <div style="max-width: 600px; margin: 0 auto;">
                    <h3>Paso 1: Instala una aplicación</h3>
                    <p>Si aún no tienes una aplicación autenticadora, instala una de estas:</p>
                    <ul>
                        <li><strong>Google Authenticator</strong> - Android / iOS</li>
                        <li><strong>Microsoft Authenticator</strong> - Android / iOS</li>
                        <li><strong>Authy</strong> - Android / iOS / Desktop</li>
                        <li><strong>FreeOTP</strong> - Android / iOS (Open Source)</li>
                    </ul>
                    
                    <h3 style="margin-top: 2rem;">Paso 2: Genera tu código QR</h3>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="setup_totp_generate">
                        <button type="submit" class="btn btn-primary btn-lg">Generar Código QR</button>
                        <a href="?step=choose" class="btn btn-outline">Volver</a>
                    </form>
                </div>
            </div>
        </div>
    
    <?php elseif ($step === 'setup_totp_verify'): ?>
        <!-- TOTP Setup - Verify -->
        <?php
        $secret = $_SESSION['2fa_temp_secret'] ?? '';
        if ($secret):
            $qrCode = $twoFactor->generateQRCode($user['username'], $secret);
        ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Escanea el Código QR</h2>
            </div>
            <div class="card-body">
                <div style="max-width: 600px; margin: 0 auto;">
                    <h3>Paso 3: Escanea este código con tu aplicación</h3>
                    <div class="qr-container">
                        <img src="<?php echo $qrCode; ?>" alt="QR Code" style="max-width: 300px;">
                    </div>
                    
                    <div style="background: var(--bg-secondary); padding: 1rem; border-radius: 0.5rem; margin: 1rem 0;">
                        <p style="margin: 0; text-align: center;">
                            <strong>¿No puedes escanear?</strong> Introduce manualmente:<br>
                            <code style="font-size: 1.1rem; background: var(--bg-primary); padding: 0.5rem; display: inline-block; margin-top: 0.5rem;">
                                <?php echo $secret; ?>
                            </code>
                        </p>
                    </div>
                    
                    <h3 style="margin-top: 2rem;">Paso 4: Verifica el código</h3>
                    <p>Introduce el código de 6 dígitos que muestra tu aplicación:</p>
                    
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="setup_totp_verify">
                        <div class="form-group">
                            <input type="text" name="code" class="form-control" 
                                   placeholder="000000" maxlength="6" pattern="[0-9]{6}"
                                   required autofocus style="text-align: center; font-size: 1.5rem; letter-spacing: 0.5rem;">
                        </div>
                        <div style="display: flex; gap: 0.75rem;">
                            <button type="submit" class="btn btn-primary btn-lg">Verificar y Activar</button>
                            <a href="?step=choose" class="btn btn-outline">Cancelar</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php else: ?>
            <div class="alert alert-danger">Sesión expirada. <a href="?step=setup_totp">Vuelve a empezar</a></div>
        <?php endif; ?>
    
    <?php elseif ($step === 'setup_totp_complete'): ?>
        <!-- TOTP Setup - Complete with Backup Codes -->
        <?php
        $backupCodes = $_SESSION['2fa_backup_codes'] ?? [];
        if (!empty($backupCodes)):
        ?>
        <div class="card">
            <div class="card-header" style="background: var(--success); color: white;">
                <h2 class="card-title" style="margin: 0;"><i class="fas fa-check"></i> 2FA Activado Correctamente</h2>
            </div>
            <div class="card-body">
                <div style="max-width: 700px; margin: 0 auto;">
                    <div class="alert alert-warning">
                        <strong>⚠ MUY IMPORTANTE:</strong> Guarda estos códigos de respaldo en un lugar seguro.
                        Si pierdes acceso a tu aplicación autenticadora, estos códigos son la única forma de recuperar tu cuenta.
                    </div>
                    
                    <div class="backup-codes">
                        <h3>Códigos de Respaldo</h3>
                        <p style="color: var(--text-muted);">Cada código solo se puede usar una vez:</p>
                        <div class="backup-codes-grid">
                            <?php foreach ($backupCodes as $code): ?>
                                <div class="backup-code"><?php echo $code; ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                        <button onclick="window.print()" class="btn btn-outline">🖨️ Imprimir</button>
                        <button onclick="copyBackupCodes()" class="btn btn-outline"><i class="fas fa-clipboard"></i> Copiar</button>
                        <a href="<?php echo BASE_URL; ?>/user/profile.php" class="btn btn-primary">Continuar</a>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        function copyBackupCodes() {
            const codes = <?php echo json_encode($backupCodes); ?>;
            const text = codes.join('\n');
            navigator.clipboard.writeText(text).then(() => {
                alert('Códigos copiados al portapapeles');
            });
        }
        </script>
        
        <?php
        unset($_SESSION['2fa_backup_codes']);
        endif;
        ?>
    
    <?php elseif ($step === 'setup_duo' && !$isEnabled): ?>
        <!-- Duo Setup -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Configurar Duo Security</h2>
            </div>
            <div class="card-body">
                <div style="max-width: 600px; margin: 0 auto;">
                    <p>Duo Security te enviará notificaciones push a tu dispositivo móvil para aprobar inicios de sesión.</p>
                    
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="setup_duo">
                        
                        <div class="form-group">
                            <label>Nombre de usuario de Duo</label>
                            <input type="text" name="duo_username" class="form-control" 
                                   value="<?php echo htmlspecialchars($user['username']); ?>" required>
                            <small style="color: var(--text-muted);">
                                Generalmente es tu nombre de usuario o email. Consulta con tu administrador.
                            </small>
                        </div>
                        
                        <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                            <button type="submit" class="btn btn-primary">Activar Duo 2FA</button>
                            <a href="?step=choose" class="btn btn-outline">Volver</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php renderPageEnd(); ?>
