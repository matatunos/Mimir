<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/Config.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();
$configClass = new Config();
$logger = new Logger();
// Expose this Config instance globally so layout/header reuse the same cache
$GLOBALS['config_instance'] = $configClass;

// Inline SMTP test handler to avoid requiring a separate file on some deployments.
if ((isset($_REQUEST['action']) && $_REQUEST['action'] === 'test_smtp')) {
    header('Content-Type: application/json; charset=utf-8');
    ini_set('display_errors', 0);
    $cfg = $configClass;
    $host = $_REQUEST['smtp_host'] ?? $cfg->get('smtp_host');
    $port = $_REQUEST['smtp_port'] ?? $cfg->get('smtp_port');
    $encryption = $_REQUEST['smtp_encryption'] ?? $cfg->get('smtp_encryption');
    $username = $_REQUEST['smtp_username'] ?? $cfg->get('smtp_username');
    $password = $_REQUEST['smtp_password'] ?? null;
    $timeout = isset($_REQUEST['timeout']) ? (int)$_REQUEST['timeout'] : 6;
    $debug = [];
    if (empty($host) || empty($port)) {
        echo json_encode(['success' => false, 'message' => 'Faltan smtp_host o smtp_port.']);
        exit;
    }
    $port = (int)$port; $enc = strtolower(trim((string)$encryption));
    try {
        $debug[] = "Intentando conectar a $host:$port (timeout={$timeout}s)";
        $connected = false; $fp = null; $eh = '';
        if ($enc === 'ssl' || $port === 465) {
            $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
            if ($fp) { $connected = true; stream_set_timeout($fp, $timeout); $debug[] = 'Conexión SSL establecida'; $banner = rtrim(fgets($fp,512)); $debug[] = "Banner: $banner"; }
            else { $debug[] = "Error SSL connect: $errstr ($errno)"; }
        } else {
            $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
            if ($fp) {
                $connected = true; stream_set_timeout($fp, $timeout); $banner = rtrim(fgets($fp,512)); $debug[] = "Banner: $banner";
                fwrite($fp, "EHLO test.local\r\n");
                $start = microtime(true);
                while (!feof($fp)) { $line = fgets($fp,512); if ($line===false) break; $eh .= $line; if (preg_match('/^[0-9]{3} /',$line)) break; if ((microtime(true)-$start)>$timeout) break; }
                $debug[] = "EHLO response: " . trim($eh);
            } else { $debug[] = "Error TCP connect: $errstr ($errno)"; }
        }

        $starttls_ok = false;
        // Negotiate STARTTLS when requested (explicit TLS on 587/25)
        if (!empty($fp) && $connected && $enc === 'tls') {
            if (stripos($eh ?? '', 'STARTTLS') !== false) {
                $debug[] = 'STARTTLS soportado por el servidor; intentando STARTTLS...';
                fwrite($fp, "STARTTLS\r\n");
                $resp = rtrim(fgets($fp, 512));
                $debug[] = "STARTTLS response: $resp";
                if (strpos($resp, '220') === 0) {
                    // Enable crypto (requires openssl)
                    $okCrypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                    if ($okCrypto) {
                        $starttls_ok = true;
                        $debug[] = 'Negociación TLS completa (STARTTLS OK).';
                        // Re-EHLO after TLS
                        fwrite($fp, "EHLO test.local\r\n");
                        $eh = '';
                        $start = microtime(true);
                        while (!feof($fp)) {
                            $line = fgets($fp,512);
                            if ($line === false) break;
                            $eh .= $line;
                            if (preg_match('/^[0-9]{3} /', $line)) break;
                            if ((microtime(true)-$start) > $timeout) break;
                        }
                        $debug[] = "EHLO after STARTTLS: " . trim($eh);
                    } else {
                        $debug[] = 'Fallo en stream_socket_enable_crypto(); STARTTLS no completado.';
                    }
                } else {
                    $debug[] = 'STARTTLS no aceptado por el servidor.';
                }
            } else {
                $debug[] = 'STARTTLS no detectado en la respuesta EHLO.';
            }
        }

        $auth_ok = null;
        if (!empty($username) && !empty($password) && !empty($fp) && $connected) {
            // Only attempt auth if server advertises AUTH or we're already in SSL/TLS
            if (stripos($eh ?? '', 'AUTH') !== false || $enc === 'ssl' || $starttls_ok) {
                // Try AUTH PLAIN first (single step)
                $plain = base64_encode("\0" . $username . "\0" . $password);
                fwrite($fp, "AUTH PLAIN $plain\r\n");
                $resp = rtrim(fgets($fp, 512));
                $debug[] = "AUTH PLAIN response: $resp";
                if (strpos($resp, '235') === 0) {
                    $auth_ok = true;
                    $debug[] = 'Autenticación SMTP correcta (AUTH PLAIN).';
                } else {
                    // Try AUTH LOGIN fallback
                    fwrite($fp, "AUTH LOGIN\r\n");
                    $step = rtrim(fgets($fp,512));
                    $debug[] = "AUTH LOGIN start: $step";
                    if (strpos($step,'334')===0) {
                        fwrite($fp, base64_encode($username) . "\r\n");
                        $resp2 = rtrim(fgets($fp,512));
                        if (strpos($resp2,'334')===0) {
                            fwrite($fp, base64_encode($password) . "\r\n");
                            $final = rtrim(fgets($fp,512));
                            $debug[] = "AUTH LOGIN final: $final";
                            if (strpos($final,'235')===0) {
                                $auth_ok = true;
                                $debug[] = 'Autenticación SMTP correcta (AUTH LOGIN).';
                            } else {
                                $auth_ok = false;
                                $debug[] = "Autenticación SMTP fallida: $final";
                            }
                        } else {
                            $auth_ok = false;
                            $debug[] = "Respuesta inesperada durante AUTH LOGIN: $resp2";
                        }
                    } else {
                        $auth_ok = false;
                        $debug[] = "Servidor no respondió 334 para AUTH LOGIN: $step";
                    }
                }
            } else {
                $debug[] = 'Servidor no anuncia AUTH; se omite la prueba de autenticación.';
                $auth_ok = false;
            }
        }

        if (!empty($fp) && is_resource($fp)) { @fwrite($fp, "QUIT\r\n"); @fclose($fp); }
        $ok = $connected && ($enc !== 'tls' || $starttls_ok || $enc === 'ssl' || $port === 465);
        $message = $ok ? 'Conexión SMTP establecida' : 'No se pudo establecer conexión SMTP';
        if ($auth_ok === true) $message .= ' y autenticación correcta.'; if ($auth_ok === false) $message .= ' (autenticación fallida o no probada).';
        echo json_encode(['success' => (bool)$ok, 'message' => $message, 'debug' => $debug, 'starttls' => $starttls_ok, 'auth_ok' => $auth_ok]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Excepción: ' . $e->getMessage(), 'debug' => $debug]);
    }
    exit;
}

// Ensure a comprehensive set of configuration keys exist so the admin UI shows them
$defaults = [
    // General / branding
    'site_name' => ['value' => 'Mimir', 'type' => 'string'],
    'site_logo' => ['value' => '', 'type' => 'string'],
    'brand_primary_color' => ['value' => '#667eea', 'type' => 'string'],
    'brand_secondary_color' => ['value' => '#764ba2', 'type' => 'string'],
    'brand_accent_color' => ['value' => '#667eea', 'type' => 'string'],
    // Storage / upload
    'max_file_size' => ['value' => '536870912', 'type' => 'number'],
    'allowed_extensions' => ['value' => 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png,gif,zip,rar,7z', 'type' => 'string'],
    'default_storage_quota' => ['value' => '10737418240', 'type' => 'number'],
    // Physical path where user uploads are stored. Change if you mount a different disk.
    'storage_uploads_path' => ['value' => UPLOADS_PATH, 'type' => 'string'],
    // Sharing
    'default_max_share_days' => ['value' => '30', 'type' => 'number'],
    'default_max_downloads' => ['value' => '100', 'type' => 'number'],
    // Email / SMTP
    'enable_email' => ['value' => '0', 'type' => 'boolean'],
    'smtp_host' => ['value' => '', 'type' => 'string'],
    'smtp_port' => ['value' => '587', 'type' => 'number'],
    'smtp_username' => ['value' => '', 'type' => 'string'],
    'smtp_password' => ['value' => '', 'type' => 'string'],
    'smtp_encryption' => ['value' => 'tls', 'type' => 'string'],
    'email_from_address' => ['value' => '', 'type' => 'string'],
    'email_from_name' => ['value' => 'Mimir', 'type' => 'string'],
    'email_signature' => ['value' => '', 'type' => 'string'],
    // User-creation notification settings
    'notify_user_creation_enabled' => ['value' => '0', 'type' => 'boolean'],
    'notify_user_creation_emails' => ['value' => '', 'type' => 'string'],
    'notify_user_creation_to_admins' => ['value' => '1', 'type' => 'boolean'],
    'notify_user_creation_retry_attempts' => ['value' => '3', 'type' => 'number'],
    'notify_user_creation_retry_delay_seconds' => ['value' => '2', 'type' => 'number'],
    'notify_user_creation_use_background_worker' => ['value' => '0', 'type' => 'boolean'],
    // LDAP / AD
    'enable_ldap' => ['value' => '0', 'type' => 'boolean'],
    'ldap_host' => ['value' => '', 'type' => 'string'],
    'ldap_port' => ['value' => '389', 'type' => 'number'],
    'ldap_base_dn' => ['value' => '', 'type' => 'string'],
    'ldap_bind_dn' => ['value' => '', 'type' => 'string'],
    'ldap_bind_password' => ['value' => '', 'type' => 'string'],
    'ldap_search_filter' => ['value' => '(&(objectClass=inetOrgPerson)(uid=%s))', 'type' => 'string'],
    'ldap_username_attribute' => ['value' => 'uid', 'type' => 'string'],
    'ldap_email_attribute' => ['value' => 'mail', 'type' => 'string'],
    'ldap_display_name_attribute' => ['value' => 'cn', 'type' => 'string'],
    'ldap_required_group_dn' => ['value' => '', 'type' => 'string'],
    'ldap_admin_group_dn' => ['value' => '', 'type' => 'string'],
    // Active Directory
    'enable_ad' => ['value' => '0', 'type' => 'boolean'],
    'ad_host' => ['value' => '', 'type' => 'string'],
    'ad_port' => ['value' => '389', 'type' => 'number'],
    'ad_base_dn' => ['value' => '', 'type' => 'string'],
    'ad_bind_dn' => ['value' => '', 'type' => 'string'],
    'ad_bind_password' => ['value' => '', 'type' => 'string'],
    'ad_search_filter' => ['value' => '(&(objectClass=user)(sAMAccountName=%s))', 'type' => 'string'],
    'ad_username_attribute' => ['value' => 'sAMAccountName', 'type' => 'string'],
    'ad_email_attribute' => ['value' => 'mail', 'type' => 'string'],
    'ad_display_name_attribute' => ['value' => 'displayName', 'type' => 'string'],
    'ad_require_group' => ['value' => '', 'type' => 'string'],
    'ad_group_filter' => ['value' => '(&(objectClass=group)(member=%s))', 'type' => 'string'],
    'ad_required_group_dn' => ['value' => '', 'type' => 'string'],
    'ad_admin_group_dn' => ['value' => '', 'type' => 'string'],
    // 2FA / Duo
    'enable_duo' => ['value' => '0', 'type' => 'boolean'],
    'duo_client_id' => ['value' => '', 'type' => 'string'],
    'duo_client_secret' => ['value' => '', 'type' => 'string'],
    'duo_api_hostname' => ['value' => '', 'type' => 'string'],
    'duo_redirect_uri' => ['value' => '', 'type' => 'string'],
    '2fa_max_attempts' => ['value' => '3', 'type' => 'number'],
    '2fa_lockout_minutes' => ['value' => '15', 'type' => 'number'],
    '2fa_grace_period_hours' => ['value' => '24', 'type' => 'number'],
    '2fa_device_trust_days' => ['value' => '30', 'type' => 'number'],
    // Continue defaults
    // Misc
    'enable_registration' => ['value' => '0', 'type' => 'boolean'],
    'items_per_page' => ['value' => '25', 'type' => 'number']
];

// Ensure default configuration keys exist in the DB so they are visible in the UI
$db = null;
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    $db = null;
}

// Ensure default configuration keys exist in the DB so they are visible in the UI
if ($db) {
    foreach ($defaults as $k => $meta) {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM config WHERE config_key = ?");
            $stmt->execute([$k]);
            $exists = (int)$stmt->fetchColumn();
            if (!$exists) {
                $ins = $db->prepare("INSERT INTO config (config_key, config_value, config_type, is_system) VALUES (?, ?, ?, 0)");
                $ins->execute([$k, $meta['value'], $meta['type']]);
            }
        } catch (Exception $e) {
            // ignore insert errors to avoid breaking admin page
        }
    }
    // Reload configs after inserting defaults
    $configClass->reload();
    $configs = $configClass->getAllDetails();
}

// Description for global config protection toggle
$descs['notify_user_creation_enabled'] = 'Si está activado, el sistema enviará notificaciones cuando se cree un usuario vía invitación.';
$descs['storage_uploads_path'] = 'Ruta física absoluta en el servidor donde se almacenan los ficheros subidos por los usuarios. Útil para montar un disco diferente (ej.: /mnt/storage/uploads).';
$descs['notify_user_creation_emails'] = 'Lista separada por comas de direcciones de correo que recibirán notificaciones cuando se cree un usuario (por ejemplo: ops@example.com, infra@example.com). Déjalo vacío para ningún correo adicional.';
$descs['notify_user_creation_to_admins'] = 'Si está marcado, además se enviarán notificaciones a todos los usuarios con rol administrador que tengan email configurado.';
$descs['notify_user_creation_retry_attempts'] = 'Número máximo de reintentos para enviar notificaciones de creación de usuario antes de registrar un evento forense.';
$descs['notify_user_creation_retry_delay_seconds'] = 'Retraso inicial en segundos entre reintentos; se aplica backoff exponencial.';
$descs['notify_user_creation_use_background_worker'] = 'Si está activado, las notificaciones se encolarán y un worker en background las procesará (recomendado para alta latencia de SMTP).';
// Persist any default descriptions if DB connection available
$db = null;
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    $db = null;
}

if (!empty($descs) && is_array($descs) && $db) {
    foreach ($descs as $k => $d) {
        try {
            $stmt = $db->prepare("UPDATE config SET description = ? WHERE config_key = ? AND (description IS NULL OR description = '')");
            $stmt->execute([$d, $k]);
        } catch (Exception $e) { /* ignore */ }
    }
}

/**
 * Try to make the background of an image transparent.
 * Returns an array with ['path'=>..., 'extension'=>...] on success or original on failure.
 */
function makeBackgroundTransparentIfPossible($filePath, $extension) {
    // Prefer Imagick if available
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick($filePath);
            $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);

            // Sample corner pixel as background color
            $pixel = $im->getImagePixelColor(0, 0);
            $bgColor = $pixel->getColor();
            $bgHex = sprintf('#%02x%02x%02x', $bgColor['r'], $bgColor['g'], $bgColor['b']);

            // Use a fuzz factor to allow near-matching colors (5% by default)
            $fuzz = ($im->getImageWidth() + $im->getImageHeight()) / 2 * 0.01; // heuristic
            // Some Imagick builds lack setImageFuzz(); guard the call to avoid fatal errors
            if (method_exists($im, 'setImageFuzz')) {
                $im->setImageFuzz($fuzz);
            }

            // Make the sampled color transparent
            $im->transparentPaintImage($bgHex, 0, $fuzz, false);

            // Ensure output is PNG to preserve alpha
            $im->setImageFormat('png');
            $newPath = preg_replace('/\.[^.]+$/', '.png', $filePath);
            if ($im->writeImage($newPath)) {
                $im->clear();
                $im->destroy();
                // Remove original if different
                if ($newPath !== $filePath && file_exists($newPath)) {
                    @unlink($filePath);
                }
                return ['path' => $newPath, 'extension' => 'png'];
            }
        } catch (Exception $e) {
            // Ignore and fall back to GD
            error_log('Imagick transparency failed: ' . $e->getMessage());
        }
    }

    // Fallback to GD
    if (!extension_loaded('gd')) {
        return ['path' => $filePath, 'extension' => $extension];
    }

    try {
        $src = null;
        $ext = strtolower($extension);
        switch ($ext) {
            case 'png': $src = imagecreatefrompng($filePath); break;
            case 'gif': $src = imagecreatefromgif($filePath); break;
            case 'webp': if (function_exists('imagecreatefromwebp')) $src = imagecreatefromwebp($filePath); break;
            case 'jpg': case 'jpeg': $src = imagecreatefromjpeg($filePath); break;
            default: return ['path' => $filePath, 'extension' => $extension];
        }

        if (!$src) return ['path' => $filePath, 'extension' => $extension];

        $w = imagesx($src);
        $h = imagesy($src);

        // Sample corner pixels to estimate background color (average)
        $samples = [];
        $coords = [[0,0], [$w-1,0], [0,$h-1], [$w-1,$h-1]];
        foreach ($coords as $c) {
            $rgb = imagecolorat($src, $c[0], $c[1]);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $samples[] = [$r,$g,$b];
        }
        $avg = [0,0,0];
        foreach ($samples as $s) { $avg[0]+=$s[0]; $avg[1]+=$s[1]; $avg[2]+=$s[2]; }
        $avg = [ (int)($avg[0]/count($samples)), (int)($avg[1]/count($samples)), (int)($avg[2]/count($samples)) ];

        // Create destination with alpha
        $dest = imagecreatetruecolor($w, $h);
        imagesavealpha($dest, true);
        $trans = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefill($dest, 0, 0, $trans);

        // Threshold for color distance (0-441). Use 70 as default tolerance
        $tolerance = 70;

        for ($y=0; $y<$h; $y++) {
            for ($x=0; $x<$w; $x++) {
                $rgb = imagecolorat($src, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $dist = sqrt(pow($r-$avg[0],2) + pow($g-$avg[1],2) + pow($b-$avg[2],2));
                if ($dist <= $tolerance) {
                    // keep transparent
                    imagesetpixel($dest, $x, $y, $trans);
                } else {
                    $col = imagecolorallocatealpha($dest, $r, $g, $b, 0);
                    imagesetpixel($dest, $x, $y, $col);
                }
            }
        }

        // Save as PNG to preserve alpha
        $newPath = preg_replace('/\.[^.]+$/', '.png', $filePath);
        if (imagepng($dest, $newPath)) {
            imagedestroy($src);
            imagedestroy($dest);
            if ($newPath !== $filePath) @unlink($filePath);
            return ['path' => $newPath, 'extension' => 'png'];
        }

    } catch (Exception $e) {
        error_log('GD transparency failed: ' . $e->getMessage());
    }

    return ['path' => $filePath, 'extension' => $extension];
}

/**
 * Generate favicons (ico + png sizes) from a logo file.
 * Writes files to public/favicon.ico, public/favicon-32x32.png, public/favicon-16x16.png
 */
function generateFavicons($logoPath) {
    $outDir = BASE_PATH . '/public';
    if (!file_exists($logoPath)) return false;

    // Prefer Imagick if available
    if (class_exists('Imagick')) {
        try {
            $src = new Imagick($logoPath);
            $ico = new Imagick();

            $sizes = [64, 32, 16];
            foreach ($sizes as $s) {
                $frame = clone $src;
                $frame->setImageBackgroundColor(new ImagickPixel('transparent'));
                $frame->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
                $frame->resizeImage($s, $s, Imagick::FILTER_LANCZOS, 1, true);
                $pngPath = $outDir . '/favicon-' . $s . 'x' . $s . '.png';
                $frame->setImageFormat('png32');
                $frame->writeImage($pngPath);

                // Add for .ico (use 64,32,16 frames)
                $ico->addImage(clone $frame);
                $frame->clear();
                $frame->destroy();
            }

            // Write ICO (Imagick will combine frames)
            $ico->setFormat('ico');
            $icoPath = $outDir . '/favicon.ico';
            $ico->writeImage($icoPath);
            $ico->clear();
            $ico->destroy();
            $src->clear();
            $src->destroy();
            return true;
        } catch (Exception $e) {
            error_log('generateFavicons Imagick failed: ' . $e->getMessage());
        }
    }

    // Fallback to GD: create 32x32 and 16x16 PNGs
    if (!extension_loaded('gd')) return false;
    try {
        $info = getimagesize($logoPath);
        $mime = $info['mime'] ?? '';
        switch ($mime) {
            case 'image/png': $img = imagecreatefrompng($logoPath); break;
            case 'image/jpeg': $img = imagecreatefromjpeg($logoPath); break;
            case 'image/gif': $img = imagecreatefromgif($logoPath); break;
            case 'image/webp': $img = imagecreatefromwebp($logoPath); break;
            default: return false;
        }

        foreach ([32,16] as $s) {
            $thumb = imagecreatetruecolor($s, $s);
            imagesavealpha($thumb, true);
            $trans = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefill($thumb, 0, 0, $trans);
            imagecopyresampled($thumb, $img, 0,0,0,0, $s,$s, imagesx($img), imagesy($img));
            $pngPath = $outDir . '/favicon-' . $s . 'x' . $s . '.png';
            imagepng($thumb, $pngPath);
            imagedestroy($thumb);
        }
        imagedestroy($img);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

$success = '';
$error = '';

// Load configs before POST processing (needed for type detection)
$configs = $configClass->getAllDetails();
// Global toggle: when enabled (1) enforce `is_system` protection; when disabled (0) allow editing all keys
$globalConfigProtection = $configClass->get('enable_config_protection', '0');

// Keys that should remain editable in the admin UI even if marked as system
$editableSystemKeys = [
    'ad_host','ad_port','ad_base_dn','ad_bind_dn','ad_bind_password','ad_use_ssl','ad_use_tls','ad_require_group','ad_domain','enable_ad',
    'ldap_host','ldap_port','ldap_base_dn','ldap_bind_dn','ldap_bind_password','enable_ldap',
    'default_max_downloads'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
    } else {
        try {
            // If global protection is enabled, do not accept updates from this page.
            if ((bool)$globalConfigProtection) {
                $error = 'Protección de configuración activada: no se permiten cambios desde esta página. Use el control de protección en el menú de usuario.';
                // Skip processing updates
                throw new Exception($error);
            }
            // Handle logo upload
            if (isset($_FILES['site_logo_file']) && $_FILES['site_logo_file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = BASE_PATH . '/public/uploads/branding/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $fileInfo = pathinfo($_FILES['site_logo_file']['name']);
                $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
                $extension = strtolower($fileInfo['extension']);
                
                if (!in_array($extension, $allowedTypes)) {
                    throw new Exception('Tipo de archivo no permitido. Use: jpg, png, gif, svg, webp');
                }
                
                // Check file size (max 2MB)
                if ($_FILES['site_logo_file']['size'] > 2 * 1024 * 1024) {
                    throw new Exception('El archivo es demasiado grande. Máximo 2MB');
                }
                
                $filename = 'logo_' . time() . '.' . $extension;
                $destination = $uploadDir . $filename;
                
                if (move_uploaded_file($_FILES['site_logo_file']['tmp_name'], $destination)) {
                    // Save original copy before any processing (for rollback)
                    $origCopy = $uploadDir . 'orig_' . time() . '_' . $filename;
                    if (!file_exists($origCopy)) {
                        @copy($destination, $origCopy);
                    }

                    // Attempt to make background transparent (if applicable)
                    $result = makeBackgroundTransparentIfPossible($destination, $extension);
                    if (!empty($result) && isset($result['path']) && $result['path'] !== $destination) {
                        // Update destination and extension to the converted PNG
                        $destination = $result['path'];
                        $extension = $result['extension'];
                        $filename = basename($destination);
                    }
                    // Delete old logo if exists
                    $oldLogo = $configClass->get('site_logo');
                    if ($oldLogo && file_exists(BASE_PATH . '/public/' . $oldLogo)) {
                        unlink(BASE_PATH . '/public/' . $oldLogo);
                    }
                    
                    // Save new logo path
                    $configClass->set('site_logo', 'uploads/branding/' . $filename);
                    $logger->log($user['id'], 'logo_upload', 'system', null, "Logo actualizado: $filename");
                    
                    // Auto-extract colors if checkbox is checked (or by default)
                    $autoExtractColors = isset($_POST['auto_extract_colors']) ? $_POST['auto_extract_colors'] === '1' : true;
                    $colorsExtracted = false;
                    
                    if ($autoExtractColors && $extension !== 'svg') {
                        require_once __DIR__ . '/../../classes/ColorExtractor.php';
                        $colorExtractor = new ColorExtractor();
                        
                        try {
                            $brandColors = $colorExtractor->extractBrandColors($destination);
                            
                            // Update brand colors
                            $configClass->set('brand_primary_color', $brandColors['primary']);
                            $configClass->set('brand_secondary_color', $brandColors['secondary']);
                            $configClass->set('brand_accent_color', $brandColors['accent']);
                            
                            $logger->log($user['id'], 'colors_extracted', 'system', null, 
                                "Colores extraídos automáticamente del logo: " . 
                                "Primary={$brandColors['primary']}, " .
                                "Secondary={$brandColors['secondary']}, " .
                                "Accent={$brandColors['accent']}"
                            );
                            
                            $colorsExtracted = true;
                            
                            $success = sprintf(
                                'Logo actualizado correctamente. Colores extraídos automáticamente: ' .
                                'Primario (%s), Secundario (%s), Acento (%s)',
                                $brandColors['primary'],
                                $brandColors['secondary'],
                                $brandColors['accent']
                            );
                            // Generate favicons from the uploaded logo (best-effort)
                            try {
                                if (function_exists('generateFavicons')) {
                                    $generated = generateFavicons(BASE_PATH . '/public/uploads/branding/' . $filename);
                                    if ($generated) {
                                        $logger->log($user['id'], 'favicon_generated', 'system', null, "Favicons generados desde logo: $filename");
                                    }
                                }
                            } catch (Exception $e) {
                                $logger->log($user['id'], 'favicon_generation_failed', 'system', null, 'Error generando favicons: ' . $e->getMessage());
                            }
                        } catch (Exception $e) {
                            // Don't fail the whole upload if color extraction fails
                            $logger->log($user['id'], 'color_extraction_failed', 'system', null, 
                                "Error extrayendo colores: " . $e->getMessage()
                            );
                            $success = 'Logo actualizado correctamente (no se pudieron extraer colores automáticamente)';
                        }
                    } else {
                        $success = 'Logo actualizado correctamente';
                    }
                } else {
                    throw new Exception('Error al subir el archivo');
                }
            }
            
            // Build updates array. Handle boolean checkboxes using presence markers so unchecked boxes are
            // correctly interpreted as '0' when present. Do not modify readonly boolean configs.
            $updates = [];

            // First, handle boolean configs which include a presence marker when editable
            foreach ($configs as $cfg) {
                if ($cfg['config_type'] === 'boolean') {
        $key = $cfg['config_key'];
        // Hide internal toggle from the admin UI per request
        if ($key === 'enable_config_protection') continue;
                    $isReadonly = ((bool)$globalConfigProtection && $cfg['is_system']) && !in_array($key, $editableSystemKeys);
                    // Only process booleans if the form included the presence marker (i.e., the control was editable)
                    if (isset($_POST[$key . '_present']) && !$isReadonly) {
                        $updates[$key] = isset($_POST[$key]) ? '1' : '0';
                    }
                }
            }

            // Then process other posted fields (text, numbers, etc.). Skip presence markers and other internal keys.
            foreach ($_POST as $key => $value) {
                // Skip csrf_token, site_logo (handled separately), auto_extract_colors (not a config)
                // and color fields if they were auto-extracted
                $skipKeys = ['csrf_token', 'site_logo', 'auto_extract_colors', 'site_logo_file'];
                if (isset($colorsExtracted) && $colorsExtracted) {
                    $skipKeys = array_merge($skipKeys, ['brand_primary_color', 'brand_secondary_color', 'brand_accent_color']);
                }
                // Also skip presence markers for boolean fields
                if (substr($key, -8) === '_present') continue;
                
                // Only skip if key is in skipKeys or key is empty string
                if (!in_array($key, $skipKeys) && $key !== '') {
                    // Allow empty values and zero values
                    $updates[$key] = $value;
                }
            }
            
                // Keys that should remain editable in the admin UI even if marked as system
                $editableSystemKeys = [
                    'ad_host','ad_port','ad_base_dn','ad_bind_dn','ad_bind_password','ad_use_ssl','ad_use_tls','ad_require_group','ad_domain','enable_ad',
                    'ldap_host','ldap_port','ldap_base_dn','ldap_bind_dn','ldap_bind_password','enable_ldap'
                ];

                foreach ($updates as $key => $value) {
                // Get existing config to preserve type
                $existingConfig = null;
                foreach ($configs as $cfg) {
                    if ($cfg['config_key'] === $key) {
                        $existingConfig = $cfg;
                        break;
                    }
                }
                
                // Determine type
                $type = $existingConfig ? $existingConfig['config_type'] : 'string';
                
                // Handle boolean checkboxes (unchecked = not in POST)
                if ($type === 'boolean' && !isset($_POST[$key])) {
                    $value = '0';
                }
                
                $configClass->set($key, $value, $type);
            }
            
            $logger->log($user['id'], 'config_update', 'system', null, 'Configuración actualizada');
            
            // Only set generic message if no specific message was set
            if (empty($success)) {
                $success = 'Configuración actualizada correctamente';
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    // Reload configs after update
    $configs = $configClass->getAllDetails();
}

// Get brand colors
$brandPrimary = $configClass->get('brand_primary_color', '#667eea');
$brandSecondary = $configClass->get('brand_secondary_color', '#764ba2');
$brandAccent = $configClass->get('brand_accent_color', '#667eea');

// Agrupar configuraciones por categoría
$categories = [
    'general' => ['title' => 'General', 'icon' => '<i class="fas fa-cog"></i>', 'configs' => []],
    'branding' => ['title' => 'Marca e Identidad', 'icon' => '<i class="fas fa-paint-brush"></i>', 'configs' => []],
    'storage' => ['title' => 'Almacenamiento', 'icon' => '<i class="fas fa-save"></i>', 'configs' => []],
    'share' => ['title' => 'Compartición', 'icon' => '<i class="fas fa-link"></i>', 'configs' => []],
    'ldap' => ['title' => 'LDAP (OpenLDAP)', 'icon' => '<i class="fas fa-server"></i>', 'configs' => []],
    'ad' => ['title' => 'Active Directory', 'icon' => '<i class="fas fa-windows"></i>', 'configs' => []],
    'email' => ['title' => 'Correo Electrónico', 'icon' => '<i class="fas fa-envelope"></i>', 'configs' => []],
    '2fa' => ['title' => 'Autenticación 2FA (TOTP)', 'icon' => '<i class="fas fa-lock"></i>', 'configs' => []],
    'duo' => ['title' => 'Duo Security', 'icon' => '<i class="fas fa-shield-alt"></i>', 'configs' => []],
    'appearance' => ['title' => 'Apariencia', 'icon' => '<i class="fas fa-palette"></i>', 'configs' => []],
    'other' => ['title' => 'Otros', 'icon' => '<i class="fas fa-clipboard"></i>', 'configs' => []]
];

                foreach ($configs as $cfg) {
    $key = $cfg['config_key'];
    $category = 'other';
    
    // General settings
    if (in_array($key, ['admin_email', 'timezone', 'base_url', 'maintenance_mode', 'maintenance_message', 'session_lifetime', 'session_name']) 
        || (strpos($key, 'default_') === 0)) {
        $category = 'general';
    } 
    // Branding
    elseif (strpos($key, 'brand_') === 0 || $key === 'site_logo' || $key === 'site_name') {
        $category = 'branding';
    } 
    // Storage
    elseif (strpos($key, 'max_file_') === 0 || strpos($key, 'storage_') === 0 || $key === 'allowed_extensions') {
        $category = 'storage';
    } 
    // Sharing
    elseif (strpos($key, 'share_') === 0 || strpos($key, 'default_max_share') === 0 || strpos($key, 'default_max_downloads') === 0 || $key === 'allow_public_shares') {
        $category = 'share';
    } 
    // LDAP (OpenLDAP)
    elseif (strpos($key, 'ldap_') === 0 || $key === 'enable_ldap') {
        $category = 'ldap';
    }
    // Active Directory
    elseif (strpos($key, 'ad_') === 0 || $key === 'enable_ad') {
        $category = 'ad';
    } 
    // Email
    elseif (strpos($key, 'smtp_') === 0 || strpos($key, 'email_') === 0 || $key === 'enable_email') {
        $category = 'email';
    } 
    // 2FA/TOTP
    elseif (strpos($key, '2fa_') === 0 || $key === 'totp_issuer_name' || $key === 'require_2fa_for_admins' 
        || in_array($key, ['max_login_attempts', 'lockout_duration'])) {
        $category = '2fa';
    } 
    // Duo Security
    elseif (strpos($key, 'duo_') === 0 || $key === 'enable_duo') {
        $category = 'duo';
    } 
    // Appearance
    elseif (strpos($key, 'logo') !== false || strpos($key, 'footer') !== false || strpos($key, 'theme') !== false 
        || in_array($key, ['enable_dark_mode', 'items_per_page', 'enable_registration'])) {
        $category = 'appearance';
    }
    
    $categories[$category]['configs'][] = $cfg;
}

// Ensure in 'branding' category the order places site_name and site_description first
if (!empty($categories['branding']['configs'])) {
    $branding = $categories['branding']['configs'];
    $ordered = [];
    // Pull site_name and site_description first if present
    foreach (['site_name', 'site_description'] as $wantKey) {
        foreach ($branding as $idx => $b) {
            if ($b['config_key'] === $wantKey) {
                $ordered[] = $b;
                unset($branding[$idx]);
                break;
            }
        }
    }
    // Append remaining branding configs
    foreach ($branding as $b) $ordered[] = $b;
    // Reassign
    $categories['branding']['configs'] = $ordered;
}

// Ensure enable toggles appear first in their respective categories
$promote = [
    'email' => ['enable_email'],
    'ldap' => ['enable_ldap'],
    'ad' => ['enable_ad']
];
foreach ($promote as $cat => $wantKeys) {
    if (!empty($categories[$cat]['configs'])) {
        $list = $categories[$cat]['configs'];
        $ordered = [];
        foreach ($wantKeys as $wantKey) {
            foreach ($list as $idx => $item) {
                if ($item['config_key'] === $wantKey) {
                    $ordered[] = $item;
                    unset($list[$idx]);
                    break;
                }
            }
        }
        // Append remaining items
        foreach ($list as $it) $ordered[] = $it;
        $categories[$cat]['configs'] = $ordered;
    }
}

renderPageStart('Configuración', 'config', true);
renderHeader('Configuración del Sistema', $user, $auth);
?>
<div class="content">
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <!-- Global config protection indicator -->
    <div style="margin-bottom:1rem;">
        <span style="font-weight:700;">Protección de configuración:</span>
        <span id="configProtectionIndicator" style="margin-left:0.75rem; display:inline-flex; align-items:center; gap:0.5rem;">
            <?php if ((bool)$globalConfigProtection): ?>
                <i class="fas fa-lock" style="color:#dc2626; font-size:1.05rem;"></i>
                <span style="color:#dc2626; font-weight:600;">Activada</span>
            <?php else: ?>
                <i class="fas fa-lock-open" style="color:#16a34a; font-size:1.05rem;"></i>
                <span style="color:#16a34a; font-weight:600;">Desactivada</span>
            <?php endif; ?>
        </span>
    </div>
    
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
        
        <?php foreach ($categories as $catKey => $category): ?>
            <?php if (!empty($category['configs'])): ?>
            <div id="config-<?php echo htmlspecialchars($catKey); ?>" class="card" style="margin-bottom: 2rem;">
                <div class="card-header">
                    <h3 class="card-title"><?php echo $category['icon']; ?> <?php echo $category['title']; ?></h3>
                </div>
                <div class="card-body">
                    <?php if ($catKey === 'ldap' || $catKey === 'ad'): ?>
                    <div style="margin-bottom:1rem;">
                        <button type="button" class="btn btn-info" onclick="testLdap('<?php echo $catKey; ?>')">
                            <i class="fas fa-vial"></i> TEST conexión <?php echo strtoupper($catKey); ?> 
                        </button>
                        <span id="testLdapResult_<?php echo $catKey; ?>" style="margin-left:1rem;font-weight:bold;"></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($catKey === 'email'): ?>
                    <div style="margin-bottom:1rem;">
                        <button type="button" class="btn btn-info" onclick="testSmtp()">
                            <i class="fas fa-paper-plane"></i> TEST correo
                        </button>
                        <span id="testSmtpResult" style="margin-left:1rem;font-weight:bold;"></span>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($category['configs'] as $cfg): ?>
                    <div class="form-group">
                        <label>
                            <?php
                                $friendlyLabels = [
                                    'site_name' => 'Nombre del Sitio',
                                    'site_description' => 'Descripción del Sitio',
                                    'site_logo' => 'Logo del Sitio',
                                    'ad_required_group_dn' => 'AD: Grupo permitido (DN)',
                                    'ad_admin_group_dn' => 'AD: Grupo administradores (DN)',
                                    'ldap_required_group_dn' => 'LDAP: Grupo permitido (DN)',
                                    'ldap_admin_group_dn' => 'LDAP: Grupo administradores (DN)'
                                ];
                                // Friendly labels for notification settings
                                $friendlyLabels['notify_user_creation_enabled'] = 'Notificar creación de usuario (invites)';
                                $friendlyLabels['notify_user_creation_emails'] = 'Emails adicionales para notificaciones de usuario';
                                $friendlyLabels['notify_user_creation_to_admins'] = 'Notificar también a administradores';
                                $friendlyLabels['notify_user_creation_retry_attempts'] = 'Reintentos de notificación';
                                $friendlyLabels['notify_user_creation_retry_delay_seconds'] = 'Retraso inicial entre intentos (s)';
                                $friendlyLabels['notify_user_creation_use_background_worker'] = 'Usar worker en background para notificaciones';
                                $label = $friendlyLabels[$cfg['config_key']] ?? $cfg['config_key'];
                                echo htmlspecialchars($label);
                            ?>
                            <?php if ($cfg['is_system']): ?>
                                <span class="badge badge-secondary" style="margin-left: 0.5rem;">Sistema</span>
                            <?php endif; ?>
                        </label>
                        
                        <?php if ($cfg['config_key'] === 'site_logo'): ?>
                            <!-- Logo upload -->
                            <div style="margin-bottom: 1rem;">
                                <?php if (!empty($cfg['config_value'])): ?>
                                    <div style="margin-bottom: 1rem; padding: 1rem; background: var(--bg-secondary); border-radius: var(--radius-md); display: inline-block;">
                                        <img src="<?php echo BASE_URL . '/_asset.php?f=' . str_replace('%2F','/',rawurlencode($cfg['config_value'])); ?>" 
                                             alt="Logo actual" 
                                             id="current_logo_preview"
                                             style="max-width: 200px; max-height: 100px; display: block;">
                                    </div>
                                <?php endif; ?>
                                <div style="display: flex; gap: 1rem; align-items: center;">
                                    <input 
                                        type="file" 
                                        name="site_logo_file" 
                                        id="site_logo_file"
                                        accept="image/jpeg,image/png,image/gif,image/svg+xml,image/webp"
                                        class="form-control"
                                        style="flex: 1;"
                                        onchange="previewLogo(this)"
                                    >
                                    <button type="button" onclick="document.getElementById('site_logo_file').value=''; document.getElementById('logo_preview').style.display='none';" class="btn btn-outline btn-outline--on-dark" style="white-space: nowrap;">
                                        <i class="fas fa-times"></i> Limpiar
                                    </button>
                                </div>
                                <div id="logo_preview" style="display: none; margin-top: 1rem; padding: 1rem; background: var(--bg-secondary); border-radius: var(--radius-md); display: inline-block;">
                                    <p style="margin-bottom: 0.5rem; color: var(--text-secondary); font-size: 0.875rem;">Vista previa:</p>
                                    <img id="logo_preview_img" style="max-width: 200px; max-height: 100px; display: block;">
                                </div>
                                <div style="margin-top: 1rem; padding: 1rem; background: #e0f2fe; border-left: 4px solid #0ea5e9; border-radius: var(--radius-md);">
                                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; margin: 0;">
                                        <input type="checkbox" name="auto_extract_colors" value="1" checked style="width: 18px; height: 18px; cursor: pointer;">
                                        <span style="color: #0c4a6e; font-weight: 600;">
                                            <i class="fas fa-magic"></i> Extraer colores automáticamente del logo
                                        </span>
                                    </label>
                                    <p style="margin: 0.5rem 0 0 1.75rem; color: #075985; font-size: 0.8125rem;">
                                        Los colores primario, secundario y de acento se ajustarán basándose en los colores dominantes del logo
                                    </p>
                                </div>
                            </div>
                            <input type="hidden" name="<?php echo htmlspecialchars($cfg['config_key']); ?>" value="<?php echo htmlspecialchars($cfg['config_value']); ?>">
                        <?php elseif (strpos($cfg['config_key'], '_color') !== false): ?>
                            <!-- Color picker for brand colors -->
                            <div style="display: flex; gap: 0.75rem; align-items: center;">
                                <input 
                                    type="color" 
                                    id="color_<?php echo htmlspecialchars($cfg['config_key']); ?>"
                                    value="<?php echo htmlspecialchars($cfg['config_value']); ?>" 
                                    style="width: 60px; height: 40px; border: 2px solid var(--border-color); border-radius: var(--radius-md); cursor: pointer;"
                                    onchange="document.getElementById('text_<?php echo htmlspecialchars($cfg['config_key']); ?>').value = this.value; updateColorPreview();"
                                >
                                <input 
                                    type="text" 
                                    id="text_<?php echo htmlspecialchars($cfg['config_key']); ?>"
                                    name="<?php echo htmlspecialchars($cfg['config_key']); ?>" 
                                    class="form-control" 
                                    value="<?php echo htmlspecialchars($cfg['config_value']); ?>" 
                                    placeholder="#1e40af"
                                    style="flex: 1; font-family: monospace;"
                                    oninput="document.getElementById('color_<?php echo htmlspecialchars($cfg['config_key']); ?>').value = this.value; updateColorPreview();"
                                >
                                <button 
                                    type="button" 
                                    class="btn btn-outline btn-outline--on-dark" 
                                    onclick="pickColorFromScreen('<?php echo htmlspecialchars($cfg['config_key']); ?>')"
                                    title="Seleccionar color de la pantalla"
                                    style="padding: 0.5rem 0.75rem; height: 40px; white-space: nowrap;">
                                    <i class="fas fa-eye-dropper"></i>
                                </button>
                                <div style="width: 40px; height: 40px; border-radius: var(--radius-md); border: 2px solid var(--border-color); background: <?php echo htmlspecialchars($cfg['config_value']); ?>;" id="preview_<?php echo htmlspecialchars($cfg['config_key']); ?>"></div>
                            </div>
                        <?php elseif ($cfg['config_key'] === 'email_signature'): ?>
                            <label style="display:block; font-weight:600; margin-bottom:0.25rem;">Firma de correo (HTML permitido)</label>
                            <textarea 
                                name="<?php echo htmlspecialchars($cfg['config_key']); ?>" 
                                id="email_signature_field"
                                class="form-control" 
                                rows="6"
                                placeholder="Puedes pegar HTML aquí (imágenes en data-URI o URLs absolutas)"
                                <?php echo (bool)$globalConfigProtection ? 'readonly style="color:#6b6b6b;"' : ''; ?>
                            ><?php echo htmlspecialchars($cfg['config_value']); ?></textarea>
                            <div style="margin-top:0.5rem; display:flex; gap:0.5rem; align-items:center;">
                                <label style="margin:0; font-weight:600;">Vista previa:</label>
                                <button type="button" class="btn btn-outline btn-outline--on-dark" onclick="toggleSignaturePreview()" style="padding:0.25rem 0.5rem;">Mostrar / Ocultar</button>
                            </div>
                            <div id="email_signature_preview" style="margin-top:0.75rem; padding:0.75rem; background:#fff; border:1px solid var(--border-color); border-radius:0.5rem; display:none; max-height:220px; overflow:auto;"></div>

                        <?php elseif ($cfg['config_key'] === 'notify_user_creation_emails'): ?>
                            <label style="display:block; font-weight:600; margin-bottom:0.25rem;">Direcciones de notificación (comas separadas)</label>
                            <textarea
                                name="<?php echo htmlspecialchars($cfg['config_key']); ?>"
                                id="notify_user_creation_emails"
                                class="form-control"
                                rows="3"
                                placeholder="ops@example.com, infra@example.com"
                                <?php echo (bool)$globalConfigProtection ? 'readonly style="color:#6b6b6b;"' : ''; ?>
                            ><?php echo htmlspecialchars($cfg['config_value']); ?></textarea>
                            <small style="color: var(--text-muted); display:block; margin-top:0.25rem;">Introduce direcciones separadas por comas. Si también está activada la opción "Notificar a administradores", se les añadirá automáticamente.</small>

                        <?php elseif (strlen($cfg['config_value']) > 100 || strpos($cfg['config_key'], 'footer') !== false): ?>
                            <?php
                            // Placeholders for textarea fields
                            $textareaPlaceholders = [
                                'footer_links' => '{"Términos": "/terms", "Privacidad": "/privacy", "Contacto": "/contact"}',
                                'allowed_extensions' => 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png,gif,zip,rar,7z,mp4,mp3',
                            ];
                            $placeholder = $textareaPlaceholders[$cfg['config_key']] ?? '';
                            ?>
                            <textarea 
                                name="<?php echo htmlspecialchars($cfg['config_key']); ?>" 
                                class="form-control" 
                                rows="3"
                                <?php if ($placeholder): ?>placeholder="<?php echo htmlspecialchars($placeholder); ?>"<?php endif; ?>
                                <?php echo (bool)$globalConfigProtection ? 'readonly style="color:#6b6b6b;"' : ''; ?>
                            ><?php echo htmlspecialchars($cfg['config_value']); ?></textarea>
                        <?php else: ?>
                            <?php
                            // Placeholders for text fields based on config key
                            $placeholders = [
                                'site_name' => 'Mi Gestor de Archivos',
                                'site_description' => 'Sistema seguro de gestión y compartición de archivos',
                                'admin_email' => 'admin@ejemplo.com',
                                'email_from_address' => 'noreply@ejemplo.com',
                                'email_from_name' => 'Sistema de Archivos',
                                'smtp_host' => 'smtp.gmail.com',
                                'smtp_port' => '587',
                                'smtp_username' => 'tu-email@gmail.com',
                                'smtp_password' => '••••••••',
                                'base_url' => 'https://files.ejemplo.com',
                                'default_storage_quota' => '10737418240',
                                'max_file_size' => '536870912',
                                'allowed_extensions' => 'pdf,doc,docx,xls,xlsx,jpg,png,zip',
                                'default_max_share_days' => '30',
                                'default_max_downloads' => '100',
                                // OpenLDAP placeholders
                                'ldap_host' => 'ldap://ldap.ejemplo.com',
                                'ldap_port' => '389',
                                'ldap_base_dn' => 'dc=ejemplo,dc=com',
                                'ldap_bind_dn' => 'cn=admin,dc=ejemplo,dc=com',
                                'ldap_bind_password' => '••••••••',
                                'ldap_search_filter' => '(&(objectClass=inetOrgPerson)(uid=%s))',
                                'ldap_username_attribute' => 'uid',
                                'ldap_email_attribute' => 'mail',
                                'ldap_display_name_attribute' => 'cn',
                                'ldap_require_group' => 'cn=usuarios_archivos,ou=grupos,dc=ejemplo,dc=com',
                                'ldap_group_filter' => '(&(objectClass=groupOfNames)(member=%s))',
                                // Active Directory placeholders
                                'ad_host' => 'ldap://dc01.empresa.local',
                                'ad_port' => '389',
                                'ad_base_dn' => 'dc=empresa,dc=local',
                                'ad_bind_dn' => 'cn=svc_bind,cn=Users,dc=empresa,dc=local',
                                'ad_bind_password' => '••••••••',
                                'ad_search_filter' => '(&(objectClass=user)(sAMAccountName=%s))',
                                'ad_username_attribute' => 'sAMAccountName',
                                'ad_email_attribute' => 'mail',
                                'ad_display_name_attribute' => 'displayName',
                                'ad_require_group' => 'cn=Usuarios Archivos,ou=Grupos,dc=empresa,dc=local',
                                'ad_group_filter' => '(&(objectClass=group)(member=%s))',
                                'duo_client_id' => 'DIXXXXXXXXXXXXX',
                                'duo_client_secret' => '••••••••••••••••••••',
                                'duo_api_hostname' => 'api-xxxxxxxx.duosecurity.com',
                                'duo_redirect_uri' => 'https://files.ejemplo.com/login_2fa_duo_callback.php',
                                '2fa_max_attempts' => '3',
                                '2fa_lockout_minutes' => '15',
                                '2fa_grace_period_hours' => '24',
                                '2fa_device_trust_days' => '30',
                                'session_timeout' => '3600',
                                'password_min_length' => '8',
                                'password_require_special' => '1',
                                'max_login_attempts' => '5',
                                'login_lockout_minutes' => '15',
                            ];
                            $placeholder = $placeholders[$cfg['config_key']] ?? '';
                            ?>
                            <?php
                            // Only treat configs as readonly if global protection is enabled and the key is marked system,
                            // unless the key is explicitly listed in `$editableSystemKeys`.
                            // Global protection: when enabled, all fields are readonly. When disabled, all editable.
                            $isReadonly = (bool)$globalConfigProtection;
                            $inputType = 'text';
                            $valueAttr = htmlspecialchars($cfg['config_value'], ENT_QUOTES);
                            // For password fields, render a password input and leave value empty (admin can fill to change)
                            if (substr($cfg['config_key'], -9) === '_password') {
                                $inputType = 'password';
                                $valueAttr = '';
                            }
                            ?>
                            <input 
                                type="<?php echo $inputType; ?>" 
                                name="<?php echo htmlspecialchars($cfg['config_key']); ?>" 
                                class="form-control" 
                                value="<?php echo $valueAttr; ?>"
                                <?php if ($placeholder): ?>placeholder="<?php echo htmlspecialchars($placeholder); ?>"<?php endif; ?>
                                <?php echo $isReadonly ? 'readonly' : ''; ?><?php echo $isReadonly ? ' style="color:#6b6b6b;"' : ''; ?>
                            >
                            <?php if (substr($cfg['config_key'], -9) === '_password'): ?>
                                <small class="form-text text-muted">Dejar vacío para no cambiar la contraseña existente.</small>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if ($cfg['description']): ?>
                            <small style="color: var(--text-muted); display: block; margin-top: 0.25rem;">
                                <?php echo htmlspecialchars($cfg['description']); ?>
                            </small>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
        
        <div style="position: sticky; bottom: 1rem; background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%); padding: 1.5rem; border-radius: 1rem; box-shadow: 0 8px 24px rgba(0,0,0,0.15); border: 2px solid var(--border-color);">
            <button type="submit" class="btn btn-primary" style="padding: 0.875rem 2rem; font-size: 1.0625rem; font-weight: 700; box-shadow: 0 4px 12px rgba(74, 144, 226, 0.3);"><i class="fas fa-save"></i> Guardar Cambios</button>
            <a href="<?php echo BASE_URL; ?>/admin/index.php" class="btn btn-outline btn-outline--on-dark" style="padding: 0.875rem 2rem; font-size: 1.0625rem; font-weight: 600;">Cancelar</a>
        </div>
    </form>
</div>

<script>
// Validate notification emails before submitting config form
(function(){
    var form = document.querySelector('form');
    if (!form) return;
    form.addEventListener('submit', function(e){
        try {
            // Presence marker is used for boolean controls; check the actual checkbox
            var enabledEl = document.querySelector('input[name="notify_user_creation_enabled"]');
            var enabledOn = enabledEl ? enabledEl.checked : false;
            if (!enabledOn) return;
            var emailsField = document.getElementById('notify_user_creation_emails');
            var notifyAdminsEl = document.querySelector('input[name="notify_user_creation_to_admins"]');
            var notifyAdmins = notifyAdminsEl ? notifyAdminsEl.checked : false;

            var hasEmails = false;
            if (emailsField) {
                var val = emailsField.value.trim();
                if (val !== '') {
                    var parts = val.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
                    var emailRegex = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
                    for (var i=0;i<parts.length;i++) if (emailRegex.test(parts[i])) { hasEmails = true; break; }
                }
            }
            if (!hasEmails && !notifyAdmins) {
                e.preventDefault();
                alert('La notificación de creación de usuario está activada pero no hay destinatarios válidos. Añade al menos un email válido o marca "Notificar también a administradores".');
            }
        } catch (err) {
            // ignore any JS errors in validation
        }
    });
})();

function previewLogo(input) {
    const preview = document.getElementById('logo_preview');
    const previewImg = document.getElementById('logo_preview_img');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            preview.style.display = 'inline-block';
        };
        
        reader.readAsDataURL(input.files[0]);
    } else {
        preview.style.display = 'none';
    }
}

function toggleSignaturePreview() {
    const preview = document.getElementById('email_signature_preview');
    const field = document.getElementById('email_signature_field');
    if (!preview || !field) return;
    if (preview.style.display === 'none' || preview.style.display === '') {
        // Render HTML as-is (admin-provided). This is an admin-only field; trust admin.
        preview.innerHTML = field.value;
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }
}

// Update preview live when editing
document.addEventListener('DOMContentLoaded', function() {
    const field = document.getElementById('email_signature_field');
    if (!field) return;
    field.addEventListener('input', function() {
        const preview = document.getElementById('email_signature_preview');
        if (preview && preview.style.display === 'block') {
            preview.innerHTML = field.value;
        }
    });
});

function updateColorPreview() {
    // Update color preview squares
    const colorInputs = document.querySelectorAll('input[type="text"][id^="text_"][id*="color"]');
    colorInputs.forEach(input => {
        const key = input.id.replace('text_', '');
        const preview = document.getElementById('preview_' + key);
        if (preview) {
            preview.style.background = input.value;
        }
    });
    
    // Live preview in page (optional)
    const primaryColor = document.getElementById('text_brand_primary_color');
    const secondaryColor = document.getElementById('text_brand_secondary_color');
    const accentColor = document.getElementById('text_brand_accent_color');
    
    if (primaryColor) {
        document.documentElement.style.setProperty('--brand-primary', primaryColor.value);
    }
    if (secondaryColor) {
        document.documentElement.style.setProperty('--brand-secondary', secondaryColor.value);
    }
    if (accentColor) {
        document.documentElement.style.setProperty('--brand-accent', accentColor.value);
    }
}

// Initialize on load
document.addEventListener('DOMContentLoaded', updateColorPreview);

// EyeDropper API for color picking
async function pickColorFromScreen(configKey) {
    // Check if EyeDropper API is supported
    if (!window.EyeDropper) {
        alert('Tu navegador no soporta la herramienta de selección de colores (EyeDropper API).\n\nPrueba con Chrome, Edge o Opera actualizado.');
        return;
    }
    
    try {
        const eyeDropper = new EyeDropper();
        const result = await eyeDropper.open();
        
        if (result && result.sRGBHex) {
            const color = result.sRGBHex;
            
            // Update all inputs
            document.getElementById('color_' + configKey).value = color;
            document.getElementById('text_' + configKey).value = color;
            
            // Update preview
            updateColorPreview();
            }
        } catch (err) {
        // User cancelled or error occurred
        if (err.name !== 'AbortError') {
            console.error('Error al seleccionar color:', err);
        }
    }
}

</script>
<script>
function testLdap(type) {
    const btn = event.target;
    const resultSpan = document.getElementById('testLdapResult_' + type);
    btn.disabled = true;
    resultSpan.textContent = 'Probando...';
    fetch('<?php echo BASE_URL; ?>/admin/test_ldap.php?type=' + encodeURIComponent(type))
        .then(Mimir.parseJsonResponse)
        .then(data => {
            if (data.success) {
                resultSpan.textContent = '✅ ' + data.message;
                resultSpan.style.color = '#16a34a'; // verde
            } else {
                resultSpan.textContent = '❌ ' + (data.message || 'Error desconocido');
                resultSpan.style.color = '#dc2626'; // rojo
            }
        })
        .catch((err) => {
            resultSpan.textContent = '❌ Error de conexión: ' + (err && err.message ? err.message : '');
            resultSpan.style.color = '#dc2626';
        })
        .finally(() => { btn.disabled = false; });
}
</script>

<script>
function testSmtp() {
    const btn = event.target;
    const resultSpan = document.getElementById('testSmtpResult');
    btn.disabled = true;
    resultSpan.textContent = 'Probando...';

    // Collect current form values to allow testing live edits
    const data = new FormData();
    const fields = ['smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_password'];
    fields.forEach(f => {
        const el = document.querySelector('[name="' + f + '"]');
        if (el) data.append(f, el.value);
    });

    fetch('<?php echo BASE_URL; ?>/admin/config.php?action=test_smtp', { method: 'POST', body: data })
        .then(Mimir.parseJsonResponse)
        .then(data => {
            if (data.success) {
                resultSpan.textContent = '✅ ' + (data.message || 'Conexión OK');
                resultSpan.style.color = '#16a34a';
            } else {
                let msg = data.message || 'Error desconocido';
                if (data.debug && Array.isArray(data.debug)) {
                    msg += ' — ' + data.debug.slice(0,2).join(' | ');
                }
                resultSpan.textContent = '❌ ' + msg;
                resultSpan.style.color = '#dc2626';
            }
        })
        .catch((err) => {
            resultSpan.textContent = '❌ Error de conexión: ' + (err && err.message ? err.message : '');
            resultSpan.style.color = '#dc2626';
        })
        .finally(() => { btn.disabled = false; });
}
</script>

<!-- TinyMCE self-hosted integration for email signature field (served locally to avoid API key/CSP issues) -->
<script src="<?php echo BASE_URL; ?>/assets/vendor/tinymce/tinymce.min.js" referrerpolicy="no-referrer"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var sigField = document.getElementById('email_signature_field');
    if (!sigField) return;

    // Ensure TinyMCE loads assets from the local vendor directory
    if (window.tinymce) {
        try { tinymce.baseURL = '<?php echo BASE_URL; ?>/assets/vendor/tinymce'; } catch(e) {}
    }

    tinymce.init({
        selector: '#email_signature_field',
        height: 300,
        menubar: false,
        plugins: 'link image media paste code',
        toolbar: 'undo redo | styleselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image | code',
        skin: false, // use local skin files
        skin_url: '<?php echo BASE_URL; ?>/assets/vendor/tinymce/skins/ui/oxide',
        content_css: '<?php echo BASE_URL; ?>/assets/vendor/tinymce/skins/ui/oxide/content.min.css',
        // Map plugins to local copies to avoid any external network calls
        external_plugins: {
            paste: '<?php echo BASE_URL; ?>/assets/vendor/tinymce/plugins/paste/plugin.min.js',
            image: '<?php echo BASE_URL; ?>/assets/vendor/tinymce/plugins/image/plugin.min.js',
            media: '<?php echo BASE_URL; ?>/assets/vendor/tinymce/plugins/media/plugin.min.js',
            link: '<?php echo BASE_URL; ?>/assets/vendor/tinymce/plugins/link/plugin.min.js',
            code: '<?php echo BASE_URL; ?>/assets/vendor/tinymce/plugins/code/plugin.min.js'
        },
        images_upload_handler: function (blobInfo, success, failure) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo BASE_URL; ?>/admin/upload_signature_image.php');
            // send CSRF token header
            xhr.setRequestHeader('X-CSRF-Token', '<?php echo $auth->generateCsrfToken(); ?>');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var json = JSON.parse(xhr.responseText);
                        if (json.location) {
                            success(json.location);
                        } else {
                            failure('Respuesta inválida: ' + xhr.responseText);
                        }
                    } catch (e) {
                        failure('JSON inválido: ' + e.message);
                    }
                } else {
                    failure('HTTP Error: ' + xhr.status);
                }
            };
            xhr.onerror = function() { failure('Network error.'); };
            var fd = new FormData();
            fd.append('file', blobInfo.blob(), blobInfo.filename());
            xhr.send(fd);
        }
    });
});
</script>

<?php renderPageEnd(); ?>
