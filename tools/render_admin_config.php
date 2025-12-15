<?php
// Render admin/config.php as if an admin is logged in, capture output to stdout
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

// Simulate server environment
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'CLI-Test/1.0';

// Start session and set admin user context
if (session_status() === PHP_SESSION_NONE) session_start();
// Minimal session user that Auth->requireAdmin() can accept (id 1 assumed admin)
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['email'] = 'admin@localhost';
$_SESSION['role'] = 'admin';

ob_start();
include __DIR__ . '/../public/admin/config.php';
$out = ob_get_clean();
file_put_contents(__DIR__ . '/rendered_config.html', $out);
echo "Rendered to tools/rendered_config.html\n";

?>
