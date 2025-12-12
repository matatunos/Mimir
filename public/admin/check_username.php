<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

header('Content-Type: application/json');

$username = trim($_GET['username'] ?? $_POST['username'] ?? '');
if ($username === '') {
    echo json_encode(['exists' => false, 'error' => 'empty']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    // Check users table
    $stmt = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        echo json_encode(['exists' => true, 'where' => 'users']);
        exit;
    }

    // Also check pending invitations with same forced_username (not revoked and not used)
    $stmt2 = $db->prepare("SELECT id FROM invitations WHERE forced_username = ? AND is_revoked = 0 AND used_at IS NULL LIMIT 1");
    $stmt2->execute([$username]);
    if ($stmt2->fetch()) {
        echo json_encode(['exists' => true, 'where' => 'invitations']);
        exit;
    }

    echo json_encode(['exists' => false]);
} catch (Exception $e) {
    echo json_encode(['exists' => false, 'error' => 'db']);
}
