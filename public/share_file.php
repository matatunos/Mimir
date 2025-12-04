<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireLogin();

$userId = Auth::getUserId();
$fileId = $_GET['id'] ?? null;

if (!$fileId) {
    header('Location: dashboard.php');
    exit;
}

$fileManager = new FileManager();
$file = $fileManager->getFile($fileId, $userId);

if (!$file) {
    die('File not found');
}

$shareManager = new ShareManager();
$message = '';
$messageType = '';
$shareUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shareType = $_POST['share_type'] ?? '';
    $value = $_POST['value'] ?? '';
    
    if (empty($shareType) || empty($value)) {
        $message = 'Please fill all fields';
        $messageType = 'error';
    } else {
        try {
            $share = $shareManager->createShare($fileId, $userId, $shareType, $value);
            $shareUrl = $share['url'];
            $message = 'Share link created successfully!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Failed to create share: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

$maxShareDays = SystemConfig::get('max_share_time_days', MAX_SHARE_TIME_DAYS_DEFAULT);
$siteName = SystemConfig::get('site_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml($siteName); ?> - Compartir Archivo</title>
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
            max-width: 1400px;
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
            gap: 0.5rem;
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
            font-weight: 500;
        }
        
        .navbar-menu a:hover {
            background: #f1f5f9;
            color: #667eea;
        }
        
        .container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            font-size: 2rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .file-info {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .file-info-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .file-info-details {
            flex: 1;
        }
        
        .file-info-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        
        .file-info-size {
            color: #64748b;
            font-size: 0.875rem;
        }
        
        .share-result {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .share-result h3 {
            color: #059669;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .share-url {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .share-url input {
            flex: 1;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.875rem;
            font-family: monospace;
            background: #f8fafc;
            color: #475569;
        }
        
        .share-url input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
        }
        
        .share-result a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .share-result a:hover {
            text-decoration: underline;
        }
        
        .content-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .form-section {
            margin-bottom: 2rem;
        }
        
        .form-section h3 {
            font-size: 1.125rem;
            color: #1e293b;
            margin-bottom: 1rem;
        }
        
        .radio-group {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .radio-option {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .radio-option:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
        }
        
        .radio-option.selected {
            border-color: #667eea;
            background: #ede9fe;
        }
        
        .radio-option input[type="radio"] {
            margin-top: 0.25rem;
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .radio-option-content {
            flex: 1;
        }
        
        .radio-option-title {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .radio-option-description {
            color: #64748b;
            font-size: 0.875rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #475569;
            font-weight: 500;
        }
        
        .form-group input[type="number"] {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s;
            font-size: 0.95rem;
            text-decoration: none;
            flex: 1;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        
        .value-input-container {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1rem;
            border: 2px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-cloud"></i>
                <?php echo escapeHtml($siteName); ?>
            </div>
            <div class="navbar-menu">
                <a href="dashboard.php">
                    <i class="fas fa-folder"></i> Mis Archivos
                </a>
                <a href="shares.php">
                    <i class="fas fa-share-alt"></i> Compartidos
                </a>
                <?php if (Auth::isAdmin()): ?>
                <a href="admin_dashboard.php">
                    <i class="fas fa-shield-alt"></i> Administración
                </a>
                <?php endif; ?>
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                </a>
            </div>
        </div>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo escapeHtml($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($shareUrl): ?>
            <div class="share-result">
                <h3>
                    <i class="fas fa-check-circle"></i>
                    ¡Enlace Creado Exitosamente!
                </h3>
                <div class="share-url">
                    <input type="text" id="shareUrl" value="<?php echo escapeHtml($shareUrl); ?>" readonly onclick="this.select()">
                    <button class="btn btn-primary" onclick="copyShareUrl()">
                        <i class="fas fa-copy"></i> Copiar
                    </button>
                </div>
                <p><a href="shares.php"><i class="fas fa-list"></i> Ver todos mis enlaces</a></p>
            </div>
        <?php endif; ?>
        
        <div class="page-header">
            <h1>
                <i class="fas fa-share-nodes"></i>
                Compartir Archivo
            </h1>
            <div class="file-info">
                <div class="file-info-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="file-info-details">
                    <div class="file-info-name"><?php echo escapeHtml($file['original_filename']); ?></div>
                    <div class="file-info-size"><?php echo formatBytes($file['file_size']); ?></div>
                </div>
            </div>
        </div>
        
        <div class="content-card">
            <form method="POST">
                <div class="form-section">
                    <h3>Tipo de Enlace</h3>
                    <div class="radio-group">
                        <label class="radio-option" onclick="selectOption(this, 'time')">
                            <input type="radio" name="share_type" value="time" required onchange="toggleShareType()">
                            <div class="radio-option-content">
                                <div class="radio-option-title">
                                    <i class="fas fa-clock"></i>
                                    Basado en Tiempo
                                </div>
                                <div class="radio-option-description">
                                    El enlace expirará después de un número específico de días
                                </div>
                            </div>
                        </label>
                        <label class="radio-option" onclick="selectOption(this, 'downloads')">
                            <input type="radio" name="share_type" value="downloads" required onchange="toggleShareType()">
                            <div class="radio-option-content">
                                <div class="radio-option-title">
                                    <i class="fas fa-download"></i>
                                    Basado en Descargas
                                </div>
                                <div class="radio-option-description">
                                    El enlace expirará después de un número específico de descargas
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
                
                <div id="timeValue" style="display: none;">
                    <div class="value-input-container">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="time_days">
                                <i class="fas fa-calendar-days"></i>
                                Número de Días (máximo <?php echo $maxShareDays; ?>)
                            </label>
                            <input type="number" id="time_days" name="value" min="1" max="<?php echo $maxShareDays; ?>" placeholder="Ej: 7">
                        </div>
                    </div>
                </div>
                
                <div id="downloadValue" style="display: none;">
                    <div class="value-input-container">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="max_downloads">
                                <i class="fas fa-hashtag"></i>
                                Número Máximo de Descargas
                            </label>
                            <input type="number" id="max_downloads" name="value" min="1" placeholder="Ej: 10">
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-link"></i>
                        Crear Enlace
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    function selectOption(label, type) {
        document.querySelectorAll('.radio-option').forEach(opt => opt.classList.remove('selected'));
        label.classList.add('selected');
    }
    
    function toggleShareType() {
        const shareType = document.querySelector('input[name="share_type"]:checked').value;
        const timeValue = document.getElementById('timeValue');
        const downloadValue = document.getElementById('downloadValue');
        const timeInput = document.getElementById('time_days');
        const downloadInput = document.getElementById('max_downloads');
        
        if (shareType === 'time') {
            timeValue.style.display = 'block';
            downloadValue.style.display = 'none';
            timeInput.required = true;
            downloadInput.required = false;
            downloadInput.name = '';
            timeInput.name = 'value';
        } else {
            timeValue.style.display = 'none';
            downloadValue.style.display = 'block';
            timeInput.required = false;
            downloadInput.required = true;
            timeInput.name = '';
            downloadInput.name = 'value';
        }
    }
    
    function copyShareUrl() {
        const input = document.getElementById('shareUrl');
        const url = input.value;
        
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(() => {
                const btn = event.target.closest('button');
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> ¡Copiado!';
                btn.style.background = '#059669';
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.style.background = '';
                }, 2000);
            }).catch(() => {
                fallbackCopyToClipboard(input);
            });
        } else {
            fallbackCopyToClipboard(input);
        }
    }
    
    function fallbackCopyToClipboard(input) {
        input.select();
        try {
            document.execCommand('copy');
            alert('¡URL copiada al portapapeles!');
        } catch (err) {
            alert('Error al copiar. Por favor copia manualmente.');
        }
    }
    </script>
</body>
</html>
