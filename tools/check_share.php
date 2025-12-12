<?php
// Usage: php tools/check_share.php <share_token>
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$token = $argv[1] ?? '';
if (!$token) {
    echo "Usage: php tools/check_share.php <share_token>\n";
    exit(1);
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare('SELECT s.*, f.id as file_id, f.file_path, f.stored_name, f.original_name, f.file_size, f.mime_type, u.username as owner_username, u.email as owner_email FROM shares s JOIN files f ON s.file_id = f.id JOIN users u ON f.user_id = u.id WHERE s.share_token = ? LIMIT 1');
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo "Share with token $token not found\n";
    exit(2);
}

echo "Share ID: " . ($row['id'] ?? '') . "\n";
echo "Share token: " . ($row['share_token'] ?? '') . "\n";
echo "File ID: " . ($row['file_id'] ?? '') . "\n";
echo "Original name: " . ($row['original_name'] ?? '') . "\n";
echo "Stored name: " . ($row['stored_name'] ?? '') . "\n";
echo "File path (DB): " . ($row['file_path'] ?? '') . "\n";
echo "File size (DB): " . ($row['file_size'] ?? '') . "\n";
echo "Mime type (DB): " . ($row['mime_type'] ?? '') . "\n";
echo "Share is_active: " . ($row['is_active'] ? '1' : '0') . "\n";
echo "Share expires_at: " . ($row['expires_at'] ?? 'NULL') . "\n";
echo "Share max_downloads: " . ($row['max_downloads'] ?? 'NULL') . "\n";
echo "Recipient email: " . ($row['recipient_email'] ?? 'NULL') . "\n";
echo "Owner: " . ($row['owner_username'] ?? '') . " <" . ($row['owner_email'] ?? '') . ">\n";

$path = $row['file_path'] ?? '';
if (!$path) {
    echo "No file_path recorded in DB\n";
    exit(3);
}

$exists = file_exists($path);
echo "File exists: " . ($exists ? 'yes' : 'no') . "\n";
if ($exists) {
    echo "Is readable: " . (is_readable($path) ? 'yes' : 'no') . "\n";
    echo "Is file: " . (is_file($path) ? 'yes' : 'no') . "\n";
    echo "Filesize actual: " . filesize($path) . "\n";
    $st = @stat($path);
    if ($st) {
        echo "Owner UID: " . ($st['uid'] ?? '') . "\n";
        echo "Perms (octal): " . substr(sprintf('%o', $st['mode']), -4) . "\n";
    }
    // Try to read first bytes
    $h = @fopen($path, 'rb');
    if ($h) {
        $data = fread($h, 64);
        fclose($h);
        echo "First bytes (hex): " . bin2hex($data) . "\n";
    } else {
        echo "Unable to open file for read\n";
    }
}

// Also check uploads path and UPLOADS_PATH constant
if (defined('UPLOADS_PATH')) {
    echo "UPLOADS_PATH constant: " . UPLOADS_PATH . "\n";
}

exit(0);
