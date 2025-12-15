<?php
// CLI helper: create an invitation for nacho@favala.es with forced username 'nacho' and TOTP enforced
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Invitation.php';

$inv = new Invitation();
$token = $inv->create('nacho@favala.es', 1, ['send_email' => true, 'forced_username' => 'nacho', 'force_2fa' => 'totp']);
if ($token) {
    echo "Invitation created and email attempted to nacho@favala.es -> token: $token\n";
    exit(0);
} else {
    echo "Failed to create/send invitation to nacho@favala.es\n";
    exit(1);
}

?>
