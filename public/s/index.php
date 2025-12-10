<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/Share.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/ForensicLogger.php';
require_once __DIR__ . '/../../classes/Config.php';
require_once __DIR__ . '/../../classes/SecurityValidator.php';
require_once __DIR__ . '/../../classes/SecurityHeaders.php';

// Apply security headers
SecurityHeaders::applyAll();

$shareClass = new Share();
$logger = new Logger();
$forensicLogger = new ForensicLogger();
$security = SecurityValidator::getInstance();
$config = new Config();

// Site branding
$siteName = $config->get('site_name', 'Mimir');
$siteLogo = $config->get('site_logo', '');

// Normalize logo URL: prefer root-relative paths for portability
$siteLogoUrl = '';
if (!empty($siteLogo)) {
    if (preg_match('#^https?://#i', $siteLogo)) {
        $siteLogoUrl = $siteLogo;
    } elseif (strpos($siteLogo, '/') === 0) {
        // Already root-relative
        $siteLogoUrl = $siteLogo;
    } else {
        // Use root-relative path so it works regardless of BASE_URL
        $siteLogoUrl = '/' . ltrim($siteLogo, '/');
    }
}

// Branding colors (fallbacks)
$brandPrimary = $config->get('brand_primary_color', '#1e40af');
$brandSecondary = $config->get('brand_secondary_color', '#475569');
$brandAccent = $config->get('brand_accent_color', '#0ea5e9');

// Decide readable text color for buttons based on accent luminance
function hexToRgbArray($hex) {
    $hex = ltrim($hex, '#');
    return [
        'r' => hexdec(substr($hex, 0, 2)),
        'g' => hexdec(substr($hex, 2, 2)),
        'b' => hexdec(substr($hex, 4, 2)),
    ];
}

function readableTextColor($hex) {
    $rgb = hexToRgbArray($hex);
    $brightness = ($rgb['r'] * 299 + $rgb['g'] * 587 + $rgb['b'] * 114) / 1000;
    return ($brightness < 140) ? '#ffffff' : '#000000';
}

$buttonBg = $brandAccent ?: $brandPrimary;
$buttonText = readableTextColor($buttonBg);

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
    
    if (is_array($validation) && !empty($validation['valid'])) {
        // Get share info for forensic logging
        $share = $shareClass->getByToken($token);
        if ($share) {
            $shareId = $share['share_id'] ?? $share['id'] ?? null;
            $fileSize = $share['file_size'] ?? null;
            if ($shareId !== null) {
                $forensicLogger->logShareAccess($shareId, 'download', $fileSize);
                $forensicLogger->logDownload($share['file_id'], $shareId, null);
            }
        }
        $shareClass->download($token, $password);
        exit;
    } else {
        $error = is_array($validation) ? ($validation['error'] ?? 'Error de acceso') : 'Error de acceso';
        $needsPassword = true;
        $share = $shareClass->getByToken($token);
    }
    } else {
        // Check if share exists and needs password
    $validation = $shareClass->validateAccess($token);
    
    if (is_array($validation) && !empty($validation['valid'])) {
        // No password needed, proceed to download
        $share = $shareClass->getByToken($token);
        if ($share) {
            $shareId = $share['share_id'] ?? $share['id'] ?? null;
            $fileSize = $share['file_size'] ?? null;
            if ($shareId !== null) {
                $forensicLogger->logShareAccess($shareId, 'download', $fileSize);
                $forensicLogger->logDownload($share['file_id'], $shareId, null);
            }
        }
        $shareClass->download($token);
        exit;
    } elseif (is_array($validation) && ($validation['error'] ?? '') === 'Password required') {
        $needsPassword = true;
        $share = $shareClass->getByToken($token);
        // Log view attempt
        if ($share) {
            $shareId = $share['share_id'] ?? $share['id'] ?? null;
            $fileSize = $share['file_size'] ?? null;
            if ($shareId !== null) {
                $forensicLogger->logShareAccess($shareId, 'view', $fileSize);
            }
        }
    } else {
        $error = is_array($validation) ? ($validation['error'] ?? 'Error de acceso') : 'Error de acceso';
    }
}

// Render page
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Descargar Archivo - <?php echo htmlspecialchars($siteName); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-card" style="text-align: center;">
            <div class="login-header" style="text-align: center;">
                <?php if ($siteLogoUrl): ?>
                    <div class="logo" style="margin-bottom:0.5rem; display:inline-block;">
                        <img src="<?php echo htmlspecialchars($siteLogoUrl); ?>" alt="<?php echo htmlspecialchars($siteName); ?>" style="max-height:48px; max-width:220px; display:block;">
                    </div>
                <?php else: ?>
                    <h1><?php echo htmlspecialchars($siteName); ?></h1>
                <?php endif; ?>
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
                    
                        <div style="margin-top: 1rem;">
                            <button type="submit" class="btn btn-primary" style="background: <?php echo htmlspecialchars($buttonBg); ?>; color: <?php echo htmlspecialchars($buttonText); ?>; border: none; padding: 0.7rem 1.2rem; font-size: 1rem; border-radius: 6px; display: inline-block; cursor: pointer;">⬇️ Descargar</button>
                        </div>
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
