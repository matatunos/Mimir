<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
$auth = new Auth();
$auth->requireLogin();
if ($auth->isAdmin()) {
    header('Location: ' . BASE_URL . '/admin/index.php');
} else {
    header('Location: ' . BASE_URL . '/user/index.php');
}
exit;
