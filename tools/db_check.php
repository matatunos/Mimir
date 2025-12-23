<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT COUNT(*) as c FROM users");
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "users:" . ($c['c'] ?? '0') . "\n";
} catch (Exception $e) {
    echo "ERR:" . $e->getMessage() . "\n";
}
