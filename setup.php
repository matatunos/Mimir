<?php
/**
 * Setup script - Run this once to initialize the database
 */

// Load configuration
require_once __DIR__ . '/config/config.php';

echo "========================================\n";
echo "Mimir File Storage - Database Setup\n";
echo "========================================\n\n";

try {
    // Connect to database
    echo "Connecting to database...\n";
    $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    
    // Create database if it doesn't exist
    echo "Creating database if not exists...\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE " . DB_NAME);
    
    // Read and execute schema
    echo "Loading schema...\n";
    $schema = file_get_contents(__DIR__ . '/database/schema.sql');
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $schema)));
    
    echo "Executing schema statements...\n";
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                // Ignore errors for statements that may already exist (like INSERT)
                if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                    echo "Warning: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    
    echo "\n========================================\n";
    echo "Setup completed successfully!\n";
    echo "========================================\n\n";
    echo "Default admin credentials:\n";
    echo "Username: admin\n";
    echo "Password: admin123\n";
    echo "\nIMPORTANT: Please change the admin password after first login!\n\n";
    echo "You can now access the application at: " . BASE_URL . "\n";
    
} catch (PDOException $e) {
    echo "\nERROR: " . $e->getMessage() . "\n\n";
    echo "Please check your database configuration in config/config.php\n";
    exit(1);
}
