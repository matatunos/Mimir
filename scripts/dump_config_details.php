<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';
$c = new Config();
$keys = [
    'duo_api_hostname','duo_client_secret','2fa_device_trust_days','smtp_username','smtp_encryption','enable_duo','action','filename'
];
$details = $c->getAllDetails();
$map = [];
foreach ($details as $d) $map[$d['config_key']] = $d;
foreach ($keys as $k) {
    if (isset($map[$k])) {
        echo "$k\tDESC:" . (isset($map[$k]['description']) ? $map[$k]['description'] : '<NULL>') . "\tVALUE:" . $map[$k]['config_value'] . "\n";
    } else {
        echo "$k\tMISSING\n";
    }
}
?>