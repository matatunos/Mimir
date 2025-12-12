<?php
/**
 * Mimir File Management System
 * Shared Layout Functions
 */

// Apply security headers globally
if (file_exists(__DIR__ . '/../classes/SecurityHeaders.php')) {
    require_once __DIR__ . '/../classes/SecurityHeaders.php';
    
    // Only apply if not already applied
    if (!headers_sent()) {
        SecurityHeaders::applyAll([
            'frame' => 'SAMEORIGIN',
            'referrer' => 'strict-origin-when-cross-origin',
            'hsts' => true
        ]);
    }
}

function renderHeader($title, $user, $auth = null) {
    // Generate CSRF token - try to get auth from parameter or create new instance
    $csrfToken = '';
    if ($auth !== null) {
        $csrfToken = $auth->generateCsrfToken();
    } else {
        // Try to create auth instance to get token
        if (class_exists('Auth')) {
            $tempAuth = new Auth();
            if ($tempAuth->isLoggedIn()) {
                $csrfToken = $tempAuth->generateCsrfToken();
            }
        }
    }
    ?>
    <div class="header">
        <div class="header-left">
            <button class="mobile-menu-toggle btn btn-outline" onclick="Mimir.toggleMobileMenu()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="header-title"><?php echo htmlspecialchars($title); ?></h1>
        </div>
        <div class="header-actions">
            <div class="user-menu" onclick="toggleUserMenu(event)">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                </div>
                <div>
                    <div style="font-weight: 500;"><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></div>
                    <div style="font-size: 0.8125rem; color: var(--text-muted);">
                        <?php echo $user['role'] === 'admin' ? 'Administrador' : 'Usuario'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div id="userMenuDropdown" style="display: none; position: absolute; right: 1.5rem; top: 70px; background: white; border: 1px solid var(--border-color); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); min-width: 200px; z-index: 1000;">
        <a href="<?php echo BASE_URL; ?>/user/profile.php" style="display: block; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); text-decoration: none; color: inherit;"><i class="fas fa-user"></i> Mi Perfil</a>
        <?php if ($user['role'] === 'admin'): ?>
            <?php
            require_once __DIR__ . '/../classes/Config.php';
            $config = new Config();
            $maintenanceMode = $config->get('maintenance_mode', '0');
            $isInMaintenance = $maintenanceMode === '1';
            $globalProtection = $config->get('enable_config_protection', '0');
            ?>
            <a href="<?php echo BASE_URL; ?>/user/2fa_setup.php" style="display: block; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); text-decoration: none; color: inherit;"><i class="fas fa-lock"></i> Autenticación 2FA</a>
            <a href="#" onclick="toggleMaintenance(event, <?php echo $isInMaintenance ? 'false' : 'true'; ?>, '<?php echo $csrfToken; ?>')" style="display: block; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); text-decoration: none; color: inherit;">
                <i class="fas fa-tools"></i> 
                <?php echo $isInMaintenance ? 'Desactivar Mantenimiento' : 'Activar Mantenimiento'; ?>
            </a>
            <a href="#" onclick="toggleConfigProtection(event, <?php echo $globalProtection ? 'false' : 'true'; ?>, '<?php echo $csrfToken; ?>')" style="display: block; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); text-decoration: none; color: inherit;">
                <i class="fas fa-shield-alt"></i>
                <?php echo $globalProtection ? 'Desactivar protección de configuración' : 'Activar protección de configuración'; ?>
            </a>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>/logout.php" style="display: block; padding: 0.75rem 1rem; color: var(--danger-color); text-decoration: none;"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
    </div>
    <?php
}

function renderSidebar($currentPage, $isAdmin = false) {
    require_once __DIR__ . '/../classes/Config.php';
    $config = new Config();
    $siteName = $config->get('site_name', 'Mimir');
    $logo = $config->get('site_logo', '');
    ?>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <?php if ($logo): ?>
                    <img src="<?php echo BASE_URL . '/' . htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($siteName); ?>">
                <?php else: ?>
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path>
                        <polyline points="13 2 13 9 20 9"></polyline>
                    </svg>
                <?php endif; ?>
                <span><?php echo htmlspecialchars($siteName); ?></span>
            </div>
        </div>
        
        <div class="sidebar-menu">
            <?php if ($isAdmin): ?>
                <!-- Admin Menu -->
                <div class="menu-section">
                    <div class="menu-section-title">Panel</div>
                    <a href="<?php echo BASE_URL; ?>/admin/index.php" class="menu-item <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i> Dashboard
                    </a>
                </div>
                
                <div class="menu-section">
                    <div class="menu-section-title">Gestión</div>
                    <a href="<?php echo BASE_URL; ?>/admin/users.php" class="menu-item <?php echo $currentPage === 'users' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i> Usuarios & 2FA
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/files.php" class="menu-item <?php echo $currentPage === 'files' ? 'active' : ''; ?>">
                        <i class="fas fa-folder"></i> Archivos
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/orphan_files.php" class="menu-item <?php echo $currentPage === 'orphan_files' ? 'active' : ''; ?>">
                        <i class="fas fa-box"></i> Archivos huérfanos
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/expired_files.php" class="menu-item <?php echo $currentPage === 'expired_files' ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i> Archivos Expirados
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/shares.php" class="menu-item <?php echo $currentPage === 'shares' ? 'active' : ''; ?>">
                        <i class="fas fa-share-alt"></i> Comparticiones
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/invitations.php" class="menu-item <?php echo $currentPage === 'invitations' ? 'active' : ''; ?>">
                        <i class="fas fa-envelope-open-text"></i> Invitaciones
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/logs.php" class="menu-item <?php echo $currentPage === 'logs' ? 'active' : ''; ?>">
                        <i class="fas fa-clipboard-list"></i> Registros
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/forensic_logs.php" class="menu-item <?php echo $currentPage === 'forensic-logs' ? 'active' : ''; ?>">
                        <i class="fas fa-search"></i> Análisis Forense
                    </a>
                </div>
                
                <div class="menu-section">
                    <div class="menu-section-title">Sistema</div>
                    <a href="<?php echo BASE_URL; ?>/admin/config.php" class="menu-item <?php echo $currentPage === 'config' ? 'active' : ''; ?>">
                        <i class="fas fa-cog"></i> Configuración
                    </a>
                </div>
                
                <div class="menu-section">
                    <div class="menu-section-title">Mis Archivos</div>
                    <?php
                    // Preserve folder context in sidebar links when viewing a folder
                    $folderParam = '';
                    if (isset($_GET['folder']) && is_numeric($_GET['folder'])) {
                        $folderParam = '?folder=' . intval($_GET['folder']);
                    }
                    ?>
                    <a href="<?php echo BASE_URL; ?>/user/files.php<?php echo $folderParam; ?>" class="menu-item <?php echo (!$isAdmin && $currentPage === 'files') ? 'active' : ''; ?>" style="font-size:1.03rem;">
                            <i class="fas fa-folder-open" style="font-size:1.25rem; vertical-align:middle; margin-right:0.5rem;"></i> Ver mis archivos
                        </a>
                        <a href="<?php echo BASE_URL; ?>/user/upload.php<?php echo $folderParam; ?>" class="menu-item <?php echo (!$isAdmin && $currentPage === 'upload') ? 'active' : ''; ?>">
                            <i class="fas fa-upload"></i> Subir archivo
                        </a>
                </div>
            <?php else: ?>
                <!-- User Menu -->
                <div class="menu-section">
                    <div class="menu-section-title">Menú</div>
                    <a href="<?php echo BASE_URL; ?>/user/index.php" class="menu-item <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-home"></i> Inicio
                    </a>
                    <a href="<?php echo BASE_URL; ?>/user/files.php" class="menu-item <?php echo $currentPage === 'files' ? 'active' : ''; ?>">
                        <i class="fas fa-folder"></i> Mis Archivos
                    </a>
                    <a href="<?php echo BASE_URL; ?>/user/upload.php" class="menu-item <?php echo $currentPage === 'upload' ? 'active' : ''; ?>">
                        <i class="fas fa-cloud-upload-alt"></i> Subir Archivo
                    </a>
                    <a href="<?php echo BASE_URL; ?>/user/shares.php" class="menu-item <?php echo $currentPage === 'shares' ? 'active' : ''; ?>">
                        <i class="fas fa-link"></i> Mis Comparticiones
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <?php
        $footerLinksData = $config->get('footer_links', '[]');
        $footerLinks = is_string($footerLinksData) ? json_decode($footerLinksData, true) : $footerLinksData;
        if (!empty($footerLinks)):
        ?>
        <div class="footer">
            <div class="footer-links">
                <?php foreach ($footerLinks as $link): ?>
                    <a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank">
                        <?php echo htmlspecialchars($link['text']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="footer-copyright">
                © <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteName); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

function renderPageStart($title, $currentPage, $isAdmin = false) {
    require_once __DIR__ . '/../classes/Config.php';
    $config = new Config();
    
    // Get branding colors
    $primaryColor = $config->get('brand_primary_color', '#1e40af');
    $secondaryColor = $config->get('brand_secondary_color', '#475569');
    $accentColor = $config->get('brand_accent_color', '#0ea5e9');
    $siteName = $config->get('site_name', 'Mimir');
    
    // Calculate darker shade for primary-dark (20% darker)
    $primaryDark = adjustColorBrightness($primaryColor, -20);
    $primaryLight = adjustColorBrightness($primaryColor, 30);
    
    // Calculate appropriate text colors for buttons
    $primaryTextColor = getTextColorForBackground($primaryColor);
    $accentTextColor = getTextColorForBackground($accentColor);
    
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="<?php echo isset($GLOBALS['auth']) ? $GLOBALS['auth']->generateCsrfToken() : ''; ?>">
        <title><?php echo htmlspecialchars($title); ?> - <?php echo htmlspecialchars($siteName); ?></title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
        <style>
        :root {
            /* Override brand colors from configuration */
            --brand-primary: <?php echo htmlspecialchars($primaryColor); ?> !important;
            --brand-secondary: <?php echo htmlspecialchars($secondaryColor); ?> !important;
            --brand-accent: <?php echo htmlspecialchars($accentColor); ?> !important;
            
            /* Update dependent colors */
            --primary-color: <?php echo htmlspecialchars($primaryColor); ?> !important;
            --primary-dark: <?php echo htmlspecialchars($primaryDark); ?> !important;
            --primary-light: <?php echo htmlspecialchars($primaryLight); ?> !important;
            --secondary-color: <?php echo htmlspecialchars($secondaryColor); ?> !important;
            --accent-color: <?php echo htmlspecialchars($accentColor); ?> !important;
            --info-color: <?php echo htmlspecialchars($accentColor); ?> !important;
            --primary: <?php echo htmlspecialchars($primaryColor); ?> !important;
        }
        
        /* Ensure proper text contrast on buttons */
        .btn-primary,
        .btn.btn-primary,
        button.btn-primary {
            background: <?php echo htmlspecialchars($primaryColor); ?> !important;
            color: <?php echo htmlspecialchars($primaryTextColor); ?> !important;
        }
        
        .btn-primary:hover,
        .btn.btn-primary:hover,
        button.btn-primary:hover {
            background: <?php echo htmlspecialchars($primaryDark); ?> !important;
            color: <?php echo htmlspecialchars($primaryTextColor); ?> !important;
        }
        
        .btn-accent,
        .btn.btn-accent {
            background: <?php echo htmlspecialchars($accentColor); ?> !important;
            color: <?php echo htmlspecialchars($accentTextColor); ?> !important;
        }
        </style>
        <style>
        /* When configuration protection is active, make readonly form controls appear in medium-gray */
        .config-protected input[readonly],
        .config-protected textarea[readonly],
        .config-protected select[disabled],
        .config-protected input[disabled] {
            color: #6b6b6b !important;
            opacity: 1 !important;
        }
        /* Also dim placeholder text slightly for readonly fields */
        .config-protected input[readonly]::placeholder,
        .config-protected textarea[readonly]::placeholder {
            color: #8a8a8a !important;
        }
        /* Floating, centered protection banner */
        .config-protection-floating {
            position: fixed;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            z-index: 2200;
            background: rgba(255,255,255,0.98);
            color: #dc2626;
            border: 1px solid rgba(220,38,38,0.15);
            padding: 1rem 1.25rem;
            border-radius: 0.75rem;
            box-shadow: 0 18px 48px rgba(0,0,0,0.28);
            display: flex;
            gap: 0.75rem;
            align-items: center;
            font-size: 1.25rem;
            font-weight: 700;
            text-align: center;
            min-width: 320px;
            max-width: 90vw;
        }
        .config-protection-floating i { font-size: 1.6rem; color: #dc2626; }
        @media (max-width: 540px) {
            .config-protection-floating { font-size: 1rem; padding: 0.75rem 1rem; }
            .config-protection-floating i { font-size: 1.2rem; }
        }
        </style>
        <style>
        /* Compact-mode transition and global compact styles (applies when user enables compact view) */
        .compact-mode .users-table-compact th,
        .compact-mode .users-table-compact td {
            transition: padding 220ms ease, font-size 220ms ease, max-width 220ms ease;
        }
        .compact-mode .users-table-compact .truncate {
            transition: max-width 220ms ease;
        }
        /* Smooth transition for buttons area */
        .compact-mode .btn { transition: padding 180ms ease, font-size 180ms ease; }
        </style>
    </head>
    <body <?php echo (bool)$config->get('enable_config_protection', '0') ? 'class="config-protected"' : ''; ?>>
        <?php if ((bool)$config->get('enable_config_protection', '0')): ?>
            <div id="configProtectionFloating" class="config-protection-floating" role="status" aria-live="polite">
                <i class="fas fa-lock" aria-hidden="true"></i>
                <div>Protección de configuración: <span style="font-weight:800;">Activada</span></div>
            </div>
        <?php endif; ?>
        <div class="app-container">
            <?php renderSidebar($currentPage, $isAdmin); ?>
            <div class="main-content">
    <?php
}

/**
 * Adjust color brightness
 * @param string $hexColor Hex color string
 * @param int $percent Percentage to adjust (-100 to 100)
 * @return string Adjusted hex color
 */
function adjustColorBrightness($hexColor, $percent) {
    $hexColor = ltrim($hexColor, '#');
    
    $r = hexdec(substr($hexColor, 0, 2));
    $g = hexdec(substr($hexColor, 2, 2));
    $b = hexdec(substr($hexColor, 4, 2));
    
    $r = max(0, min(255, $r + ($r * $percent / 100)));
    $g = max(0, min(255, $g + ($g * $percent / 100)));
    $b = max(0, min(255, $b + ($b * $percent / 100)));
    
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

/**
 * Calculate relative luminance of a color (WCAG standard)
 */
function getColorLuminance($hexColor) {
    $hexColor = ltrim($hexColor, '#');
    $r = hexdec(substr($hexColor, 0, 2)) / 255;
    $g = hexdec(substr($hexColor, 2, 2)) / 255;
    $b = hexdec(substr($hexColor, 4, 2)) / 255;
    
    // Apply gamma correction
    $r = $r <= 0.03928 ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4);
    $g = $g <= 0.03928 ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4);
    $b = $b <= 0.03928 ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4);
    
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

/**
 * Calculate contrast ratio between two colors (WCAG standard)
 */
function getContrastRatio($color1, $color2) {
    $l1 = getColorLuminance($color1);
    $l2 = getColorLuminance($color2);
    
    $lighter = max($l1, $l2);
    $darker = min($l1, $l2);
    
    return ($lighter + 0.05) / ($darker + 0.05);
}

/**
 * Get appropriate text color (black or white) for a background color
 */
function getTextColorForBackground($bgColor) {
    $whiteContrast = getContrastRatio($bgColor, '#ffffff');
    $blackContrast = getContrastRatio($bgColor, '#000000');
    
    // Return white if it has better contrast, otherwise black
    return $whiteContrast > $blackContrast ? '#ffffff' : '#000000';
}

function renderPageEnd() {
    ?>
            </div>
        </div>
        <script>
        // Global compact-mode initializer and toggle handler
        (function(){
            const KEY = 'users_compact_view_v1';
            function applyCompact(enabled){
                if (enabled) document.body.classList.add('compact-mode');
                else document.body.classList.remove('compact-mode');
                // Update all toggle buttons if present
                const btns = document.querySelectorAll('#compactToggle');
                btns.forEach(b => {
                    try { b.innerHTML = '<i class="fas fa-compress"></i> Compacto: ' + (enabled ? 'On' : 'Off'); b.title = 'Alternar vista compacta (reduce padding y oculta columnas menos importantes)'; } catch(e){}
                });
            }
            try {
                const v = localStorage.getItem(KEY);
                applyCompact(v === '1');
            } catch(e){}

            document.addEventListener('click', function(e){
                const t = e.target.closest('#compactToggle');
                if (!t) return;
                try {
                    const current = document.body.classList.contains('compact-mode');
                    const next = !current;
                    localStorage.setItem(KEY, next ? '1' : '0');
                    applyCompact(next);
                } catch(e){
                    console.error('Compact toggle error', e);
                    alert('No se pudo cambiar la vista compacta.');
                }
            });
        })();
        </script>
        <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
    </body>
    </html>
    <?php
}
?>
