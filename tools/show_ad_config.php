<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT config_key, config_value FROM config WHERE config_key IN ('enable_ldap','enable_ad','ad_host','ad_bind_dn','ad_bind_password','ad_base_dn','ad_admin_group_dn')");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows)) { echo "No AD/LDAP config rows found\n"; exit(0); }
foreach ($rows as $r) {
    echo "{$r['config_key']} = {$r['config_value']}\n";
}
