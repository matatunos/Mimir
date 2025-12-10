<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT id,event_type,description,details,created_at FROM security_events WHERE event_type LIKE 'ldap_%' ORDER BY created_at DESC LIMIT 50");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows)) {
    echo "No ldap_* events found\n";
    exit(0);
}
foreach ($rows as $r) {
    echo "[{$r['created_at']}] id={$r['id']} type={$r['event_type']} desc={$r['description']} details={$r['details']}\n";
}
