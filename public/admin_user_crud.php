<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();
header('Content-Type: application/json');
$db = Database::getInstance()->getConnection();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        try {
            $stmt->execute([$_POST['id']]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';
    $twofa_method = $_POST['twofa_method'] ?? 'none';
    $twofa_enabled = ($twofa_method === 'totp') ? 1 : 0;
    $duo_enabled = ($twofa_method === 'duo') ? 1 : 0;
    // Edición de usuario
    if (isset($_POST['user_id'])) {
        $user_id = $_POST['user_id'];
        $stmt = $db->prepare("UPDATE users SET username=?, email=?, role=?, twofa_enabled=?, duo_enabled=? WHERE id=?");
        try {
            $stmt->execute([$username, $email, $role, $twofa_enabled, $duo_enabled, $user_id]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }
    // Alta de usuario
    if (!$username || !$email || !$password) {
        echo json_encode(['success'=>false,'error'=>'Faltan datos']);
        exit;
    }
    $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role, is_active, twofa_enabled, duo_enabled) VALUES (?, ?, ?, ?, 1, ?, ?)");
    try {
        $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $twofa_enabled, $duo_enabled]);
        echo json_encode(['success'=>true]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}
echo json_encode(['success'=>false,'error'=>'Método no permitido']);
