<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/TwoFactor.php';

$auth = new Auth();

echo "<h2>Estado de la Sesión</h2>";
echo "<pre>";
echo "Session ID: " . session_id() . "\n";
echo "Session Status: " . session_status() . " (1=disabled, 2=active)\n";
echo "\nVariables de sesión:\n";
print_r($_SESSION);
echo "</pre>";

// Probar establecer variables 2FA
if (isset($_GET['set'])) {
    $_SESSION['2fa_user_id'] = 10;
    $_SESSION['2fa_pending'] = true;
    echo "<p>Variables 2FA establecidas</p>";
    echo "<a href='test_session.php'>Ver sesión</a> | ";
    echo "<a href='login_2fa_totp.php'>Ir a 2FA</a>";
} else {
    echo "<a href='test_session.php?set=1'>Establecer variables 2FA</a>";
}
