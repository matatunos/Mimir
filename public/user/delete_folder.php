<?php
/**
 * Mimir File Management System
 * Delete Folder Endpoint
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
    
    if (!isset($input['folder_id'])) {
        throw new Exception("ID de carpeta requerido");
    }
    
    $folderId = (int)$input['folder_id'];
    
    // Delete folder
    $fileClass->deleteFolder($folderId, $user['id']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Carpeta eliminada exitosamente'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
