<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$userId = intval($argv[1] ?? 1);
$originalName = $argv[2] ?? 'autotest_file.txt';
$content = $argv[3] ?? "Autotest file generated at " . date('c');

$uploadsDir = UPLOADS_PATH . '/' . $userId;
if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0770, true);

$storedName = uniqid('autotest_') . '_' . time() . '.txt';
$filePath = $uploadsDir . '/' . $storedName;
file_put_contents($filePath, $content);
$fileSize = filesize($filePath);
$fileHash = hash_file('sha256', $filePath);
$mimeType = mime_content_type($filePath) ?: 'text/plain';

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("INSERT INTO files (user_id, original_name, stored_name, file_path, file_size, mime_type, file_hash, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$userId, $originalName, $storedName, $filePath, $fileSize, $mimeType, $fileHash, 'Autotest file']);
$id = $db->lastInsertId();
echo "Created file id: $id\n";
exit(0);

?>