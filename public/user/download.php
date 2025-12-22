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
    header('Location: ' . BASE_URL . '/user/files.php?error=' . urlencode(t('error_invalid_file_id')));
    exit;
}

try {
    $file = $fileClass->getById($fileId);
    
    if (!$file || $file['user_id'] != $user['id']) {
        throw new Exception(t('error_file_not_found'));
    }
    
    // Log forensic data before download
    $downloadLogId = $forensicLogger->logDownload($fileId, null, $user['id']);
    
    $result = $fileClass->download($fileId, $user['id']);

    // If download returned false, handle failure (download() exits on success)
    if ($result === false) {
        if ($downloadLogId) {
            $forensicLogger->completeDownload($downloadLogId, 0, 500, t('download_failed_check_logs'));
        }
        header('Location: ' . BASE_URL . '/user/files.php?error=' . urlencode(t('download_failed_check_logs')));
        exit;
    }

    $logger->log($user['id'], 'file_download', 'file', $fileId, 'Usuario descargó archivo');
    
    // Mark download as completed (successful)
    if ($downloadLogId) {
        $forensicLogger->completeDownload($downloadLogId, $file['file_size'], 200);
    }
    
} catch (Exception $e) {
    header('Location: ' . BASE_URL . '/user/files.php?error=' . urlencode($e->getMessage()));
    exit;
}
