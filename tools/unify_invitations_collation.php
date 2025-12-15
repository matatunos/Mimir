<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

try {
    $db = Database::getInstance()->getConnection();

    // Convert entire invitations table to utf8mb4_unicode_ci
    $sql = "ALTER TABLE invitations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    $db->exec($sql);

    // Ensure the email column specifically is varchar(255) with the same collation
    $sql2 = "ALTER TABLE invitations MODIFY email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    $db->exec($sql2);

    echo "Converted invitations table and column collation to utf8mb4_unicode_ci\n";
    exit(0);
} catch (Exception $e) {
    echo "Error converting collation: " . $e->getMessage() . "\n";
    exit(1);
}

?>