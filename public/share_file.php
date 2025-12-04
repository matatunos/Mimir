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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml($siteName); ?> - Share File</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="navbar">
        <div class="navbar-brand"><?php echo escapeHtml($siteName); ?></div>
        <div class="navbar-menu">
            <a href="dashboard.php">My Files</a>
            <a href="shares.php">Shares</a>
            <?php if (Auth::isAdmin()): ?>
            <a href="admin.php">Admin</a>
            <?php endif; ?>
            <a href="logout.php">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="main-content">
            <h1>Share File</h1>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>"><?php echo escapeHtml($message); ?></div>
            <?php endif; ?>
            
            <?php if ($shareUrl): ?>
                <div class="share-result">
                    <h3>Share Link Created!</h3>
                    <div class="share-url">
                        <input type="text" id="shareUrl" value="<?php echo escapeHtml($shareUrl); ?>" readonly>
                        <button class="btn btn-primary" onclick="copyShareUrl()">Copy</button>
                    </div>
                    <p><a href="shares.php">View all shares</a></p>
                </div>
            <?php endif; ?>
            
            <div class="file-info">
                <h3>File: <?php echo escapeHtml($file['original_filename']); ?></h3>
                <p>Size: <?php echo formatBytes($file['file_size']); ?></p>
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label>Share Type</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="share_type" value="time" required onchange="toggleShareType()">
                            Time-based (expires after specified days)
                        </label>
                        <label>
                            <input type="radio" name="share_type" value="downloads" required onchange="toggleShareType()">
                            Download-based (expires after specified downloads)
                        </label>
                    </div>
                </div>
                
                <div class="form-group" id="timeValue" style="display: none;">
                    <label for="time_days">Number of Days (max <?php echo $maxShareDays; ?>)</label>
                    <input type="number" id="time_days" name="value" min="1" max="<?php echo $maxShareDays; ?>">
                </div>
                
                <div class="form-group" id="downloadValue" style="display: none;">
                    <label for="max_downloads">Maximum Downloads</label>
                    <input type="number" id="max_downloads" name="value" min="1">
                </div>
                
                <button type="submit" class="btn btn-primary">Create Share Link</button>
                <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
    
    <script src="js/script.js"></script>
    <script>
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
        
        // Modern Clipboard API with fallback
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(() => {
                alert('Share URL copied to clipboard!');
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
            alert('Share URL copied to clipboard!');
        } catch (err) {
            alert('Failed to copy. Please copy manually.');
        }
    }
    </script>
</body>
</html>
