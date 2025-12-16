<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/ForensicLogger.php';
require_once __DIR__ . '/../../classes/SecurityValidator.php';

$auth = new Auth();
$auth->requireAdmin();
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
    header('Location: ' . BASE_URL . '/admin/files.php?error=' . urlencode('ID de archivo no válido'));
    exit;
}

try {
    $file = $fileClass->getById($fileId);
    if (!$file) {
        throw new Exception('Archivo no encontrado');
    }

    // Forensic log with admin user id
    $downloadLogId = $forensicLogger->logDownload($fileId, null, $user['id']);

    // Use File::download without owner check (pass null)
    $result = $fileClass->download($fileId, null);

    if ($result === false) {
        if ($downloadLogId) {
            $forensicLogger->completeDownload($downloadLogId, 0, 500, 'Download failed');
        }
        throw new Exception('Descarga fallida. Revisa los logs del servidor.');
    }

    $logger->log($user['id'], 'admin_file_download', 'file', $fileId, 'Admin downloaded file');

    if ($downloadLogId) {
        $forensicLogger->completeDownload($downloadLogId, $file['file_size'], 200);
    }

} catch (Exception $e) {
    header('Location: ' . BASE_URL . '/admin/files.php?error=' . urlencode($e->getMessage()));
    exit;
}
