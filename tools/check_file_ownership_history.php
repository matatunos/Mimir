<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT COUNT(*) as c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'file_ownership_history'");
    $stmt->execute();
    $exists = intval($stmt->fetchColumn()) > 0;
    if (!$exists) {
        echo "file_ownership_history table NOT found in database.\n";
        exit(1);
    }

    echo "file_ownership_history exists. Recent rows:\n";
    $rows = $db->query("SELECT * FROM file_ownership_history ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo sprintf("%s | file_id=%s old=%s new=%s by=%s reason=%s\n", $r['created_at'], $r['file_id'], $r['old_user_id'] ?? 'NULL', $r['new_user_id'] ?? 'NULL', $r['changed_by_user_id'] ?? 'NULL', $r['reason'] ?? '');
    }
    exit(0);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(2);
}
