<?php
// CLI: php tools/test_share_send.php FILE_ID recipient@example.com
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../classes/Share.php';

$fileId = intval($argv[1] ?? 0);
$recipient = $argv[2] ?? null;
if (!$fileId || !$recipient) {
    echo "Usage: php tools/test_share_send.php FILE_ID recipient@example.com\n";
    exit(1);
}

$share = new Share();
try {
    $res = $share->create($fileId, 1, ['recipient_email' => $recipient, 'recipient_message' => 'Prueba automática desde test_share_send.php']);
    echo "Created share: " . json_encode($res) . "\n";
} catch (Exception $e) {
    echo "Error creating share: " . $e->getMessage() . "\n";
}
