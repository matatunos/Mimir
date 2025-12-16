<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/ForensicLogger.php';
require_once __DIR__ . '/../../classes/SecurityValidator.php';
require_once __DIR__ . '/../../classes/SecurityHeaders.php';

$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();
$fileClass = new File();
$logger = new Logger();
$forensicLogger = new ForensicLogger();
$security = SecurityValidator::getInstance();

// Do not allow search engines to index authenticated downloads
header('X-Robots-Tag: noindex, nofollow');

// Validate and sanitize file ID
$fileId = $security->validateInt($_GET['id'] ?? 0, 1, PHP_INT_MAX);

if ($fileId === false) {
    header('Location: ' . BASE_URL . '/user/files.php?error=' . urlencode('ID de archivo no válido'));
    exit;
}

try {
    $file = $fileClass->getById($fileId);
    
    if (!$file || $file['user_id'] != $user['id']) {
        throw new Exception('Archivo no encontrado');
    }
    
    // Log forensic data before download
    $downloadLogId = $forensicLogger->logDownload($fileId, null, $user['id']);
    
    $fileClass->download($fileId, $user['id']);
    $logger->log($user['id'], 'file_download', 'file', $fileId, 'Usuario descargó archivo');
    
    // Mark download as completed
    if ($downloadLogId) {
        $forensicLogger->completeDownload($downloadLogId, $file['file_size'], 200);
    }
    
} catch (Exception $e) {
    header('Location: ' . BASE_URL . '/user/files.php?error=' . urlencode($e->getMessage()));
    exit;
}
