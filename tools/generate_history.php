<?php
// Generate historical activity (files, downloads, security_events)
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$opts = getopt('', ['users::', 'max-files::']);
@file_put_contents('/tmp/gen_hist.log', "started\n", FILE_APPEND);
$numUsers = isset($opts['users']) ? intval($opts['users']) : 200;
$maxFiles = isset($opts['max-files']) ? intval($opts['max-files']) : 10;

$db = Database::getInstance()->getConnection();

// Pick random users
$stmt = $db->query("SELECT id FROM users ORDER BY RAND() LIMIT " . intval($numUsers));
$res = $stmt ? 'ok' : 'empty';
@file_put_contents('/tmp/gen_hist.log', "after query: $res\n", FILE_APPEND);
$users = $stmt->fetchAll(PDO::FETCH_COLUMN);
@file_put_contents('/tmp/gen_hist.log', "users count: " . count($users) . "\n", FILE_APPEND);
if (!$users) { echo "No users found to generate history.\n"; exit(1); }

echo "Generating history for " . count($users) . " users...\n";

try {
    $insertFile = $db->prepare("INSERT INTO files (user_id, original_name, stored_name, file_path, file_size, mime_type, file_hash, description, is_shared, is_folder, parent_folder_id, is_expired, never_expire, expired_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NULL, 0, 0, NULL, ?, ?)");
    $insertDownload = $db->prepare("INSERT INTO download_log (file_id, user_id, ip_address, user_agent, download_started_at, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    $insertEvent = $db->prepare("INSERT INTO security_events (event_type, username, severity, user_id, ip_address, user_agent, description, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $i = 0;
    foreach ($users as $uid) {
        $i++;
        if ($i % 10 === 0) @file_put_contents('/tmp/gen_hist.log', "Processing user #$i id={$uid}\n", FILE_APPEND);
        $numFiles = rand(1, $maxFiles);
        for ($f = 0; $f < $numFiles; $f++) {
            // Random timestamp in last 10 years
            $ts = time() - rand(0, 10 * 365 * 24 * 3600);
            $created = date('Y-m-d H:i:s', $ts);
            // Use mt_rand fallback for environments without random_bytes
            $randHex = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
            $name = "history_{$uid}_" . $randHex . ".txt";
            $stored = uniqid('f', true);
            $path = '/storage/uploads/' . $stored;
            $size = rand(1024, 1024 * 100);
            $hash = hash('sha256', $name . $ts);
            $desc = 'Historic generated file';
            $insertFile->execute([$uid, $name, $stored, $path, $size, 'text/plain', $hash, $desc, $created, $created]);
            $fid = $db->lastInsertId();

            // Generate a few downloads
            $dlCount = rand(0, 5);
            for ($d = 0; $d < $dlCount; $d++) {
                $dts = date('Y-m-d H:i:s', $ts + rand(0, 30 * 24 * 3600));
                $insertDownload->execute([$fid, $uid, '192.0.2.' . rand(1, 254), 'SimAgent/1.0', $dts, $dts]);
            }

            // Some security events
            if (rand(1, 10) === 1) {
                $etype = 'suspicious_download';
                $insertEvent->execute([$etype, null, 'medium', $uid, '198.51.100.' . rand(1, 254), 'SimAgent/1.0', 'Suspicious download detected', json_encode(['file_id' => $fid]), $created]);
            }
        }
    }

    echo "History generation complete.\n";
    exit(0);
} catch (Throwable $e) {
    $msg = "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString();
    @file_put_contents('/tmp/gen_hist.log', $msg, FILE_APPEND);
    echo "Error during history generation (see /tmp/gen_hist.log).\n";
    exit(1);
}
