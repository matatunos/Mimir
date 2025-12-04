<?php
/**
 * Setup script - Run this once to initialize the database
 */

// Check if config file exists
if (!file_exists(__DIR__ . '/config/config.php')) {
    echo "ERROR: config/config.php not found!\n";
    echo "Please copy config/config.example.php to config/config.php and configure it.\n";
    echo "\nExample:\n";
    echo "  cp config/config.example.php config/config.php\n";
    echo "  nano config/config.php\n";
    exit(1);
}

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
                // Check for specific error codes
                $errorCode = $e->getCode();
                // 23000 = Integrity constraint violation (includes duplicates)
                // 42S01 = Table already exists
                if ($errorCode == '23000' || $errorCode == '42S01') {
                    // Ignore these as they indicate schema already exists
                    continue;
                } else {
                    echo "Warning: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    
    // Create default admin user
    echo "Creating default admin user...\n";
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE username = 'admin'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] == 0) {
            // Create admin user with hashed password
            $adminPassword = 'admin123';
            $adminPasswordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, ?, 'admin', 1)");
            $stmt->execute(['admin', 'admin@mimir.local', $adminPasswordHash]);
            
            echo "\n========================================\n";
            echo "Setup completed successfully!\n";
            echo "========================================\n\n";
            echo "Default admin credentials:\n";
            echo "Username: admin\n";
            echo "Password: admin123\n";
            echo "\nIMPORTANT: Please change the admin password after first login!\n\n";
        } else {
            echo "\n========================================\n";
            echo "Setup completed successfully!\n";
            echo "========================================\n\n";
            echo "Admin user already exists.\n\n";
        }
    } catch (PDOException $e) {
        echo "Note: Could not create admin user: " . $e->getMessage() . "\n";
        echo "You may need to create an admin user manually.\n\n";
    }
    
    echo "You can now access the application at: " . BASE_URL . "\n";
    
} catch (PDOException $e) {
    echo "\nERROR: " . $e->getMessage() . "\n\n";
    echo "Please check your database configuration in config/config.php\n";
    exit(1);
}
