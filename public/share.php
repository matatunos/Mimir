<?php
require_once __DIR__ . '/../includes/init.php';

$token = $_GET['token'] ?? null;

if (!$token) {
    die('Invalid share link');
}

$shareManager = new ShareManager();
$share = $shareManager->getShareByToken($token);

if (!$share || !$shareManager->isShareValid($share)) {
    die('This share link is invalid or has expired');
}

// Download file
if (isset($_GET['download'])) {
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
            <?php else: ?>
                <div class="detail-item">
                    <div class="detail-icon">
                        <i class="fas fa-download"></i>
                    </div>
                    <div class="detail-content">
                        <div class="detail-label">Descargas Restantes</div>
                        <div class="detail-value">
                            <?php echo ($share['max_downloads'] - $share['current_downloads']); ?> de <?php echo $share['max_downloads']; ?>
                            <span class="badge badge-info" style="margin-left: 0.5rem;">
                                <i class="fas fa-hashtag"></i>
                                <?php echo $share['current_downloads']; ?> usadas
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
            Este enlace es de un solo uso y seguro
        </div>
    </div>
</body>
</html>
