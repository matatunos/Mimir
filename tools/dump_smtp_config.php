<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';

$c = new Config();
$keys = ['smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_password','email_from_address','email_from_name','enable_email'];
foreach ($keys as $k) {
    $val = $c->get($k, null);
    if ($k === 'smtp_password' && is_string($val) && strpos($val, 'ENC:') === 0) {
        $val = '[ENCRYPTED]';
    }
    echo $k . ': ' . var_export($val, true) . "\n";
}
