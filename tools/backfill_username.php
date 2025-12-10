<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT id, details FROM security_events WHERE (username IS NULL OR username = '') AND details IS NOT NULL AND details LIKE '%\"username\"%' LIMIT 1000");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$updated = 0;
foreach ($rows as $r) {
    $id = $r['id'];
    $details = $r['details'];
    $decoded = json_decode($details, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($decoded['username'])) {
        $username = $decoded['username'];
        $uStmt = $db->prepare("UPDATE security_events SET username = ? WHERE id = ?");
        $uStmt->execute([$username, $id]);
        $updated++;
    }
}
echo "Updated: $updated\n";

// Suggest manual update count
$countStmt = $db->prepare("SELECT COUNT(*) FROM security_events WHERE username IS NULL AND details IS NOT NULL AND details LIKE '%\"username\"%'");
$countStmt->execute();
$remaining = $countStmt->fetchColumn();
echo "Remaining rows with username in details but not updated: $remaining\n";

?>
