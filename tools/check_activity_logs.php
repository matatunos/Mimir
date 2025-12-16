<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT id, actor_id, action, object_type, object_id, message, created_at FROM activity_log WHERE action LIKE '%invitation%' OR action LIKE '%notif_user_created%' ORDER BY created_at DESC LIMIT 40");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
