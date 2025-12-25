<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/SecurityHeaders.php';

$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();

$fileId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($fileId <= 0) {
    http_response_code(400);
    echo 'Invalid id';
    exit;
}

$fileClass = new File();
$file = $fileClass->getById($fileId);
if (!$file || $file['user_id'] != $user['id']) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

// Only allow image types for inline preview
if (stripos($file['mime_type'], 'image/') !== 0) {
    http_response_code(415);
    echo 'Unsupported';
    exit;
}

$realPath = realpath($file['file_path']);
$realUploadsPath = realpath(UPLOADS_PATH);
if ($realPath === false || strpos($realPath, $realUploadsPath) !== 0) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

// Optionally support a thumbnail parameter (same file for now)
$thumb = isset($_GET['thumb']) ? 1 : 0;

SecurityHeaders::setContentSecurityPolicy(['img-src' => ["'self'", 'data:', 'https:', 'blob:']]);
header('Content-Type: ' . $file['mime_type']);
header('Cache-Control: public, max-age=86400');
readfile($realPath);
exit;

?>
