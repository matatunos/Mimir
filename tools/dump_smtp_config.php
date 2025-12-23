<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';

try {
    $cfg = new Config();
    $keys = ['enable_email','email_from_address','email_from_name','smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_password','email_signature'];
    $out = [];
    foreach ($keys as $k) {
        $v = $cfg->get($k, null);
        if ($k === 'smtp_password' && is_string($v) && strlen($v) > 0) {
            $v = '[REDACTED]';
        }
        $out[$k] = $v;
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
