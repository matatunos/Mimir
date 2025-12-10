<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../classes/Logger.php';

// This script marks files as expired based on their creation date and the configured threshold.
// Usage: php tools/expire_files.php

$config = new Config();
$days = (int)$config->get('file_expire_days', 180); // default 180 days
if ($days <= 0) {
    echo "Expiration disabled (file_expire_days <= 0)\n";
    exit(0);
}

$db = Database::getInstance()->getConnection();

// Find files older than threshold that are not marked 'never_expire' and not already expired
$sql = "UPDATE files SET is_expired = 1, expired_at = NOW()\n        WHERE is_expired = 0 AND never_expire = 0\n        AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
$stmt = $db->prepare($sql);
$stmt->execute([$days]);
$updated = $stmt->rowCount();

echo "Expired files updated: $updated\n";

// Optional: log to system log table or activity_log
try {
    $logger = new Logger();
    if ($updated > 0) {
        $logger->log(null, 'files_expired', null, null, "Expired $updated files older than $days days");
    }
} catch (Exception $e) {
    // ignore logging errors in CLI
}

return 0;
