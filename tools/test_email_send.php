<?php
// CLI script to test Email::send()
// Usage: php test_email_send.php recipient@example.com [--verbose]

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../classes/Notification.php';

$argv0 = $argv[0] ?? 'test_email_send.php';
$to = $argv[1] ?? null;
$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

if (!$to) {
    echo "Usage: php {$argv0} recipient@example.com [--verbose]\n";
    exit(1);
}

$config = new Config();
$enabled = (bool)$config->get('enable_email', '0');
$smtpHost = $config->get('smtp_host', '');
$smtpPort = $config->get('smtp_port', '');
$smtpEnc = $config->get('smtp_encryption', '');
$from = $config->get('email_from_address', 'noreply@localhost');
$fromName = $config->get('email_from_name', 'Mimir');

if ($verbose) {
    echo "Configuration:\n";
    echo "  enable_email: " . ($enabled ? '1' : '0') . "\n";
    echo "  smtp_host: {$smtpHost}\n";
    echo "  smtp_port: {$smtpPort}\n";
    echo "  smtp_encryption: {$smtpEnc}\n";
    echo "  from: {$from} ({$fromName})\n\n";
}

$subject = 'Prueba de correo desde Mimir - ' . date('c');
$body = '<div style="font-family: Arial, sans-serif;"><p>Esta es una prueba de envío de correo desde la instalación de Mimir.</p><p>Fecha: ' . date('c') . '</p></div>';

$email = new Notification();
try {
    $ok = $email->send($to, $subject, $body, ['from_email' => $from, 'from_name' => $fromName]);
    if ($ok) {
        echo "Envío OK a {$to}\n";
        exit(0);
    } else {
        echo "Envío FALLIDO (Email::send returned false)\n";
        exit(2);
    }
} catch (Exception $e) {
    echo "Excepción al enviar: " . $e->getMessage() . "\n";
    exit(3);
}
