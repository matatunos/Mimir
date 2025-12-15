<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$id = intval($argv[1] ?? 0);
if (!$id) { echo "Usage: php modify_test_user.php USER_ID\n"; exit(1); }

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("UPDATE users SET full_name = ?, require_2fa = 1, updated_at = NOW() WHERE id = ?");
$stmt->execute(['Autotest User Modified', $id]);

$stmt = $db->prepare("SELECT id, username, email, full_name, require_2fa FROM users WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($row);

echo "Modified user id: $id\n";
exit(0);

?>