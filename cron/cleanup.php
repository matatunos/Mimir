<?php
/**
 * Cleanup script - Run this via cron job
 * Example cron: 0 * * * * php /path/to/cleanup.php
 */

require_once __DIR__ . '/../includes/init.php';

echo "Running cleanup tasks...\n";

// Clean up expired files
echo "Cleaning up expired files...\n";
$expiredFiles = FileManager::cleanupExpiredFiles();
echo "Removed $expiredFiles expired files.\n";

// Clean up expired shares
echo "Cleaning up expired shares...\n";
$expiredShares = ShareManager::cleanupExpiredShares();
echo "Deactivated $expiredShares expired shares.\n";

echo "Cleanup complete!\n";
