<?php
// Render admin/invitations.php as if an admin is logged in, capture output to stdout
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

// Simulate server environment
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'CLI-Test/1.0';

// Start session and set admin user context
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['email'] = 'admin@mimir.local';
$_SESSION['role'] = 'admin';

ob_start();
include __DIR__ . '/../public/admin/invitations.php';
$out = ob_get_clean();
file_put_contents(__DIR__ . '/rendered_invitations.html', $out);
echo "Rendered to tools/rendered_invitations.html\n";

// Debug: run the same query here and print count
try {
	$db = Database::getInstance()->getConnection();
	$cnt = (int)$db->query('SELECT COUNT(*) FROM invitations')->fetchColumn();
	echo "Invitations count (debug): $cnt\n";
} catch (Exception $e) {
	echo "Invitations count (debug) error: " . $e->getMessage() . "\n";
}

?>