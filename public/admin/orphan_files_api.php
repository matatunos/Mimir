<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Logger.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireAdmin();
$adminUser = $auth->getUser();

$fileClass = new File();
$userClass = new User();
$logger = new Logger();

// GET requests - search users
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'search_users') {
        $query = trim($_GET['q'] ?? '');
        
        if (strlen($query) < 2) {
            echo json_encode(['success' => false, 'message' => 'Query too short']);
            exit;
        }
        
        $db = getDatabase();
        $stmt = $db->prepare("
            SELECT id, username, email, full_name, role
            FROM users
            WHERE is_active = 1
            AND (username LIKE ? OR email LIKE ? OR full_name LIKE ?)
            ORDER BY username ASC
            LIMIT 10
        ");
        
        $searchTerm = '%' . $query . '%';
        $stmt->bind_param('sss', $searchTerm, $searchTerm, $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $users = [];
        while ($user = $result->fetch_assoc()) {
            $users[] = $user;
        }
        
        echo json_encode(['success' => true, 'users' => $users]);
        exit;
    }
}

// POST requests - file operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'assign') {
        $fileId = intval($_POST['file_id'] ?? 0);
        $userId = intval($_POST['user_id'] ?? 0);
        
        if (!$fileId || !$userId) {
            echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
            exit;
        }
        
        // Verify file exists and is orphaned
        $db = getDatabase();
        $stmt = $db->prepare("SELECT id, filename FROM files WHERE id = ? AND user_id IS NULL");
        $stmt->bind_param('i', $fileId);
        $stmt->execute();
        $file = $stmt->get_result()->fetch_assoc();
        
        if (!$file) {
            echo json_encode(['success' => false, 'message' => 'Archivo no encontrado o no es huérfano']);
            exit;
        }
        
        // Verify user exists
        $user = $userClass->getById($userId);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
            exit;
        }
        
        try {
            if ($fileClass->reassignOwner($fileId, $userId)) {
                $logger->log(
                    $adminUser['id'],
                    'orphan_assigned',
                    'file',
                    $fileId,
                    "Archivo huérfano '{$file['filename']}' asignado a {$user['username']}"
                );
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al asignar el archivo']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'delete') {
        $fileId = intval($_POST['file_id'] ?? 0);
        
        if (!$fileId) {
            echo json_encode(['success' => false, 'message' => 'ID de archivo inválido']);
            exit;
        }
        
        // Verify file exists and is orphaned
        $db = getDatabase();
        $stmt = $db->prepare("SELECT id, filename, file_path FROM files WHERE id = ? AND user_id IS NULL");
        $stmt->bind_param('i', $fileId);
        $stmt->execute();
        $file = $stmt->get_result()->fetch_assoc();
        
        if (!$file) {
            echo json_encode(['success' => false, 'message' => 'Archivo no encontrado o no es huérfano']);
            exit;
        }
        
        try {
            // Delete physical file
            $fullPath = UPLOAD_DIR . '/' . $file['file_path'];
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
            
            // Delete database record
            $stmt = $db->prepare("DELETE FROM files WHERE id = ?");
            $stmt->bind_param('i', $fileId);
            
            if ($stmt->execute()) {
                $logger->log(
                    $adminUser['id'],
                    'orphan_deleted',
                    'file',
                    $fileId,
                    "Archivo huérfano '{$file['filename']}' eliminado"
                );
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al eliminar el archivo']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'bulk_delete') {
        $fileIds = json_decode($_POST['file_ids'] ?? '[]', true);
        
        if (!is_array($fileIds) || empty($fileIds)) {
            echo json_encode(['success' => false, 'message' => 'No se seleccionaron archivos']);
            exit;
        }
        
        $db = getDatabase();
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $types = str_repeat('i', count($fileIds));
        
        // Get orphaned files
        $stmt = $db->prepare("SELECT id, filename, file_path FROM files WHERE id IN ($placeholders) AND user_id IS NULL");
        $stmt->bind_param($types, ...$fileIds);
        $stmt->execute();
        $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if (empty($files)) {
            echo json_encode(['success' => false, 'message' => 'No se encontraron archivos huérfanos']);
            exit;
        }
        
        $deleted = 0;
        foreach ($files as $file) {
            try {
                // Delete physical file
                $fullPath = UPLOAD_DIR . '/' . $file['file_path'];
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
                
                // Delete database record
                $stmt = $db->prepare("DELETE FROM files WHERE id = ?");
                $stmt->bind_param('i', $file['id']);
                
                if ($stmt->execute()) {
                    $deleted++;
                    $logger->log(
                        $adminUser['id'],
                        'orphan_deleted',
                        'file',
                        $file['id'],
                        "Archivo huérfano '{$file['filename']}' eliminado (bulk)"
                    );
                }
            } catch (Exception $e) {
                // Continue with next file
                continue;
            }
        }
        
        echo json_encode(['success' => true, 'deleted' => $deleted]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Acción no válida']);
