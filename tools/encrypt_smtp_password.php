<?php
// Usage: php encrypt_smtp_password.php "plainpassword"
require_once __DIR__ . '/../includes/config.php';

$pwd = $argv[1] ?? null;
if (!$pwd) {
    echo "Usage: php encrypt_smtp_password.php \"plainpassword\"\n";
    exit(1);
}

$secretDir = dirname(__DIR__) . '/.secrets';
if (!is_dir($secretDir)) mkdir($secretDir, 0750, true);
$keyFile = $secretDir . '/smtp_key';
if (!file_exists($keyFile)) {
    $rawKey = random_bytes(32);
    file_put_contents($keyFile, base64_encode($rawKey));
    chmod($keyFile, 0640);
    echo "Generated key in {$keyFile}\n";
} else {
    echo "Using existing key in {$keyFile}\n";
}

$key = base64_decode(trim(file_get_contents($keyFile)));
$iv = random_bytes(16);
$cipher = openssl_encrypt($pwd, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
$enc = 'ENC:' . base64_encode($iv) . ':' . base64_encode($cipher);
echo "Encrypted: $enc\n";

// Update DB config
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$stmt = $pdo->prepare("INSERT INTO config (config_key, config_value, config_type, is_system) VALUES ('smtp_password', ?, 'string', 0) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
$stmt->execute([$enc]);
echo "Updated database config 'smtp_password' with encrypted value.\n";

?>
