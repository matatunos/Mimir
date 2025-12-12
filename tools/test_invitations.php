<?php
/**
 * Simple CLI test for Invitation class
 * Usage: php tools/test_invitations.php create|resend|revoke [args]
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Invitation.php';

$cmd = $argv[1] ?? '';
$inv = new Invitation();

if ($cmd === 'create') {
    $email = $argv[2] ?? ''; if (!$email) { echo "Usage: php tools/test_invitations.php create email\n"; exit(1); }
    $token = $inv->create($email, 1, ['send_email' => false]);
    if ($token) {
        echo "Created invite for $email -> token: $token\n";
    } else {
        echo "Failed to create invite for $email\n";
    }
} elseif ($cmd === 'list') {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query('SELECT id, email, token, created_at, expires_at, used_at FROM invitations ORDER BY created_at DESC LIMIT 20');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "{$r['id']} | {$r['email']} | {$r['token']} | created: {$r['created_at']} | expires: {$r['expires_at']} | used: {$r['used_at']}\n";
    }
} elseif ($cmd === 'resend') {
    $id = intval($argv[2] ?? 0); if (!$id) { echo "Usage: php tools/test_invitations.php resend id\n"; exit(1); }
    if ($inv->resend($id)) echo "Resent invite id $id\n"; else echo "Resend failed for $id\n";
} elseif ($cmd === 'revoke') {
    $id = intval($argv[2] ?? 0); if (!$id) { echo "Usage: php tools/test_invitations.php revoke id\n"; exit(1); }
    if ($inv->revoke($id, 1)) echo "Revoked invite id $id\n"; else echo "Revoke failed for $id\n";
} else {
    echo "Usage: php tools/test_invitations.php create|list|resend|revoke ...\n";
}
