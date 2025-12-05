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
    <link rel="stylesheet" href="css/extracted/share.css">
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
