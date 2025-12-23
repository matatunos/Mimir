<?php
// CLI tool to seed users for load testing. Safe-by-default: use --test to create a small number.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';

$opts = getopt('', ['count::', 'password::', 'test', 'set-smtp::', 'send-test-to::']);
$count = isset($opts['count']) ? intval($opts['count']) : 2000;
$password = $opts['password'] ?? 'Satriani@69.';
$isTest = isset($opts['test']);
$setSmtp = $opts['set-smtp'] ?? null;
$sendTestTo = $opts['send-test-to'] ?? null;

if ($isTest) $count = min(2, $count);

$db = Database::getInstance()->getConnection();

echo "Seeding $count users (test={$isTest}).\n";

// Optionally set SMTP password in config (writes to DB)
if ($setSmtp !== null) {
    $cfg = new Config();
    $ok = $cfg->set('smtp_password', $setSmtp);
    echo $ok ? "SMTP password set in config.\n" : "Failed to set SMTP password in config.\n";
}

$created = [];
try {
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO users (username, email, password, full_name, role, is_active, created_at) VALUES (?, ?, ?, ?, 'user', 1, NOW())");
    for ($i = 1; $i <= $count; $i++) {
        $username = 'simuser_' . ($isTest ? ('t' . $i) : $i) . '_' . time();
        $email = $username . '@example.com';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt->execute([$username, $email, $hash, 'Simulated User']);
        $id = $db->lastInsertId();
        $created[] = ['id' => $id, 'username' => $username, 'email' => $email];
        if ($isTest) echo "Created user: $username <$email>\n";
    }
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    echo "Error creating users: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Created " . count($created) . " users.\n";

// Optionally send a single test email (only if --send-test-to provided)
if ($sendTestTo) {
    require_once __DIR__ . '/../classes/Email.php';
    $emailer = new Email();
    $subject = "Mimir load test - single test email";
    $body = "<p>This is a single test email sent by the load seeder at " . date('c') . "</p>";
    echo "Sending test email to $sendTestTo ...\n";
    $res = $emailer->send($sendTestTo, $subject, $body);
    echo $res ? "Test email sent.\n" : "Test email failed (check smtp settings and logs).\n";
}

exit(0);

?>
