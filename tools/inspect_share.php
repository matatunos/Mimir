<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Share.php';

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT share_token FROM shares ORDER BY created_at DESC LIMIT 1");
$stmt->execute();
$token = $stmt->fetchColumn();
if (!$token) {
    echo "No shares found\n";
    exit(1);
}

$shareClass = new Share();
$share = $shareClass->getByToken($token);
var_export($share);
echo "\n";
