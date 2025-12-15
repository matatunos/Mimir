<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$username = $argv[1] ?? 'autotest_user_' . time();
$email = $argv[2] ?? ($username . '@example.com');
$password = $argv[3] ?? 'AutotestPass123!';

$hash = password_hash($password, PASSWORD_DEFAULT);

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("INSERT INTO users (username, email, password, full_name, role, is_active, created_at) VALUES (?, ?, ?, ?, 'user', 1, NOW())");
$stmt->execute([$username, $email, $hash, 'Autotest User']);
$id = $db->lastInsertId();
echo "Created user id: $id username: $username password: $password\n";
exit(0);

?>