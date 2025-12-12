<?php
/**
 * Mimir File Management System
 * Create Folder Endpoint
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/File.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    $user = $auth->getUser();
    $fileClass = new File();
    
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['folder_name']) || empty(trim($input['folder_name']))) {
        throw new Exception("Nombre de carpeta requerido");
    }
    
    $folderName = trim($input['folder_name']);
    $parentFolderId = isset($input['parent_folder_id']) && $input['parent_folder_id'] !== '' 
        ? (int)$input['parent_folder_id'] 
        : null;
    
    // Create folder
    $folderId = $fileClass->createFolder($user['id'], $folderName, $parentFolderId);
    
    echo json_encode([
        'success' => true,
        'message' => 'Carpeta creada exitosamente',
        'folder_id' => $folderId
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
