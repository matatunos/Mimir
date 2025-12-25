<?php
// Usage: php tools/create_user.php --username=demo_user --email=demo@example.com --full-name="Demo User" --password=Secret123
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/User.php';

$opts = getopt('', ['username:', 'email:', 'full-name::', 'password::']);
$username = $opts['username'] ?? null;
$email = $opts['email'] ?? null;
$fullName = $opts['full-name'] ?? ($opts['full_name'] ?? 'Demo User');
$password = $opts['password'] ?? null;

if (!$username || !$email) {
    echo "Usage: php tools/create_user.php --username=demo_user --email=demo@example.com [--full-name='Demo User'] [--password=Secret]\n";
    exit(1);
}

if (!$password) {
    // generate a reasonably strong password
    $password = trim(shell_exec('head -c 24 /dev/urandom | base64 | tr -dc A-Za-z0-9 | head -c 16'));
}

// Create user
$userClass = new User();
$res = $userClass->create([
    'username' => $username,
    'email' => $email,
    'password' => $password,
    'full_name' => $fullName,
    'role' => 'user',
    'is_active' => 1
]);

if ($res === false) {
    echo "Failed to create user. Check logs or ensure username/email are unique.\n";
    exit(2);
}

echo "User created successfully.\n";
echo "Username: {$username}\n";
echo "Email: {$email}\n";
echo "Password: {$password}\n";
echo "User ID: {$res}\n";
echo "You can login at: " . BASE_URL . "/login.php\n";

?>
