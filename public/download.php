<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireLogin();

$fileId = $_GET['id'] ?? null;

if (!$fileId) {
    die('File ID is required');
}

try {
    $fileManager = new FileManager();
    $fileManager->download($fileId, Auth::getUserId());
} catch (Exception $e) {
    die('Download failed: ' . escapeHtml($e->getMessage()));
}
