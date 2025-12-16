<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT id,user_id,action,entity_type,entity_id,description,created_at FROM activity_log WHERE action LIKE 'notif_%' ORDER BY created_at DESC LIMIT 50");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
