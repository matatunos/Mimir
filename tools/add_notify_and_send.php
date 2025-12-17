<?php
// Usage: php tools/add_notify_and_send.php recipient@example.com
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../classes/Notification.php';
require_once __DIR__ . '/../classes/Logger.php';

$recipient = $argv[1] ?? null;
if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    echo "Usage: php tools/add_notify_and_send.php recipient@example.com\n";
    exit(1);
}

$config = new Config();
$db = Database::getInstance()->getConnection();
$logger = new Logger();

// Update config: add recipient to notify_user_creation_emails
$existing = (string)$config->get('notify_user_creation_emails', '');
$emails = array_filter(array_map('trim', explode(',', $existing)));
if (!in_array($recipient, $emails, true)) {
    $emails[] = $recipient;
    $new = implode(',', $emails);
    $ok = $config->set('notify_user_creation_emails', $new, 'string');
    echo $ok ? "Added $recipient to notify_user_creation_emails\n" : "Failed to update config\n";
} else {
    echo "$recipient already present in notify_user_creation_emails\n";
}

// Find the most recent user_created_via_invite entry
$stmt = $db->prepare("SELECT * FROM activity_log WHERE action = 'user_created_via_invite' ORDER BY created_at DESC LIMIT 1");
$stmt->execute();
$act = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$act) {
    echo "No recent user_created_via_invite activity found. Aborting send.\n";
    exit(2);
}
$userId = $act['entity_id'];
// Load user
$stmt = $db->prepare('SELECT id, username, email, full_name, role FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo "User id $userId not found.\n";
    exit(3);
}

// Build notification body (match invite.php format)
$siteName = $config->get('site_name', 'Mimir');
$fromEmail = $config->get('email_from_address', 'noreply@localhost');
$fromName = $config->get('email_from_name', $siteName);
$subject = "Nuevo usuario creado — " . $siteName;
$body = '<div style="font-family: Arial, sans-serif; max-width:600px; margin:0 auto;">';
$body .= '<h3>Nuevo usuario creado</h3>';
$body .= '<ul>';
$body .= '<li><strong>Usuario:</strong> ' . htmlspecialchars($user['username']) . '</li>';
$body .= '<li><strong>Email:</strong> ' . htmlspecialchars($user['email']) . '</li>';
if (!empty($user['full_name'])) $body .= '<li><strong>Nombre completo:</strong> ' . htmlspecialchars($user['full_name']) . '</li>';
$body .= '<li><strong>Rol:</strong> ' . htmlspecialchars($user['role'] ?? 'user') . '</li>';
$body .= '</ul>';
$body .= '<p>Este usuario se ha creado mediante una invitación.</p>';
$body .= '</div>';

$emailSender = new Notification();
$ok = $emailSender->send($recipient, $subject, $body, ['from_email' => $fromEmail, 'from_name' => $fromName]);
if ($ok) {
    echo "Notification sent to $recipient\n";
    $logger->log(null, 'notif_user_created_sent', 'notification', $userId, "Notification sent to {$recipient}");
    exit(0);
} else {
    echo "Notification failed to send to $recipient\n";
    $logger->log(null, 'notif_user_created_failed', 'notification', $userId, "Notification failed to {$recipient}");
    exit(4);
}
