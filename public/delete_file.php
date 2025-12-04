<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireLogin();

$fileId = $_GET['id'] ?? null;

if (!$fileId) {
    header('Location: dashboard.php');
    exit;
}

try {
    $fileManager = new FileManager();
    $fileManager->delete($fileId, Auth::getUserId());
    header('Location: dashboard.php?msg=File deleted successfully');
} catch (Exception $e) {
    header('Location: dashboard.php?error=' . urlencode($e->getMessage()));
}
exit;
