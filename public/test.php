<?php
// Script de prueba para debug
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug Info</h1>";

// Test 1: Config loading
echo "<h2>1. Config File</h2>";
if (file_exists(__DIR__ . '/../config/config.php')) {
    echo "✓ config.php exists<br>";
    require_once __DIR__ . '/../config/config.php';
    echo "✓ config.php loaded<br>";
    echo "SESSION_NAME: " . SESSION_NAME . "<br>";
    echo "BASE_URL: " . BASE_URL . "<br>";
} else {
    echo "✗ config.php NOT found<br>";
}

// Test 2: Session
echo "<h2>2. Session</h2>";
session_name(SESSION_NAME);
session_start();
echo "Session ID: " . session_id() . "<br>";
echo "Session data: <pre>";
print_r($_SESSION);
echo "</pre>";

// Test 3: Database
echo "<h2>3. Database</h2>";
require_once __DIR__ . '/../includes/Database.php';
try {
    $db = Database::getInstance()->getConnection();
    echo "✓ Database connected<br>";
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "<br>";
}

// Test 4: Auth
echo "<h2>4. Auth</h2>";
require_once __DIR__ . '/../includes/Auth.php';
echo "Is logged in: " . (Auth::isLoggedIn() ? 'YES' : 'NO') . "<br>";

echo "<h2>5. Clear Session</h2>";
echo '<a href="?clear=1">Clear Session</a><br>';
if (isset($_GET['clear'])) {
    session_destroy();
    echo "✓ Session destroyed. <a href='test.php'>Reload</a>";
}
