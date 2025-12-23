<?php
// Generate shares for existing files and simulate downloads referencing those shares.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$opts = getopt('', ['duration::','rate::']);
$duration = isset($opts['duration']) ? intval($opts['duration']) : 1800; // seconds
$rate = isset($opts['rate']) ? intval($opts['rate']) : 5; // shares per loop

$db = Database::getInstance()->getConnection();

echo "Starting shares/download simulation for {$duration}s (rate={$rate}).\n";

$filesStmt = $db->query("SELECT id, user_id FROM files ORDER BY RAND() LIMIT 1000");
$files = $filesStmt->fetchAll(PDO::FETCH_ASSOC);
if (!$files) { echo "No files to share.\n"; exit(1); }

$end = time() + $duration;
while (time() < $end) {
    for ($i=0;$i<$rate;$i++) {
        $f = $files[array_rand($files)];
        $token = bin2hex(random_bytes(8));
        $creator = $f['user_id'];
        $shareName = 'sim_share_' . substr($token,0,6);
        $expires = date('Y-m-d H:i:s', time() + rand(3600, 7*24*3600));
        $ins = $db->prepare("INSERT INTO shares (file_id, share_token, share_name, recipient_email, recipient_message, max_downloads, download_count, expires_at, is_active, created_by, created_at) VALUES (?, ?, ?, NULL, NULL, NULL, 0, ?, 1, ?, NOW())");
        $ins->execute([$f['id'], $token, $shareName, $expires, $creator]);
        $shareId = $db->lastInsertId();

        // simulate downloads against share
        $dlCount = rand(1,3);
        for ($d=0;$d<$dlCount;$d++) {
            $started = date('Y-m-d H:i:s', time() - rand(0, 3600));
            $stmt = $db->prepare("INSERT INTO download_log (file_id, share_id, user_id, ip_address, user_agent, download_started_at, download_completed_at, http_status_code, created_at) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)");
            $ip = '198.51.100.' . rand(1,254);
            $ua = 'ShareAgent/1.0';
            $completed = date('Y-m-d H:i:s', strtotime($started) + rand(1,10));
            $code = 200;
            $stmt->execute([$f['id'], $shareId, $ip, $ua, $started, $completed, $code, $started]);
        }

        // update share download_count
        $db->prepare("UPDATE shares SET download_count = download_count + ? WHERE id = ?")->execute([$dlCount, $shareId]);
    }
    usleep(500000); // 0.5s
}

echo "Shares/downloads simulation complete.\n";
exit(0);
