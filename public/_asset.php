<?php
declare(strict_types=1);

// Simple, secure asset proxy for serving files from the public directory when direct access is blocked.
// Usage: /_asset.php?f=uploads/branding/logo.png

require_once __DIR__ . '/../includes/config.php';

$f = $_GET['f'] ?? '';
if (!$f) {
    http_response_code(400);
    echo 'Missing file';
    exit;
}

// Only allow files under `uploads` or `assets`
if (!preg_match('#^(uploads|assets)/[A-Za-z0-9_\-./]+$#', $f)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$base = realpath(__DIR__ . '/../public');
$path = realpath($base . '/' . $f);
if (!$path || strpos($path, $base) !== 0) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$ext = pathinfo($path, PATHINFO_EXTENSION);
$mime = 'application/octet-stream';
switch (strtolower($ext)) {
    case 'png': $mime = 'image/png'; break;
    case 'jpg': case 'jpeg': $mime = 'image/jpeg'; break;
    case 'gif': $mime = 'image/gif'; break;
    case 'svg': $mime = 'image/svg+xml'; break;
    case 'css': $mime = 'text/css'; break;
    case 'js': $mime = 'application/javascript'; break;
}

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
readfile($path);
