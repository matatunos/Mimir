<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$id = intval($argv[1] ?? 0);
if (!$id) { echo "Usage: php delete_test_file.php FILE_ID\n"; exit(1); }

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT file_path, file_size FROM files WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    if (!empty($row['file_path']) && file_exists($row['file_path'])) unlink($row['file_path']);
}
$stmt = $db->prepare("DELETE FROM files WHERE id = ?");
$stmt->execute([$id]);
echo "Deleted file id: $id\n";
exit(0);

?>