<?php
require_once __DIR__ . '/../../includes/init.php';

Auth::requireLogin();

$fileId = $_GET['id'] ?? null;
if (!$fileId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing id']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT download_count FROM files WHERE id = ?");
    $stmt->execute([$fileId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['id' => (int)$fileId, 'download_count' => (int)$row['download_count']]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}
