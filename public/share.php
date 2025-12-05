<?php
require_once __DIR__ . '/../includes/init.php';

$token = $_GET['token'] ?? null;
$error = '';
$requiresPassword = false;
$passwordVerified = false;

if (!$token) {
    die('Invalid share link');
}

$shareManager = new ShareManager();
$share = $shareManager->getShareByToken($token);

if (!$share || !$shareManager->isShareValid($share)) {
    die('This share link is invalid or has expired');
}

// Check if password is required
$requiresPassword = !empty($share['requires_password']);

// Lógica de intentos y bloqueo progresivo
if (!isset($_SESSION['share_attempts'])) $_SESSION['share_attempts'] = [];
$attempts = &$_SESSION['share_attempts'][$token];
if (!isset($attempts)) {
    $attempts = ['count'=>0,'last'=>0,'block'=>0];
}
// Check if already verified in session
if ($requiresPassword && isset($_SESSION['verified_shares'][$token])) {
    $passwordVerified = true;
}
// Handle password submission
if ($requiresPassword && $_SERVER['REQUEST_METHOD'] === 'POST' && !$passwordVerified) {
    $now = time();
    $wait = 0;
    if ($attempts['count'] >= 5 && $attempts['count'] < 15) {
        $wait = 30;
    } elseif ($attempts['count'] >= 15) {
        $wait = 300;
    }
    if ($attempts['block'] > $now) {
        $error = 'Demasiados intentos. Espera '.ceil(($attempts['block']-$now)/60).' minutos antes de volver a intentarlo.';
    } else {
        $password = $_POST['password'] ?? '';
        if ($shareManager->verifySharePassword($share, $password)) {
            $passwordVerified = true;
            $_SESSION['verified_shares'][$token] = true;
            $attempts = ['count'=>0,'last'=>0,'block'=>0];
        } else {
            $attempts['count']++;
            $attempts['last'] = $now;
            if ($attempts['count'] >= 5 && $attempts['count'] < 15) {
                $attempts['block'] = $now + 30;
                $error = 'Contraseña incorrecta. Espera 30 segundos antes de volver a intentarlo.';
            } elseif ($attempts['count'] >= 15) {
                $attempts['block'] = $now + 300;
                $error = 'Demasiados intentos. Espera 5 minutos antes de volver a intentarlo.';
            } else {
                $error = 'Contraseña incorrecta';
            }
        }
    }
}

// Download file
if (isset($_GET['download'])) {
    // Verify password if required
    if ($requiresPassword && !$passwordVerified) {
        die('Password required');
    }
    
    // Increment download count
    $shareManager->incrementDownloadCount($share['id']);
    
    // Log the download
    AuditLog::log(null, 'share_downloaded', 'share', $share['id'], "Public download: {$share['original_filename']}");
    
    // Check file exists
    if (!file_exists($share['file_path'])) {
        die('File not found on server');
    }
    
    // Update file download count
    $fileManager = new FileManager();
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("UPDATE files SET download_count = download_count + 1 WHERE id = ?");
    $stmt->execute([$share['file_id']]);
    
    // Send file
    header('Content-Type: ' . $share['mime_type']);
    header('Content-Disposition: attachment; filename="' . $share['original_filename'] . '"');
    header('Content-Length: ' . $share['file_size']);
    readfile($share['file_path']);
    exit;
}

$siteName = SystemConfig::get('site_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo escapeHtml($siteName); ?> - Archivo Compartido</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1e293b;
            padding: 2rem;
        }
        
        .share-container {
            background: white;
            border-radius: 24px;
            padding: 3rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
        }
        
        .share-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .share-logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: white;
            font-size: 2.5rem;
        }
        
        .share-header h1 {
            font-size: 1.5rem;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .share-header p {
            color: #64748b;
            font-size: 0.95rem;
        }
        
        .file-preview {
            background: #f8fafc;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 2px dashed #e2e8f0;
            text-align: center;
        }
        
        .file-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 2.5rem;
        }
        
        .file-name {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1e293b;
            word-break: break-word;
            margin-bottom: 0.5rem;
        }
        
        .file-size {
            color: #64748b;
            font-size: 0.95rem;
        }
        
        .file-details {
            display: grid;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 12px;
        }
        
        .detail-icon {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
            font-size: 1.25rem;
        }
        
        .detail-content {
            flex: 1;
        }
        
        .detail-label {
            font-size: 0.8125rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }
        
        .detail-value {
            font-weight: 600;
            color: #1e293b;
        }
        
        .download-btn {
            width: 100%;
            padding: 1rem 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.125rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            transition: all 0.3s;
        }
        
        .download-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(102, 126, 234, 0.4);
        }
        
        .footer-note {
            text-align: center;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid #f1f5f9;
            color: #94a3b8;
            font-size: 0.875rem;
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 600;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .password-form {
            background: #fef3c7;
            border: 2px solid #f59e0b;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .password-form h3 {
            color: #92400e;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .password-form p {
            color: #92400e;
            margin-bottom: 1.5rem;
            font-size: 0.9375rem;
        }
        
        .password-input {
            display: flex;
            gap: 1rem;
        }
        
        .password-input input {
            flex: 1;
            padding: 0.875rem;
            border: 2px solid #f59e0b;
            border-radius: 8px;
            font-size: 1rem;
        }
        
        .password-input input:focus {
            outline: none;
            border-color: #d97706;
        }
        
        .password-input button {
            padding: 0.875rem 1.5rem;
            background: #f59e0b;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .password-input button:hover {
            background: #d97706;
        }
        
        .error-message {
            background: #fee2e2;
            border: 2px solid #ef4444;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            color: #991b1b;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .footer-links {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
        }
        
        .footer-links a {
            color: #64748b;
            text-decoration: none;
            font-size: 0.8125rem;
            padding: 0 0.75rem;
            border-right: 1px solid #cbd5e1;
        }
        
        .footer-links a:last-child {
            border-right: none;
        }
        
        .footer-links a:hover {
            color: #667eea;
        }
    </style>
</head>
<body>
    <div class="share-container">
        <div class="share-header">
            <div class="share-logo">
                <i class="fas fa-cloud"></i>
            </div>
            <h1><?php echo escapeHtml($siteName); ?></h1>
            <p>Archivo Compartido de forma Segura</p>
        </div>
        
        <?php if ($requiresPassword && !$passwordVerified): ?>
            <!-- Password Required -->
            <?php if ($error): ?>
                <div class="error-message" id="errorMsg">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo escapeHtml($error); ?>
                    <?php if ($attempts['block'] > time()): ?>
                        <span id="blockCountdown" style="margin-left:1em;font-weight:bold;"></span>
                        <script>
                        let blockEnd = <?php echo $attempts['block']; ?>;
                        function updateCountdown() {
                            let now = Math.floor(Date.now()/1000);
                            let left = blockEnd-now;
                            if (left > 0) {
                                let min = Math.floor(left/60);
                                let sec = left%60;
                                document.getElementById('blockCountdown').textContent = ` (${min>0?min+'m ':''}${sec}s restantes)`;
                                setTimeout(updateCountdown, 1000);
                            } else {
                                document.getElementById('blockCountdown').textContent = '';
                                document.getElementById('errorMsg').textContent = 'Puedes volver a intentarlo.';
                            }
                        }
                        updateCountdown();
                        </script>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="password-form">
                <h3>
                    <i class="fas fa-lock"></i>
                    Archivo Protegido
                </h3>
                <p>Este archivo está protegido con contraseña. Por favor, introduce la contraseña para acceder.</p>
                
                <form method="POST">
                    <div class="password-input">
                        <input type="password" name="password" id="passwordField" placeholder="Introduce la contraseña" required autofocus>
                        <button type="submit" id="passwordBtn">
                            <i class="fas fa-unlock"></i>
                            Verificar
                        </button>
                    </div>
                </form>
                <script>
                <?php if ($attempts['block'] > time()): ?>
                document.getElementById('passwordField').disabled = true;
                document.getElementById('passwordBtn').disabled = true;
                let blockEnd = <?php echo $attempts['block']; ?>;
                function updateCountdown() {
                    let now = Math.floor(Date.now()/1000);
                    let left = blockEnd-now;
                    if (left > 0) {
                        let min = Math.floor(left/60);
                        let sec = left%60;
                        document.getElementById('blockCountdown').textContent = ` (${min>0?min+'m ':''}${sec}s restantes)`;
                        setTimeout(updateCountdown, 1000);
                    } else {
                        document.getElementById('blockCountdown').textContent = '';
                        document.getElementById('errorMsg').textContent = 'Puedes volver a intentarlo.';
                        document.getElementById('passwordField').disabled = false;
                        document.getElementById('passwordBtn').disabled = false;
                    }
                }
                updateCountdown();
                <?php endif; ?>
                </script>
            </div>
            
            <div class="file-preview">
                <div class="file-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="file-name"><?php echo escapeHtml($share['original_filename']); ?></div>
                <div class="file-size"><?php echo formatBytes($share['file_size']); ?></div>
            </div>
        <?php else: ?>
            <!-- File Details -->
            <div class="file-preview">
                <div class="file-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="file-name"><?php echo escapeHtml($share['original_filename']); ?></div>
                <div class="file-size"><?php echo formatBytes($share['file_size']); ?></div>
            </div>
        
        <div class="file-details">
            <div class="detail-item">
                <div class="detail-icon">
                    <i class="fas fa-calendar"></i>
                </div>
                <div class="detail-content">
                    <div class="detail-label">Fecha de Subida</div>
                    <div class="detail-value"><?php echo date('d/m/Y H:i', strtotime($share['created_at'])); ?></div>
                </div>
            </div>
            
            <?php if ($share['share_type'] === 'time' && !empty($share['expires_at'])): ?>
                <div class="detail-item">
                    <div class="detail-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="detail-content">
                        <div class="detail-label">Expira el</div>
                        <div class="detail-value">
                            <?php echo date('d/m/Y H:i', strtotime($share['expires_at'])); ?>
                            <span class="badge badge-warning" style="margin-left: 0.5rem;">
                                <i class="fas fa-hourglass-half"></i>
                                <?php 
                                $now = new DateTime();
                                $expires = new DateTime($share['expires_at']);
                                $interval = $now->diff($expires);
                                if ($interval->days > 0) {
                                    echo $interval->days . ' día' . ($interval->days > 1 ? 's' : '');
                                } elseif ($interval->h > 0) {
                                    echo $interval->h . ' hora' . ($interval->h > 1 ? 's' : '');
                                } else {
                                    echo 'Pronto';
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <a href="?token=<?php echo escapeHtml($token); ?>&download=1" class="download-btn">
            <i class="fas fa-download"></i>
            Descargar Archivo
        </a>
        
        <div class="footer-note">
            <i class="fas fa-shield-alt"></i>
            Este enlace es seguro
        </div>
        <?php endif; ?>
        
        <?php 
        // Footer links
        $footerLinks = SystemConfig::get('footer_links', []);
        if (!empty($footerLinks)): 
        ?>
            <div class="footer-links">
                <?php foreach ($footerLinks as $link): ?>
                    <a href="<?php echo escapeHtml($link['url']); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo escapeHtml($link['label']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
