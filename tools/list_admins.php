<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT id,username,email,role FROM users WHERE role='admin'");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
