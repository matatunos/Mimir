<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$id = intval($argv[1] ?? 0);
if (!$id) { echo "Usage: php delete_test_user.php USER_ID\n"; exit(1); }

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("DELETE FROM users WHERE id = ?");
$stmt->execute([$id]);
echo "Deleted user id: $id\n";
exit(0);

?>