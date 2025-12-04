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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml($siteName); ?> - Shared File</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <div class="share-page">
            <h1><?php echo escapeHtml($siteName); ?></h1>
            <div class="share-info">
                <h2>Shared File</h2>
                <div class="file-details">
                    <p><strong>Filename:</strong> <?php echo escapeHtml($share['original_filename']); ?></p>
                    <p><strong>Size:</strong> <?php echo formatBytes($share['file_size']); ?></p>
                    <p><strong>Uploaded:</strong> <?php echo date('Y-m-d H:i', strtotime($share['created_at'])); ?></p>
                    
                    <?php if ($share['share_type'] === 'time'): ?>
                        <p><strong>Expires:</strong> <?php echo date('Y-m-d H:i', strtotime($share['expires_at'])); ?></p>
                    <?php else: ?>
                        <p><strong>Downloads:</strong> <?php echo $share['current_downloads']; ?> / <?php echo $share['max_downloads']; ?></p>
                    <?php endif; ?>
                </div>
                
                <a href="?token=<?php echo escapeHtml($token); ?>&download=1" class="btn btn-primary btn-large">Download File</a>
            </div>
        </div>
    </div>
</body>
</html>
