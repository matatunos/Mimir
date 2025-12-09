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

// Ensure AD/LDAP group config keys exist so admins can edit them via the UI
$defaults = [
    'ad_required_group_dn' => ['value' => '', 'type' => 'string'],
    'ad_admin_group_dn' => ['value' => '', 'type' => 'string'],
    'ldap_required_group_dn' => ['value' => '', 'type' => 'string'],
    'ldap_admin_group_dn' => ['value' => '', 'type' => 'string']
];
foreach ($defaults as $k => $v) {
    $details = $configClass->getDetails($k);
    if (!$details) {
        $configClass->set($k, $v['value'], $v['type']);
    }
}

// Add human-friendly descriptions for the new keys if missing
$db = Database::getInstance()->getConnection();
$descs = [
    'ad_required_group_dn' => 'DN del grupo de Active Directory cuyos miembros están permitidos para iniciar sesión (ej. CN=svrdoc_user,OU=Groups,DC=example,DC=com)',
    'ad_admin_group_dn' => 'DN del grupo de Active Directory cuyos miembros recibirán el rol de administrador (ej. CN=svrdoc_admin,OU=Groups,DC=example,DC=com)',
    'ldap_required_group_dn' => 'DN del grupo LDAP cuyos miembros están permitidos para iniciar sesión (opcional)',
    'ldap_admin_group_dn' => 'DN del grupo LDAP cuyos miembros recibirán el rol de administrador (opcional)'
];
foreach ($descs as $k => $d) {
    try {
        $stmt = $db->prepare("UPDATE config SET description = ? WHERE config_key = ? AND (description IS NULL OR description = '')");
        $stmt->execute([$d, $k]);
    } catch (Exception $e) {
        // ignore
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
            $im->setImageFuzz($fuzz);

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

$success = '';
$error = '';

// Load configs before POST processing (needed for type detection)
$configs = $configClass->getAllDetails();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
    } else {
        try {
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
            
            $updates = [];
            foreach ($_POST as $key => $value) {
                // Skip csrf_token, site_logo (handled separately), auto_extract_colors (not a config)
                // and color fields if they were auto-extracted
                $skipKeys = ['csrf_token', 'site_logo', 'auto_extract_colors', 'site_logo_file'];
                if (isset($colorsExtracted) && $colorsExtracted) {
                    $skipKeys = array_merge($skipKeys, ['brand_primary_color', 'brand_secondary_color', 'brand_accent_color']);
                }
                
                // Only skip if key is in skipKeys or key is empty string
                if (!in_array($key, $skipKeys) && $key !== '') {
                    // Allow empty values and zero values
                    $updates[$key] = $value;
                }
            }
            
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
    
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
        
        <?php foreach ($categories as $catKey => $category): ?>
            <?php if (!empty($category['configs'])): ?>
            <div class="card" style="margin-bottom: 2rem;">
                <div class="card-header" style="background: linear-gradient(135deg, <?php echo htmlspecialchars($brandPrimary); ?> 0%, <?php echo htmlspecialchars($brandSecondary); ?> 100%); color: white;">
                    <h3 class="card-title" style="color: white;"><?php echo $category['icon']; ?> <?php echo $category['title']; ?></h3>
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
                                        <img src="<?php echo BASE_URL . '/' . htmlspecialchars($cfg['config_value']); ?>" 
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
                                    <button type="button" onclick="document.getElementById('site_logo_file').value=''; document.getElementById('logo_preview').style.display='none';" class="btn btn-outline" style="white-space: nowrap;">
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
                                    class="btn btn-outline" 
                                    onclick="pickColorFromScreen('<?php echo htmlspecialchars($cfg['config_key']); ?>')"
                                    title="Seleccionar color de la pantalla"
                                    style="padding: 0.5rem 0.75rem; height: 40px; white-space: nowrap;">
                                    <i class="fas fa-eye-dropper"></i>
                                </button>
                                <div style="width: 40px; height: 40px; border-radius: var(--radius-md); border: 2px solid var(--border-color); background: <?php echo htmlspecialchars($cfg['config_value']); ?>;" id="preview_<?php echo htmlspecialchars($cfg['config_key']); ?>"></div>
                            </div>
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
                                <?php echo $cfg['is_system'] ? 'readonly' : ''; ?>
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
                            <input 
                                type="text" 
                                name="<?php echo htmlspecialchars($cfg['config_key']); ?>" 
                                class="form-control" 
                                value="<?php echo htmlspecialchars($cfg['config_value']); ?>"
                                <?php if ($placeholder): ?>placeholder="<?php echo htmlspecialchars($placeholder); ?>"<?php endif; ?>
                                <?php echo $cfg['is_system'] ? 'readonly' : ''; ?>
                            >
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
            <a href="<?php echo BASE_URL; ?>/admin/index.php" class="btn btn-outline" style="padding: 0.875rem 2rem; font-size: 1.0625rem; font-weight: 600;">Cancelar</a>
        </div>
    </form>
</div>

<script>
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
        .then(r => r.json())
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

<?php renderPageEnd(); ?>
