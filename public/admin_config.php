<?php
require_once '../includes/init.php';

// Check admin access
Auth::requireAdmin();

$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_general':
            SystemConfig::set('site_name', $_POST['site_name'] ?? 'Mimir', 'string');
            $message = 'Configuración general actualizada correctamente';
            $messageType = 'success';
            break;
            
        case 'upload_logo':
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../uploads/branding';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                $filename = 'logo_' . time() . '.' . $ext;
                $uploadPath = $uploadDir . '/' . $filename;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadPath)) {
                    SystemConfig::set('site_logo_uploaded', $filename, 'string');
                    SystemConfig::set('site_logo', '/uploads/branding/' . $filename, 'string');
                    $message = 'Logo actualizado correctamente';
                    $messageType = 'success';
                } else {
                    $message = 'Error al subir el logo';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'update_footer':
            $footerLinks = [];
            $labels = $_POST['link_label'] ?? [];
            $urls = $_POST['link_url'] ?? [];
            
            foreach ($labels as $index => $label) {
                if (!empty($label) && !empty($urls[$index])) {
                    $footerLinks[] = [
                        'label' => $label,
                        'url' => $urls[$index]
                    ];
                }
            }
            
            SystemConfig::set('footer_links', json_encode($footerLinks), 'json');
            $message = 'Enlaces del footer actualizados correctamente';
            $messageType = 'success';
            break;
            
        case 'update_ldap':
            SystemConfig::set('ldap_enabled', isset($_POST['ldap_enabled']) ? 'true' : 'false', 'boolean');
            SystemConfig::set('ldap_server', $_POST['ldap_server'] ?? '', 'string');
            SystemConfig::set('ldap_port', $_POST['ldap_port'] ?? '389', 'integer');
            SystemConfig::set('ldap_use_ssl', isset($_POST['ldap_use_ssl']) ? 'true' : 'false', 'boolean');
            SystemConfig::set('ldap_use_starttls', isset($_POST['ldap_use_starttls']) ? 'true' : 'false', 'boolean');
            SystemConfig::set('ldap_base_dn', $_POST['ldap_base_dn'] ?? '', 'string');
            SystemConfig::set('ldap_user_dn', $_POST['ldap_user_dn'] ?? '', 'string');
            SystemConfig::set('ldap_admin_dn', $_POST['ldap_admin_dn'] ?? '', 'string');
            
            if (!empty($_POST['ldap_admin_password'])) {
                SystemConfig::set('ldap_admin_password', $_POST['ldap_admin_password'], 'string');
            }
            
            SystemConfig::set('ldap_user_filter', $_POST['ldap_user_filter'] ?? '(sAMAccountName={username})', 'string');
            SystemConfig::set('ldap_username_attr', $_POST['ldap_username_attr'] ?? 'sAMAccountName', 'string');
            SystemConfig::set('ldap_email_attr', $_POST['ldap_email_attr'] ?? 'mail', 'string');
            SystemConfig::set('ldap_displayname_attr', $_POST['ldap_displayname_attr'] ?? 'displayName', 'string');
            
            SystemConfig::clearCache();
            $message = 'Configuración LDAP actualizada correctamente';
            $messageType = 'success';
            break;
            
        case 'test_ldap':
            $ldap = new LdapAuth();
            $result = $ldap->testConnection();
            $message = $result['message'];
            $messageType = $result['success'] ? 'success' : 'error';
            break;
            
        case 'update_security':
            SystemConfig::set('enable_password_shares', isset($_POST['enable_password_shares']) ? 'true' : 'false', 'boolean');
            SystemConfig::set('max_share_time_days', $_POST['max_share_time_days'] ?? '30', 'integer');
            $message = 'Configuración de seguridad actualizada';
            $messageType = 'success';
            break;
            
        case 'update_email':
            SystemConfig::set('enable_email_notifications', isset($_POST['enable_email_notifications']) ? 'true' : 'false', 'boolean');
            SystemConfig::set('smtp_host', $_POST['smtp_host'] ?? '', 'string');
            SystemConfig::set('smtp_port', $_POST['smtp_port'] ?? '587', 'integer');
            SystemConfig::set('smtp_username', $_POST['smtp_username'] ?? '', 'string');
            
            if (!empty($_POST['smtp_password'])) {
                SystemConfig::set('smtp_password', $_POST['smtp_password'], 'string');
            }
            
            SystemConfig::set('smtp_from_email', $_POST['smtp_from_email'] ?? 'noreply@mimir.local', 'string');
            SystemConfig::set('smtp_from_name', $_POST['smtp_from_name'] ?? 'Mimir Storage', 'string');
            
            $message = 'Configuración de email actualizada correctamente';
            $messageType = 'success';
            break;
    }
}

// Get current config
$siteName = SystemConfig::get('site_name', APP_NAME);
$siteLogo = SystemConfig::get('site_logo', '');
$footerLinks = SystemConfig::get('footer_links', []);

// LDAP config
$ldapEnabled = SystemConfig::get('ldap_enabled', false);
$ldapServer = SystemConfig::get('ldap_server', '');
$ldapPort = SystemConfig::get('ldap_port', 389);
$ldapUseSsl = SystemConfig::get('ldap_use_ssl', false);
$ldapUseStartTls = SystemConfig::get('ldap_use_starttls', false);
$ldapBaseDn = SystemConfig::get('ldap_base_dn', '');
$ldapUserDn = SystemConfig::get('ldap_user_dn', '');
$ldapAdminDn = SystemConfig::get('ldap_admin_dn', '');
$ldapUserFilter = SystemConfig::get('ldap_user_filter', '(sAMAccountName={username})');
$ldapUsernameAttr = SystemConfig::get('ldap_username_attr', 'sAMAccountName');
$ldapEmailAttr = SystemConfig::get('ldap_email_attr', 'mail');
$ldapDisplaynameAttr = SystemConfig::get('ldap_displayname_attr', 'displayName');

// Security config
$enablePasswordShares = SystemConfig::get('enable_password_shares', true);
$maxShareDays = SystemConfig::get('max_share_time_days', 30);

// Email config
$emailEnabled = SystemConfig::get('enable_email_notifications', false);
$smtpHost = SystemConfig::get('smtp_host', '');
$smtpPort = SystemConfig::get('smtp_port', 587);
$smtpUsername = SystemConfig::get('smtp_username', '');
$smtpFromEmail = SystemConfig::get('smtp_from_email', 'noreply@mimir.local');
$smtpFromName = SystemConfig::get('smtp_from_name', 'Mimir Storage');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración del Sistema - <?php echo escapeHtml($siteName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #1e293b;
        }
        
        .navbar {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1rem 0;
        }
        
        .navbar-content {
            max-width: 1600px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .navbar-brand {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
        }
        
        .navbar-menu {
            display: flex;
            gap: 1rem;
        }
        
        .navbar-menu a {
            color: #64748b;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .navbar-menu a:hover {
            background: #f1f5f9;
            color: #667eea;
        }
        
        .navbar-menu a.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            font-size: 2rem;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .page-header p {
            color: #64748b;
        }
        
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .tabs {
            background: white;
            border-radius: 16px 16px 0 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            display: flex;
            overflow-x: auto;
        }
        
        .tab {
            padding: 1rem 2rem;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #64748b;
            font-weight: 500;
        }
        
        .tab:hover {
            background: #f8fafc;
            color: #667eea;
        }
        
        .tab.active {
            border-bottom-color: #667eea;
            color: #667eea;
            background: #f8fafc;
        }
        
        .tab-content {
            display: none;
            background: white;
            border-radius: 0 0 16px 16px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .tab-content.active {
            display: block;
        }
        
        .section {
            margin-bottom: 3rem;
        }
        
        .section:last-child {
            margin-bottom: 0;
        }
        
        .section-header {
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .section-header h2 {
            font-size: 1.25rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .section-header p {
            color: #64748b;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #475569;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9375rem;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-group-checkbox {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .form-group-checkbox input {
            width: auto;
        }
        
        .form-hint {
            font-size: 0.8125rem;
            color: #64748b;
            margin-top: 0.5rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #f1f5f9;
            color: #64748b;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
        }
        
        .logo-preview {
            max-width: 300px;
            max-height: 150px;
            margin-top: 1rem;
            border: 2px dashed #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .logo-preview img {
            max-width: 100%;
            max-height: 100px;
        }
        
        .footer-link-item {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            align-items: center;
        }
        
        .footer-link-item input {
            flex: 1;
        }
        
        .footer-link-item button {
            background: #fee2e2;
            color: #991b1b;
            border: none;
            padding: 0.75rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .footer-link-item button:hover {
            background: #fecaca;
        }
        
        .info-box {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .info-box strong {
            display: block;
            margin-bottom: 0.5rem;
            color: #1e40af;
        }
        
        .info-box ul {
            margin-left: 1.5rem;
            color: #1e40af;
        }
        
        .warning-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .warning-box strong {
            display: block;
            margin-bottom: 0.5rem;
            color: #92400e;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="navbar-content">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-cloud"></i>
                <?php echo escapeHtml($siteName); ?>
            </a>
            <div class="navbar-menu">
                <a href="dashboard.php">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="admin_dashboard.php">
                    <i class="fas fa-chart-line"></i> Panel Admin
                </a>
                <a href="admin_users.php">
                    <i class="fas fa-users"></i> Usuarios
                </a>
                <a href="admin_files.php">
                    <i class="fas fa-file-alt"></i> Archivos
                </a>
                <a href="admin_config.php" class="active">
                    <i class="fas fa-cog"></i> Configuración
                </a>
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                </a>
            </div>
        </div>
    </div>
    
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-cogs"></i> Configuración del Sistema</h1>
            <p>Personaliza y configura tu plataforma de almacenamiento</p>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo escapeHtml($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="tabs">
            <div class="tab active" onclick="switchTab('general')">
                <i class="fas fa-globe"></i> General
            </div>
            <div class="tab" onclick="switchTab('branding')">
                <i class="fas fa-palette"></i> Personalización
            </div>
            <div class="tab" onclick="switchTab('email')">
                <i class="fas fa-envelope"></i> Email/SMTP
            </div>
            <div class="tab" onclick="switchTab('ldap')">
                <i class="fas fa-network-wired"></i> Active Directory
            </div>
            <div class="tab" onclick="switchTab('security')">
                <i class="fas fa-shield-alt"></i> Seguridad
            </div>
        </div>
        
        <!-- General Settings -->
        <div id="tab-general" class="tab-content active">
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-info-circle"></i> Información del Sitio</h2>
                    <p>Configuración básica de la plataforma</p>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="update_general">
                    
                    <div class="form-group">
                        <label>Nombre del Sitio</label>
                        <input type="text" name="site_name" value="<?php echo escapeHtml($siteName); ?>" required>
                        <div class="form-hint">Este nombre aparecerá en toda la plataforma</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Cambios
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Branding Settings -->
        <div id="tab-branding" class="tab-content">
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-image"></i> Logotipo</h2>
                    <p>Personaliza el logo de tu plataforma</p>
                </div>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_logo">
                    
                    <?php if ($siteLogo): ?>
                        <div class="logo-preview">
                            <img src="<?php echo escapeHtml($siteLogo); ?>" alt="Logo actual">
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label>Subir Nuevo Logo</label>
                        <input type="file" name="logo" accept="image/*">
                        <div class="form-hint">Formatos: PNG, JPG, SVG. Tamaño recomendado: 300x100px</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Subir Logo
                    </button>
                </form>
            </div>
            
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-link"></i> Enlaces del Footer</h2>
                    <p>Configura los enlaces legales que aparecerán en el pie de página</p>
                </div>
                
                <form method="POST" id="footerForm">
                    <input type="hidden" name="action" value="update_footer">
                    
                    <div id="footerLinks">
                        <?php if (!empty($footerLinks)): ?>
                            <?php foreach ($footerLinks as $index => $link): ?>
                                <div class="footer-link-item">
                                    <input type="text" name="link_label[]" placeholder="Texto del enlace" value="<?php echo escapeHtml($link['label']); ?>" required>
                                    <input type="url" name="link_url[]" placeholder="https://..." value="<?php echo escapeHtml($link['url']); ?>" required>
                                    <button type="button" onclick="removeFooterLink(this)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="footer-link-item">
                                <input type="text" name="link_label[]" placeholder="Ej: Política de Privacidad">
                                <input type="url" name="link_url[]" placeholder="https://...">
                                <button type="button" onclick="removeFooterLink(this)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <button type="button" class="btn btn-secondary" onclick="addFooterLink()" style="margin-bottom: 1rem;">
                        <i class="fas fa-plus"></i> Añadir Enlace
                    </button>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Enlaces
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Email Settings -->
        <div id="tab-email" class="tab-content">
            <div class="info-box">
                <strong><i class="fas fa-info-circle"></i> Notificaciones por Email</strong>
                Configura el servidor SMTP para enviar notificaciones automáticas al compartir archivos. Los emails incluirán:
                <ul>
                    <li>Enlace de descarga directo</li>
                    <li>Información de expiración</li>
                    <li>Contraseña de acceso (si aplica)</li>
                    <li>Copia automática al propietario</li>
                </ul>
            </div>
            
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-envelope"></i> Configuración SMTP</h2>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="update_email">
                    
                    <div class="form-group-checkbox">
                        <input type="checkbox" name="enable_email_notifications" id="emailEnabled" <?php echo $emailEnabled ? 'checked' : ''; ?>>
                        <label for="emailEnabled">Habilitar Notificaciones por Email</label>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Servidor SMTP</label>
                            <input type="text" name="smtp_host" value="<?php echo escapeHtml($smtpHost); ?>" placeholder="smtp.gmail.com">
                            <div class="form-hint">Hostname del servidor SMTP</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Puerto SMTP</label>
                            <input type="number" name="smtp_port" value="<?php echo $smtpPort; ?>">
                            <div class="form-hint">587 (TLS) o 465 (SSL)</div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Usuario SMTP</label>
                            <input type="text" name="smtp_username" value="<?php echo escapeHtml($smtpUsername); ?>" placeholder="usuario@dominio.com">
                            <div class="form-hint">Usuario para autenticación SMTP</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Contraseña SMTP</label>
                            <input type="password" name="smtp_password" placeholder="••••••••">
                            <div class="form-hint">Dejar vacío para no cambiar</div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email Remitente</label>
                            <input type="email" name="smtp_from_email" value="<?php echo escapeHtml($smtpFromEmail); ?>" placeholder="noreply@tudominio.com">
                            <div class="form-hint">Dirección que aparecerá como remitente</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Nombre Remitente</label>
                            <input type="text" name="smtp_from_name" value="<?php echo escapeHtml($smtpFromName); ?>" placeholder="Mimir Storage">
                            <div class="form-hint">Nombre que aparecerá como remitente</div>
                        </div>
                    </div>
                    
                    <div class="warning-box">
                        <strong>⚠️ Importante:</strong>
                        Para Gmail, necesitas usar una "Contraseña de aplicación" en lugar de tu contraseña normal. 
                        Para otros proveedores, consulta su documentación sobre SMTP.
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Configuración Email
                    </button>
                </form>
            </div>
        </div>
        
        <!-- LDAP Settings -->
        <div id="tab-ldap" class="tab-content">
            <div class="info-box">
                <strong><i class="fas fa-info-circle"></i> Integración con Active Directory / LDAP</strong>
                Permite que los usuarios se autentiquen usando sus credenciales corporativas. Esta integración es compatible con:
                <ul>
                    <li>Microsoft Active Directory</li>
                    <li>OpenLDAP</li>
                    <li>FreeIPA</li>
                    <li>Otros servidores LDAP compatibles</li>
                </ul>
            </div>
            
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-network-wired"></i> Configuración LDAP/AD</h2>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="update_ldap">
                    
                    <div class="form-group-checkbox">
                        <input type="checkbox" name="ldap_enabled" id="ldapEnabled" <?php echo $ldapEnabled ? 'checked' : ''; ?>>
                        <label for="ldapEnabled">Habilitar Autenticación LDAP/Active Directory</label>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Servidor LDAP/AD</label>
                            <input type="text" name="ldap_server" value="<?php echo escapeHtml($ldapServer); ?>" placeholder="192.168.1.254">
                            <div class="form-hint">IP o hostname del servidor Active Directory</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Puerto</label>
                            <input type="number" name="ldap_port" value="<?php echo $ldapPort; ?>">
                            <div class="form-hint">389 para LDAP, 636 para LDAPS</div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group-checkbox">
                            <input type="checkbox" name="ldap_use_ssl" id="ldapUseSsl" <?php echo $ldapUseSsl ? 'checked' : ''; ?>>
                            <label for="ldapUseSsl">Usar SSL (LDAPS puerto 636)</label>
                        </div>
                        
                        <div class="form-group-checkbox">
                            <input type="checkbox" name="ldap_use_starttls" id="ldapUseStartTls" <?php echo $ldapUseStartTls ? 'checked' : ''; ?>>
                            <label for="ldapUseStartTls">Usar StartTLS (cifrar puerto 389)</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Base DN</label>
                        <input type="text" name="ldap_base_dn" value="<?php echo escapeHtml($ldapBaseDn); ?>" placeholder="DC=favala,DC=es">
                        <div class="form-hint">Distinguished Name base. Ejemplo: DC=favala,DC=es</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Patrón User DN (RECOMENDADO para Active Directory sin admin)</label>
                        <input type="text" name="ldap_user_dn" value="<?php echo escapeHtml($ldapUserDn); ?>" placeholder="CN={username},CN=Users,DC=favala,DC=es">
                        <div class="form-hint"><strong>Patrón del DN completo.</strong> {username} se reemplaza por el nombre de usuario.<br>
                        Ejemplo: CN={username},CN=Users,DC=favala,DC=es<br>
                        Si usas esto, NO necesitas DN Admin ni Password Admin.</div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>DN Admin (Solo si falla la búsqueda)</label>
                            <input type="text" name="ldap_admin_dn" value="<?php echo escapeHtml($ldapAdminDn); ?>" placeholder="CN=admin,CN=Users,DC=favala,DC=es">
                            <div class="form-hint">Dejar vacío si usas Patrón User DN arriba</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Password Admin</label>
                            <input type="password" name="ldap_admin_password" placeholder="••••••••">
                            <div class="form-hint">Dejar vacío para no cambiar</div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Filtro de Búsqueda de Usuarios</label>
                        <input type="text" name="ldap_user_filter" value="<?php echo escapeHtml($ldapUserFilter); ?>" placeholder="(sAMAccountName={username})">
                        <div class="form-hint">{username} será reemplazado. Para Active Directory: (sAMAccountName={username})</div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Atributo Username</label>
                            <input type="text" name="ldap_username_attr" value="<?php echo escapeHtml($ldapUsernameAttr); ?>" placeholder="sAMAccountName">
                            <div class="form-hint">Para AD: sAMAccountName</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Atributo Email</label>
                            <input type="text" name="ldap_email_attr" value="<?php echo escapeHtml($ldapEmailAttr); ?>" placeholder="mail">
                            <div class="form-hint">Para AD: mail</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Atributo Nombre Completo</label>
                            <input type="text" name="ldap_displayname_attr" value="<?php echo escapeHtml($ldapDisplaynameAttr); ?>" placeholder="displayName">
                            <div class="form-hint">Para AD: displayName</div>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Guardar Configuración LDAP
                        </button>
                        
                        <button type="submit" name="action" value="test_ldap" class="btn btn-success">
                            <i class="fas fa-check-circle"></i> Probar Conexión
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Security Settings -->
        <div id="tab-security" class="tab-content">
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-lock"></i> Seguridad de Compartir Archivos</h2>
                    <p>Configura opciones de seguridad para enlaces compartidos</p>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="update_security">
                    
                    <div class="form-group-checkbox">
                        <input type="checkbox" name="enable_password_shares" id="enablePasswordShares" <?php echo $enablePasswordShares ? 'checked' : ''; ?>>
                        <label for="enablePasswordShares">Permitir protección con contraseña en enlaces compartidos</label>
                    </div>
                    <div class="form-hint" style="margin-bottom: 1.5rem;">
                        Los usuarios podrán añadir una contraseña opcional al compartir archivos
                    </div>
                    
                    <div class="form-group">
                        <label>Tiempo máximo para enlaces compartidos (días)</label>
                        <input type="number" name="max_share_time_days" value="<?php echo $maxShareDays; ?>" min="1" max="365">
                        <div class="form-hint">Los usuarios no podrán crear enlaces que duren más de este tiempo</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Configuración
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            // Show selected tab
            event.target.closest('.tab').classList.add('active');
            document.getElementById('tab-' + tabName).classList.add('active');
        }
        
        function addFooterLink() {
            const container = document.getElementById('footerLinks');
            const newLink = document.createElement('div');
            newLink.className = 'footer-link-item';
            newLink.innerHTML = `
                <input type="text" name="link_label[]" placeholder="Texto del enlace" required>
                <input type="url" name="link_url[]" placeholder="https://..." required>
                <button type="button" onclick="removeFooterLink(this)">
                    <i class="fas fa-trash"></i>
                </button>
            `;
            container.appendChild(newLink);
        }
        
        function removeFooterLink(button) {
            const container = document.getElementById('footerLinks');
            if (container.children.length > 1) {
                button.closest('.footer-link-item').remove();
            } else {
                alert('Debe haber al menos un enlace');
            }
        }
    </script>
</body>
</html>
