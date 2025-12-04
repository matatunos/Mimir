<?php
require_once __DIR__ . '/../includes/init.php';

// Redirect to dashboard if logged in, otherwise to login
if (Auth::isLoggedIn()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
