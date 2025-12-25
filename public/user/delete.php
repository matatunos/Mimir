<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lang.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();
$fileClass = new File();
$logger = new Logger();

$fileId = intval($_GET['id'] ?? 0);

try {
    $file = $fileClass->getById($fileId);
    
    if (!$file || $file['user_id'] != $user['id']) {
        throw new Exception(t('error_file_not_found'));
    }
    
    $fileClass->delete($fileId, $user['id']);
    $logger->log($user['id'], 'file_delete', 'file', $fileId, 'Usuario eliminó archivo: ' . $file['original_name']);

    header('Location: ' . BASE_URL . '/user/files.php?success=' . urlencode(t('file_deleted')));
    
} catch (Exception $e) {
    header('Location: ' . BASE_URL . '/user/files.php?error=' . urlencode($e->getMessage()));
}
exit;
