<?php
// Usage: php simulate_owner_change.php <file_id> <new_user_id> [actor_user_id]
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

if ($argc < 3) {
    echo "Usage: php simulate_owner_change.php <file_id> <new_user_id> [actor_user_id]\n";
    exit(1);
}

$fileId = intval($argv[1]);
$newUserId = intval($argv[2]);
$actor = isset($argv[3]) ? intval($argv[3]) : ($_SESSION['user_id'] ?? null);

try {
    $db = Database::getInstance()->getConnection();

    echo "Setting @current_actor_id = " . ($actor ?? 'NULL') . "\n";
    $stmt = $db->prepare("SET @current_actor_id = ?");
    $stmt->execute([$actor]);

    echo "Updating file id=$fileId -> user_id=$newUserId\n";
    $u = $db->prepare("UPDATE files SET user_id = ? WHERE id = ?");
    $u->execute([$newUserId, $fileId]);

    // clear var
    $db->query("SET @current_actor_id = NULL");

    // show latest inserted history for that file
    $q = $db->prepare("SELECT * FROM file_ownership_history WHERE file_id = ? ORDER BY created_at DESC LIMIT 5");
    $q->execute([$fileId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "No history rows found for file $fileId\n";
        exit(0);
    }
    echo "Recent history for file $fileId:\n";
    foreach ($rows as $r) {
        echo sprintf("%s | id=%s file=%s old=%s new=%s by=%s reason=%s note=%s\n",
            $r['created_at'], $r['id'], $r['file_id'], $r['old_user_id'] ?? 'NULL', $r['new_user_id'] ?? 'NULL', $r['changed_by_user_id'] ?? 'NULL', $r['reason'] ?? '', substr($r['note'] ?? '', 0, 120)
        );
    }
    exit(0);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(2);
}
