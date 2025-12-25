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

// Prevent search engines from indexing share pages
header('X-Robots-Tag: noindex, nofollow');

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

// Get token from URL. Support path-style URLs like /s/<token>/<filename>
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); // usually '/s'
$rel = $requestPath;
if ($scriptDir !== '' && strpos($requestPath, $scriptDir) === 0) {
    $rel = substr($requestPath, strlen($scriptDir));
}
$rel = trim($rel, '/');
$parts = $rel === '' ? [] : explode('/', $rel);
$tokenRaw = $_GET['token'] ?? ($parts[0] ?? basename($_SERVER['REQUEST_URI']));
$token = $security->sanitizeString($tokenRaw);

// Validate token format (should be alphanumeric, 32 or 64 chars)
if (!preg_match('/^[a-f0-9]{32}$|^[a-f0-9]{64}$/', $token)) {
    $error = t('error_invalid_token');
    $token = '';
}

// Check rate limiting - max 20 download attempts per IP per hour
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if ($token && !$security->checkIPRateLimit($clientIP, 'share_download', 20, 60)) {
    $error = t('error_too_many_attempts_share');
    $token = '';
}

// If raw image requested (or requested via direct path), serve inline and exit (used for embedding/preview)
if (!empty($token) && ((isset($_GET['raw']) && $_GET['raw']) || (isset($parts[1]) && $parts[1] !== ''))) {
    // Allow cross-origin embedding for images
    header('Access-Control-Allow-Origin: *');
    $shareClass->streamInline($token);
    exit;
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
                $downloadLogId = $forensicLogger->logDownload($share['file_id'], $shareId, null);
            } else {
                $downloadLogId = null;
            }
        }
        $dlRes = $shareClass->download($token, $password, $downloadLogId);
        if (is_array($dlRes) && !empty($dlRes['error'])) {
            // Preserve error and allow page render (so user sees message)
            $error = $dlRes['error'];
            $needsPassword = false;
            $share = $shareClass->getByToken($token);
        } else {
            // download either handled (streaming + exit) or returned ok; ensure exit
            exit;
        }
    } else {
        $error = is_array($validation) ? ($validation['error'] ?? 'Error de acceso') : 'Error de acceso';
        $needsPassword = true;
        $share = $shareClass->getByToken($token);
    }
    } else {
        // Check if share exists and needs password
    $validation = $shareClass->validateAccess($token);
    
    if (is_array($validation) && !empty($validation['valid'])) {
        // No password needed — show download page and require user to click the button
        $share = $shareClass->getByToken($token);
        if ($share) {
            $shareId = $share['share_id'] ?? $share['id'] ?? null;
            $fileSize = $share['file_size'] ?? null;
            if ($shareId !== null) {
                // Log a view event; actual download will be logged when user clicks
                $forensicLogger->logShareAccess($shareId, 'view', $fileSize);
            }
        }
        // Ensure we render the page with a download button (do not auto-stream)
        $needsPassword = false;
        // $share is set and page will show download button below
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
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo sprintf(t('download_file_title'), htmlspecialchars($siteName)); ?></title>
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
                <p><?php echo t('download_shared_heading'); ?></p>
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
                    <p style="margin-top: 1rem;"><?php echo t('protected_file_notice'); ?></p>
                </div>
                
                <form method="POST" class="login-form">
                    <div class="form-group">
                        <label><?php echo t('label_password'); ?></label>
                        <input type="password" name="password" class="form-control" required autofocus>
                    </div>
                    
                        <div style="margin-top: 1rem;">
                            <button type="submit" class="btn btn-primary" style="background: <?php echo htmlspecialchars($buttonBg); ?>; color: <?php echo htmlspecialchars($buttonText); ?>; border: none; padding: 0.7rem 1.2rem; font-size: 1rem; border-radius: 6px; display: inline-block; cursor: pointer;"><?php echo t('download_button'); ?></button>
                        </div>
                </form>
                <?php elseif ($share && !$needsPassword): ?>
                    <?php
                        // Detect gallery-style shares (images with no expiry and unlimited downloads)
                        $isGalleryImage = false;
                        if (!empty($share['mime_type']) && strpos($share['mime_type'], 'image/') === 0) {
                            $noExpiry = empty($share['expires_at']);
                            $unlimited = empty($share['max_downloads']);
                            if ($noExpiry && $unlimited) $isGalleryImage = true;
                        }
                    ?>
                    <?php if ($isGalleryImage): ?>
                        <div class="mb-3" style="text-align: center;">
                            <div style="margin-bottom:1rem;">
                                <img src="<?php echo htmlspecialchars(BASE_URL . '/s/' . $token); ?>?raw=1" alt="<?php echo htmlspecialchars(t('gallery_public_image')); ?>" style="max-width:100%; max-height:60vh; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.12);">
                            </div>
                            <h2 style="margin-bottom: 0.5rem;"><?php echo htmlspecialchars(t('gallery_public_image')); ?></h2>
                            <p style="color: var(--text-muted);"><?php echo number_format($share['file_size'] / 1024 / 1024, 2); ?> MB</p>
                            <p style="margin-top: 1rem;"><?php echo htmlspecialchars(t('embed_in_forum') ?? 'Código para incrustar'); ?></p>
                            <div style="margin-top:0.5rem; text-align:left; max-width:720px; margin-left:auto; margin-right:auto;">
                                <label style="font-weight:600;">HTML</label>
                                <textarea class="form-control" rows="2" readonly>&lt;img src="<?php echo htmlspecialchars(BASE_URL . '/s/' . $token); ?>?raw=1" alt="<?php echo htmlspecialchars(t('gallery_public_image')); ?>"&gt;</textarea>
                                <label style="font-weight:600; margin-top:0.5rem;">BBCode</label>
                                <textarea class="form-control" rows="2" readonly>[img]<?php echo htmlspecialchars(BASE_URL . '/s/' . $token); ?>?raw=1[/img]</textarea>
                                <label style="font-weight:600; margin-top:0.5rem;">Enlace directo</label>
                                <input type="text" class="form-control" readonly value="<?php echo htmlspecialchars(BASE_URL . '/s/' . $token); ?>">
                            </div>
                        </div>
                    <?php else: ?>
                    <div class="mb-3" style="text-align: center;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">📄</div>
                        <h2 style="margin-bottom: 0.5rem;"><?php echo htmlspecialchars($share['original_name']); ?></h2>
                        <p style="color: var(--text-muted);">
                            <?php echo number_format($share['file_size'] / 1024 / 1024, 2); ?> MB
                        </p>
                        <p style="margin-top: 1rem;"><?php echo t('press_button_to_download'); ?></p>
                    </div>

                    <form method="POST" class="login-form">
                        <div style="margin-top: 1rem;">
                            <button type="submit" class="btn btn-primary" style="background: <?php echo htmlspecialchars($buttonBg); ?>; color: <?php echo htmlspecialchars($buttonText); ?>; border: none; padding: 0.7rem 1.2rem; font-size: 1rem; border-radius: 6px; display: inline-block; cursor: pointer;">⬇️ Descargar</button>
                        </div>
                    </form>
        <?php endif; ?>

                <?php elseif (isset($error) && $error): ?>
                    <div style="text-align: center; padding: 2rem;">
                    <div style="font-size: 4rem; margin-bottom: 1rem;">❌</div>
                    <p style="color: var(--text-muted);"><?php echo t('cannot_access_file'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
