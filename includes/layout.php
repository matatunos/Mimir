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

// Load translations helper so layout strings can use t()
if (file_exists(__DIR__ . '/lang.php')) {
    require_once __DIR__ . '/lang.php';
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
        <div class="header-right" style="position:relative;">
            <button id="userMenuButton" class="btn btn-ghost user-menu" onclick="Mimir.toggleUserMenu(event)">
                <span class="user-avatar" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <circle cx="12" cy="8" r="3.2" fill="currentColor" />
                        <path d="M4 20c0-3.3137 2.6863-6 6-6h4c3.3137 0 6 2.6863 6 6" stroke="none" fill="currentColor" opacity="0.95" />
                    </svg>
                </span>
                <span class="sr-only"><?php echo htmlspecialchars(t('open_user_menu')); ?></span>
            </button>
            <div id="userMenuDropdown" class="user-dropdown" style="display:none; position: absolute; right: 0.5rem; top: calc(var(--header-height) + 8px); background: white; border: 1px solid var(--border-color); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); min-width: 220px; z-index: 1200;">
                <a href="<?php echo BASE_URL; ?>/user/profile.php" style="display: block; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); text-decoration: none; color: inherit;"><i class="fas fa-user"></i> <?php echo htmlspecialchars(t('user_profile')); ?></a>
                <?php if ($user['role'] === 'admin'): ?>
                    <?php
                    // Prefer a shared Config instance when available to avoid cache inconsistency
                    require_once __DIR__ . '/../classes/Config.php';
                    $config = $GLOBALS['config_instance'] ?? new Config();
                    $maintenanceMode = $config->get('maintenance_mode', '0');
                    $isInMaintenance = $maintenanceMode === '1';
                    $globalProtection = (bool)$config->get('enable_config_protection', '0');
                    ?>
                    <a href="<?php echo BASE_URL; ?>/user/2fa_setup.php" style="display: block; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); text-decoration: none; color: inherit;"><i class="fas fa-lock"></i> <?php echo htmlspecialchars(t('twofa_setup')); ?></a>
                    <?php
                    // Show config protection toggle in the user dropdown for admins
                    $globalProtection = (bool)$config->get('enable_config_protection', '0');
                    $protectionEnabled = (bool)$globalProtection;
                    $toggleLabel = $protectionEnabled ? t('disable_protection') : t('enable_protection');
                    $toggleJsFlag = $protectionEnabled ? 'false' : 'true';
                    ?>
                    <a href="#" onclick="toggleConfigProtection(event, <?php echo $toggleJsFlag; ?>, '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>')" style="display: block; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); text-decoration: none; color: inherit;"><i class="fas fa-shield-alt"></i> <?php echo $toggleLabel; ?></a>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>/logout.php" style="display: block; padding: 0.75rem 1rem; color: var(--danger-color); text-decoration: none;"><i class="fas fa-sign-out-alt"></i> <?php echo htmlspecialchars(t('logout')); ?></a>
            </div>
        </div>
    </div>
    <?php
}

function renderSidebar($currentPage, $isAdmin = false) {
    // Use shared Config instance when present (pages can set $GLOBALS['config_instance'])
    require_once __DIR__ . '/../classes/Config.php';
    $config = $GLOBALS['config_instance'] ?? new Config();
    $siteName = $config->get('site_name', 'Mimir');
    $logo = $config->get('site_logo', '');
    ?>
    <div class="sidebar">
        <div class="sidebar-header">
                <div class="logo">
                    <?php if ($logo): ?>
                        <img src="<?php echo BASE_URL . '/_asset.php?f=' . urlencode($logo); ?>" alt="<?php echo htmlspecialchars($siteName); ?>">
                        <span class="sidebar-brand-text"><?php echo htmlspecialchars($siteName); ?></span>
                    <?php else: ?>
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path>
                            <polyline points="13 2 13 9 20 9"></polyline>
                        </svg>
                        <span><?php echo htmlspecialchars($siteName); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        
        <div class="sidebar-menu">
            <?php if ($isAdmin): ?>
                <!-- Admin Menu -->
                <div class="menu-section">
                    <div class="menu-section-title"><?php echo htmlspecialchars(t('menu_panel')); ?></div>
                    <a href="<?php echo BASE_URL; ?>/admin/index.php" class="menu-item <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i> <?php echo htmlspecialchars(t('dashboard')); ?>
                    </a>
                </div>
                
                <div class="menu-section">
                    <div class="menu-section-title"><?php echo htmlspecialchars(t('management')); ?></div>
                    <a href="<?php echo BASE_URL; ?>/admin/users.php" class="menu-item <?php echo $currentPage === 'users' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i> <?php echo htmlspecialchars(t('users_2fa')); ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/files.php" class="menu-item <?php echo $currentPage === 'files' ? 'active' : ''; ?>">
                        <i class="fas fa-folder"></i> <?php echo htmlspecialchars(t('files')); ?>
                    </a>
                    <!-- Almacenamiento removed from main admin menu to avoid duplicate active state -->
                    <a href="<?php echo BASE_URL; ?>/admin/orphan_files.php" class="menu-item <?php echo $currentPage === 'orphan_files' ? 'active' : ''; ?>">
                        <i class="fas fa-box"></i> <?php echo htmlspecialchars(t('orphan_files')); ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/expired_files.php" class="menu-item <?php echo $currentPage === 'expired_files' ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i> <?php echo htmlspecialchars(t('expired_files')); ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/shares.php" class="menu-item <?php echo $currentPage === 'shares' ? 'active' : ''; ?>">
                        <i class="fas fa-share-alt"></i> <?php echo htmlspecialchars(t('shares')); ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/invitations.php" class="menu-item <?php echo $currentPage === 'invitations' ? 'active' : ''; ?>">
                        <i class="fas fa-envelope-open-text"></i> <?php echo htmlspecialchars(t('invitations')); ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/logs.php" class="menu-item <?php echo $currentPage === 'logs' ? 'active' : ''; ?>">
                        <i class="fas fa-clipboard-list"></i> <?php echo htmlspecialchars(t('logs')); ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/operations.php" class="menu-item <?php echo $currentPage === 'operations' ? 'active' : ''; ?>">
                        <i class="fas fa-tools"></i> <?php echo htmlspecialchars(t('operations')); ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/forensic_logs.php" class="menu-item <?php echo $currentPage === 'forensic-logs' ? 'active' : ''; ?>">
                        <i class="fas fa-search"></i> <?php echo htmlspecialchars(t('forensic_analysis')); ?>
                    </a>
                </div>
                
                <div class="menu-section">
                    <!-- 'Sistema' section title removed per request -->
                    <a href="<?php echo BASE_URL; ?>/admin/config.php" class="menu-item <?php echo $currentPage === 'config' ? 'active' : ''; ?>">
                        <i class="fas fa-cog"></i> <?php echo htmlspecialchars(t('configuration')); ?>
                    </a>
                </div>
                
                <div class="menu-section">
                    <div class="menu-section-title"><?php echo htmlspecialchars(t('my_files_section')); ?></div>
                    <?php
                    // Preserve folder context in sidebar links when viewing a folder
                    $folderParam = '';
                    if (isset($_GET['folder']) && is_numeric($_GET['folder'])) {
                        $folderParam = '?folder=' . intval($_GET['folder']);
                    }
                    ?>
                    <a href="<?php echo BASE_URL; ?>/user/files.php<?php echo $folderParam; ?>" class="menu-item <?php echo (!$isAdmin && $currentPage === 'files') ? 'active' : ''; ?>" style="font-size:1.03rem;">
                            <i class="fas fa-folder-open" style="font-size:1.25rem; vertical-align:middle; margin-right:0.5rem;"></i> <?php echo htmlspecialchars(t('view_my_files')); ?>
                        </a>
                        <a href="<?php echo BASE_URL; ?>/user/upload.php<?php echo $folderParam; ?>" class="menu-item <?php echo (!$isAdmin && $currentPage === 'upload') ? 'active' : ''; ?>">
                            <i class="fas fa-upload"></i> <?php echo htmlspecialchars(t('upload_file')); ?>
                        </a>
                </div>
            <?php else: ?>
                <!-- User Menu -->
                <div class="menu-section">
                    <div class="menu-section-title"><?php echo htmlspecialchars(t('menu_title')); ?></div>
                    <a href="<?php echo BASE_URL; ?>/user/index.php" class="menu-item <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-home"></i> <?php echo htmlspecialchars(t('home')); ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/user/files.php" class="menu-item <?php echo $currentPage === 'files' ? 'active' : ''; ?>">
                        <i class="fas fa-folder"></i> <?php echo htmlspecialchars(t('my_files')); ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/user/upload.php" class="menu-item <?php echo $currentPage === 'upload' ? 'active' : ''; ?>">
                        <i class="fas fa-cloud-upload-alt"></i> <?php echo htmlspecialchars(t('upload_file')); ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/user/shares.php" class="menu-item <?php echo $currentPage === 'shares' ? 'active' : ''; ?>">
                        <i class="fas fa-link"></i> <?php echo htmlspecialchars(t('my_shares')); ?>
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
            z-index: 99999;
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
        /* Shared admin stat card styles (used by dashboard and operations) */
        .admin-stat-card {
            background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            padding: 1.25rem;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .admin-dashboard .card-header {
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
            color: white;
        }
        .admin-stat-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 16px 32px rgba(0,0,0,0.10);
        }
        .admin-stat-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #4a90e2, #50c878, #ffa500, #9b59b6);
            background-size: 200% 100%;
            animation: mimic-shimmer 3s infinite;
        }
        @keyframes mimic-shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        .admin-stat-icon { font-size: 3.5rem; position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); opacity: 0.08; }
        .admin-stat-card:hover .admin-stat-icon { opacity: 0.15; transform: translateY(-50%) scale(1.12) rotate(5deg); }
        .admin-stat-value { font-size: 2.2rem; font-weight: 800; background: linear-gradient(135deg, rgba(74,144,226,0.95) 0%, rgba(80,200,120,0.9) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; line-height: 1; margin-bottom: 0.25rem; }
        .admin-stat-label { font-size: 0.9375rem; color: var(--text-main); font-weight: 600; margin-bottom: 0.25rem; }
        .admin-stat-sublabel { font-size: 0.8125rem; color: var(--text-muted); font-weight: 500; }
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
        <?php if ((bool)$config->get('enable_config_protection', '0') && $isAdmin && $currentPage === 'config'): ?>
            <div id="configProtectionFloating" class="config-protection-floating" role="status" aria-live="polite">
                <i class="fas fa-lock" aria-hidden="true"></i>
                <div><?php echo t('config_protection_active_html'); ?></div>
            </div>
        <?php endif; ?>
        <div class="app-container">
            <?php renderSidebar($currentPage, $isAdmin); ?>
            <div class="main-content">
    <script>
    // Minimal UI helpers for toggling menus
    window.Mimir = window.Mimir || {};
    Mimir.toggleUserMenu = function(event) {
        var d = document.getElementById('userMenuDropdown');
        if (!d) return;
        if (d.style.display === 'block') {
            d.style.display = 'none';
            document.removeEventListener('click', Mimir._userMenuOutsideHandler);
        } else {
            d.style.display = 'block';
            // close when clicking outside
            Mimir._userMenuOutsideHandler = function(ev){ if (!d.contains(ev.target) && ev.target.id !== 'userMenuButton') { d.style.display='none'; document.removeEventListener('click', Mimir._userMenuOutsideHandler); } };
            setTimeout(function(){ document.addEventListener('click', Mimir._userMenuOutsideHandler); }, 10);
        }
        if (event) event.stopPropagation();
    };
    Mimir.toggleMobileMenu = function() {
        document.body.classList.toggle('mobile-menu-open');
    };
    </script>
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
