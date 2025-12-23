<?php
// Lightweight runtime scenario: performs logins and inserts simulated events for duration
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$opts = getopt('', ['duration::', 'concurrency::']);
$duration = isset($opts['duration']) ? intval($opts['duration']) : 3600; // seconds
$concurrency = isset($opts['concurrency']) ? intval($opts['concurrency']) : 20;

$db = Database::getInstance()->getConnection();

// Fetch a sample of users to drive activity
$stmt = $db->query("SELECT id, username, email FROM users ORDER BY RAND() LIMIT 500");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$users) { echo "No users available for scenario.\n"; exit(1); }

echo "Starting scenario for {$duration}s with concurrency={$concurrency}. Users available: " . count($users) . "\n";

$end = time() + $duration;
$loginUrl = (defined('BASE_URL') ? BASE_URL : '') . '/login.php';

// Simple helper to perform a login POST
function do_login($url, $username, $password) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['username' => $username, 'password' => $password]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $res = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return $info['http_code'] ?? 0;
}

$count = 0;
while (time() < $end) {
    $batch = [];
    for ($i = 0; $i < $concurrency; $i++) {
        $u = $users[array_rand($users)];
        $batch[] = $u;
    }

    foreach ($batch as $u) {
        // perform login (non-blocking simple call)
        $code = do_login($loginUrl, $u['username'], 'Satriani@69.');
        if ($code === 200 || $code === 302) {
            // insert a small activity row
            $ins = $db->prepare("INSERT INTO security_events (event_type, username, severity, user_id, ip_address, user_agent, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $ins->execute(['failed_login', $u['username'], 'low', $u['id'], '203.0.113.' . rand(1, 254), 'ScenarioAgent/1.0', 'Simulated login attempt']);
        } else {
            $ins = $db->prepare("INSERT INTO security_events (event_type, username, severity, user_id, ip_address, user_agent, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $ins->execute(['failed_login', $u['username'], 'low', $u['id'], '203.0.113.' . rand(1, 254), 'ScenarioAgent/1.0', 'Simulated failed login']);
        }
        $count++;
    }

    // Occasionally create a notification job (emails)
    if (rand(1, 20) === 1) {
        $nj = $db->prepare("INSERT INTO notification_jobs (recipient, subject, body, options, actor_id, target_id, attempts, status, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, NULL, 0, 'pending', NOW(), NOW())");
        $u = $users[array_rand($users)];
        $nj->execute([$u['email'], 'Simulated notification', '<p>Simulated notification body</p>', json_encode(['type' => 'sim'])]);
    }

    // throttle a bit
    usleep(200000); // 0.2s
}

echo "Scenario completed. Performed {$count} login attempts and created some notifications.\n";
exit(0);
