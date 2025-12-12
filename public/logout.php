<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
$auth = new Auth();
$auth->logout();
header('Location: ' . BASE_URL . '/login.php');
exit;
