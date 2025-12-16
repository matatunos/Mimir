<?php
// Usage: php find_user.php email_or_username
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
$arg = $argv[1] ?? null;
if (!$arg) { echo "Usage: php find_user.php email_or_username\n"; exit(1); }
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare('SELECT id,username,email,full_name,created_at FROM users WHERE email = ? OR username = ? LIMIT 1');
$stmt->execute([$arg, $arg]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if ($r) {
    echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
    exit(0);
}
// Also search recent activity_log for user_create events matching username/email
$stmt2 = $db->prepare('SELECT id,actor_id,event_type,object_type,object_id,description,created_at FROM activity_log WHERE (description LIKE ? OR description LIKE ?) ORDER BY created_at DESC LIMIT 10');
$stmt2->execute(["%{$arg}%","%{$arg}%"]);
$rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
if (!empty($rows)) {
    echo "Recent activity_log entries matching {$arg}:\n" . json_encode($rows, JSON_PRETTY_PRINT) . "\n";
} else {
    echo "No user found and no matching activity_log entries for {$arg}\n";
}
