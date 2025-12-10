<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/Share.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/ForensicLogger.php';
require_once __DIR__ . '/../../classes/SecurityValidator.php';
require_once __DIR__ . '/../../classes/SecurityHeaders.php';

// Apply security headers
SecurityHeaders::applyAll();

$shareClass = new Share();
$logger = new Logger();
$forensicLogger = new ForensicLogger();
$security = SecurityValidator::getInstance();

// Get token from URL
$tokenRaw = $_GET['token'] ?? basename($_SERVER['REQUEST_URI']);
$token = $security->sanitizeString($tokenRaw);

// Validate token format (should be alphanumeric, 32 or 64 chars)
if (!preg_match('/^[a-f0-9]{32}$|^[a-f0-9]{64}$/', $token)) {
    $error = 'Token no válido';
    $token = '';
}

// Check rate limiting - max 20 download attempts per IP per hour
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if ($token && !$security->checkIPRateLimit($clientIP, 'share_download', 20, 60)) {
    $error = 'Demasiados intentos. Por favor, espera una hora antes de intentar de nuevo.';
    $token = '';
}

$needsPassword = false;
$share = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    
    $validation = $shareClass->validateAccess($token, $password);
    
    if ($validation['valid']) {
        // Get share info for forensic logging
        $share = $shareClass->getByToken($token);
        if ($share) {
            $forensicLogger->logShareAccess($share['share_id'], 'download', $share['file_size']);
            $forensicLogger->logDownload($share['file_id'], $share['share_id'], null);
        }
        $shareClass->download($token, $password);
        exit;
    } else {
        $error = $validation['error'];
        $needsPassword = true;
        $share = $shareClass->getByToken($token);
    }
} else {
    // Check if share exists and needs password
    $validation = $shareClass->validateAccess($token);
    
    if ($validation['valid']) {
        // No password needed, proceed to download
        $share = $shareClass->getByToken($token);
        if ($share) {
            $forensicLogger->logShareAccess($share['share_id'], 'download', $share['file_size']);
            $forensicLogger->logDownload($share['file_id'], $share['share_id'], null);
        }
        $shareClass->download($token);
        exit;
    } elseif ($validation['error'] === 'Password required') {
        $needsPassword = true;
        $share = $shareClass->getByToken($token);
        // Log view attempt
        if ($share) {
            $forensicLogger->logShareAccess($share['share_id'], 'view', $share['file_size']);
        }
    } else {
        $error = $validation['error'];
    }
}

// Render page
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Descargar Archivo - Mimir</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>📦 Mimir</h1>
                <p>Descargar Archivo Compartido</p>
            </div>
            
            <?php if (isset($error) && $error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($needsPassword && $share): ?>
                <div class="mb-3" style="text-align: center;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">🔒</div>
                    <h2 style="margin-bottom: 0.5rem;"><?php echo htmlspecialchars($share['original_name']); ?></h2>
                    <p style="color: var(--text-muted);">
                        <?php echo number_format($share['file_size'] / 1024 / 1024, 2); ?> MB
                    </p>
                    <p style="margin-top: 1rem;">Este archivo está protegido con contraseña</p>
                </div>
                
                <form method="POST" class="login-form">
                    <div class="form-group">
                        <label>Contraseña</label>
                        <input type="password" name="password" class="form-control" required autofocus>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">⬇️ Descargar</button>
                </form>
            <?php elseif (isset($error) && $error): ?>
                <div style="text-align: center; padding: 2rem;">
                    <div style="font-size: 4rem; margin-bottom: 1rem;">❌</div>
                    <p style="color: var(--text-muted);">No se puede acceder al archivo</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
