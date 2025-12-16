<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';
$c = new Config();
$keys = ['notify_user_creation_enabled','notify_user_creation_emails','notify_user_creation_to_admins','notify_user_creation_use_background_worker','notify_user_creation_retry_attempts','notify_user_creation_retry_delay_seconds'];
foreach ($keys as $k) {
    echo $k.': '.var_export($c->get($k), true)."\n";
}
