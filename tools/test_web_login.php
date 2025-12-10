<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';

$username = $argv[1] ?? null;
$password = $argv[2] ?? null;
if (!$username || !$password) {
    echo "Usage: php tools/test_web_login.php username password\n";
    exit(2);
}

$auth = new Auth();
$ok = $auth->login($username, $password);
echo "Login result: " . ($ok ? 'OK' : 'FAIL') . "\n";
if ($ok) {
    $user = $auth->getUser();
    echo "User id: " . $user['id'] . " role: " . $user['role'] . "\n";
}
