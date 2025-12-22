<?php
/**
 * Mimir File Management System
 * Create Folder Endpoint
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
// Ensure opcode cache loads latest class definition
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__DIR__ . '/../../classes/File.php', true);
}
require_once __DIR__ . '/../../classes/File.php';

header('Content-Type: application/json');

$auth = new Auth();
// For AJAX endpoints return JSON 401 instead of redirecting to login page
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => t('error_auth_required')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => t('error_invalid_method')]);
    exit;
}

try {
    $user = $auth->getUser();
    $fileClass = new File();
    
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['folder_name']) || empty(trim($input['folder_name']))) {
        throw new Exception(t('error_folder_name_required'));
    }
    
    $folderName = trim($input['folder_name']);
    $parentFolderId = isset($input['parent_folder_id']) && $input['parent_folder_id'] !== '' 
        ? (int)$input['parent_folder_id'] 
        : null;
    
    // Create folder
    $folderId = $fileClass->createFolder($user['id'], $folderName, $parentFolderId);
    
    echo json_encode([
        'success' => true,
        'message' => t('folder_created_success'),
        'folder_id' => $folderId
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
