<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$dn = $argv[1] ?? null;
if (!$dn) {
    echo "Usage: php tools/set_ad_admin_group.php \"<group DN>\"\n";
    exit(2);
}

try {
    $db = Database::getInstance()->getConnection();
    // Check if config key exists
    $stmt = $db->prepare("SELECT config_key FROM config WHERE config_key = 'ad_admin_group_dn' LIMIT 1");
    $stmt->execute();
    $exists = $stmt->fetch();
    if ($exists) {
        $u = $db->prepare("UPDATE config SET config_value = ? WHERE config_key = 'ad_admin_group_dn'");
        $u->execute([$dn]);
        echo "Updated ad_admin_group_dn to: $dn\n";
    } else {
        $i = $db->prepare("INSERT INTO config (config_key, config_value, type, description) VALUES ('ad_admin_group_dn', ?, 'string', 'AD admin group DN')");
        $i->execute([$dn]);
        echo "Inserted ad_admin_group_dn: $dn\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
