<?php
// test_create_folder_cli.php
// Simulate a logged-in user and call File::createFolder to verify behavior.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../classes/File.php';

// Instantiate Auth to ensure session settings are applied
$auth = new Auth();

// Find a user to act as
$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT id, username, email, full_name, role, is_ldap FROM users ORDER BY id LIMIT 1");
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "No users found in DB to simulate login.\n");
    exit(1);
}

// Ensure session is active
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Populate session as if logged in
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['email'] = $user['email'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role'] = $user['role'];
$_SESSION['is_ldap'] = $user['is_ldap'];

$fileClass = new File();
$folderName = 'cli_test_folder_' . bin2hex(random_bytes(4));
try {
    $folderId = $fileClass->createFolder($user['id'], $folderName, null);
    echo "Created folder id: $folderId with name: $folderName\n";
} catch (Exception $e) {
    echo "Error creating folder: " . $e->getMessage() . "\n";
}

exit(0);
