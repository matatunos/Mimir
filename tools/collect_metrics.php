<?php
// Collect quick DB metrics for the running scenario
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

header: null;
try {
    $db = Database::getInstance()->getConnection();
    $out = [];
    $queries = [
        'users' => 'SELECT COUNT(*) AS c FROM users',
        'files' => 'SELECT COUNT(*) AS c FROM files',
        'downloads' => 'SELECT COUNT(*) AS c FROM download_log',
        'events' => 'SELECT COUNT(*) AS c FROM security_events',
        'notif_pending' => "SELECT COUNT(*) AS c FROM notification_jobs WHERE status = 'pending'",
    ];
    foreach ($queries as $k => $sql) {
        $r = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
        $out[$k] = intval($r['c'] ?? 0);
    }
    echo json_encode($out, JSON_PRETTY_PRINT) . "\n";
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]) . "\n";
}
