#!/usr/bin/env php
<?php
/**
 * Create local user script
 * Usage: php create_user.php
 */

require_once __DIR__ . '/includes/init.php';

echo "===========================================\n";
echo "  Mimir - Create Local User\n";
echo "===========================================\n\n";

// Get username
echo "Username: ";
$username = trim(fgets(STDIN));

if (empty($username)) {
    die("Error: Username cannot be empty\n");
}

// Get email
echo "Email: ";
$email = trim(fgets(STDIN));

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Error: Invalid email address\n");
}

// Get password
echo "Password (min 6 characters): ";
system('stty -echo');
$password = trim(fgets(STDIN));
system('stty echo');
echo "\n";

if (strlen($password) < 6) {
    die("Error: Password must be at least 6 characters\n");
}

// Confirm password
echo "Confirm password: ";
system('stty -echo');
$confirmPassword = trim(fgets(STDIN));
system('stty echo');
echo "\n";

if ($password !== $confirmPassword) {
    die("Error: Passwords do not match\n");
}

// Get role
echo "Role (user/admin) [user]: ";
$role = trim(fgets(STDIN));
if (empty($role)) {
    $role = 'user';
}

if (!in_array($role, ['user', 'admin'])) {
    die("Error: Role must be 'user' or 'admin'\n");
}

// Get storage quota
echo "Storage quota in GB [1]: ";
$quotaGb = trim(fgets(STDIN));
if (empty($quotaGb)) {
    $quotaGb = 1;
}
$quota = intval($quotaGb) * 1073741824; // Convert to bytes

// Confirm
echo "\n--- Summary ---\n";
echo "Username: $username\n";
echo "Email: $email\n";
echo "Role: $role\n";
echo "Storage Quota: {$quotaGb}GB\n";
echo "\nCreate this user? (yes/no): ";
$confirm = trim(fgets(STDIN));

if (strtolower($confirm) !== 'yes') {
    die("User creation cancelled\n");
}

// Create user
try {
    $db = Database::getInstance()->getConnection();
    
    // Check if username exists
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        die("Error: Username already exists\n");
    }
    
    // Check if email exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        die("Error: Email already exists\n");
    }
    
    // Create user
    $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role, storage_quota, is_active) VALUES (?, ?, ?, ?, ?, 1)");
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $stmt->execute([$username, $email, $passwordHash, $role, $quota]);
    
    $userId = $db->lastInsertId();
    
    AuditLog::log(1, 'user_created_cli', 'user', $userId, "Local user created via CLI: $username");
    
    echo "\n✓ User created successfully!\n";
    echo "User ID: $userId\n";
    echo "Username: $username\n";
    echo "Email: $email\n";
    echo "Role: $role\n\n";
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}
