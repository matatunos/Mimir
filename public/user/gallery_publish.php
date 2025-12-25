<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/Share.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Logger.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$fileId = intval($_POST['file_id'] ?? 0);
if ($fileId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid file']);
    exit;
}

$fileClass = new File();
$file = $fileClass->getById($fileId);
if (!$file || $file['user_id'] != $user['id']) {
    echo json_encode(['success' => false, 'error' => 'File not found or access denied']);
    exit;
}

try {
    $shareClass = new Share();

    // If a gallery share already exists for this file by this user, reuse it
    $existing = $shareClass->findGalleryShare($fileId, $user['id']);
    if ($existing) {
        $logger = new Logger();
        $shareId = $existing['share_id'] ?? ($existing['id'] ?? null);
        $token = $existing['share_token'] ?? ($existing['token'] ?? null);
        // Log that user requested publish but reused existing gallery link
        if ($shareId) {
            $logger->log($user['id'], 'gallery_publish_reused', 'share', $shareId, "Reused existing gallery share for file: {$file['original_name']}");
        }
        $ext = pathinfo($file['file_path'] ?? $file['original_name'], PATHINFO_EXTENSION);
        $publicPath = rtrim(constant('BASE_PATH'), '/') . '/public/sfiles/' . $token . ($ext ? '.' . $ext : '');
        if (file_exists($publicPath)) {
            $url = rtrim((defined('BASE_URL') ? BASE_URL : ''), '/') . '/sfiles/' . $token . ($ext ? '.' . $ext : '');
        } else {
            $url = (defined('BASE_URL') ? BASE_URL : '') . '/s/' . $token;
        }
        echo json_encode(['success' => true, 'url' => $url, 'token' => $token, 'reused' => true]);
        exit;
    }

    // Create gallery-style share (public, no expiry, unlimited)
    $result = $shareClass->create($fileId, $user['id'], [
        'max_days' => 0,
        'max_downloads' => null,
        'password' => null
    ]);

    $token = $result['token'] ?? null;
    $url = $result['url'] ?? (defined('BASE_URL') ? BASE_URL . '/s/' . $token : '/s/' . $token);

    // Prefer public sfiles URL if present
    $ext = pathinfo($file['file_path'] ?? $file['original_name'], PATHINFO_EXTENSION);
    $publicPath = rtrim(constant('BASE_PATH'), '/') . '/public/sfiles/' . $token . ($ext ? '.' . $ext : '');
    if (file_exists($publicPath)) {
        $url = rtrim((defined('BASE_URL') ? BASE_URL : ''), '/') . '/sfiles/' . $token . ($ext ? '.' . $ext : '');
    }

    echo json_encode(['success' => true, 'url' => $url, 'token' => $token, 'reused' => false]);
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

?>
