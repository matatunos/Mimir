<?php
// Usage: php set_smtp_password.php 'plain_password'
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$plain = $argv[1] ?? null;
if (!$plain) {
    echo "Usage: php set_smtp_password.php 'plain_password'\n";
    exit(1);
}

$keyFile = realpath(__DIR__ . '/../.secrets/smtp_key');
if (!$keyFile || !file_exists($keyFile)) {
    echo "ERROR: key file not found at .secrets/smtp_key\n";
    exit(2);
}
$keyB64 = trim(@file_get_contents($keyFile));
if ($keyB64 === '') {
    echo "ERROR: key file empty or unreadable\n";
    exit(3);
}
$keyRaw = base64_decode($keyB64, true);
if ($keyRaw === false) {
    echo "ERROR: key file does not contain valid base64\n";
    exit(4);
}
$iv = openssl_random_pseudo_bytes(16);
$cipher = openssl_encrypt($plain, 'AES-256-CBC', $keyRaw, OPENSSL_RAW_DATA, $iv);
if ($cipher === false) {
    echo "ERROR: encryption failed\n";
    exit(5);
}
$enc = 'ENC:' . base64_encode($iv) . ':' . base64_encode($cipher);

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("UPDATE config SET config_value = ? WHERE config_key = ?");
    $stmt->execute([$enc, 'smtp_password']);
    echo "Encrypted password written to config (smtp_password).\n";
    exit(0);
} catch (Exception $e) {
    echo "DB error: " . $e->getMessage() . "\n";
    exit(6);
}
