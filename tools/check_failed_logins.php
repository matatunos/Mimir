<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$username = $argv[1] ?? null;
if (!$username) {
    echo "Usage: php tools/check_failed_logins.php username\n";
    exit(2);
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT id, event_type, username, severity, ip_address, user_agent, description, details, created_at FROM security_events WHERE event_type = 'failed_login' AND username = ? ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$username]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "No failed_login events found for user: $username\n";
    exit(0);
}

foreach ($rows as $r) {
    echo "[{$r['created_at']}] id={$r['id']} user={$r['username']} ip={$r['ip_address']} agent={$r['user_agent']} desc={$r['description']} details={$r['details']}\n";
}

