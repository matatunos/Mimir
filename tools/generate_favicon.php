<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';
// Include the config admin helper which defines generateFavicons
require_once __DIR__ . '/../public/admin/config.php';

$cfg = new Config();
$logoRel = $cfg->get('site_logo');
if (empty($logoRel)) {
    echo "No site_logo configured in config table\n";
    exit(1);
}

$logoFull = BASE_PATH . '/public/' . $logoRel;
if (!file_exists($logoFull)) {
    echo "Logo file not found: $logoFull\n";
    exit(2);
}

if (generateFavicons($logoFull)) {
    echo "Favicons generated in public/\n";
    exit(0);
} else {
    echo "Favicons generation failed\n";
    exit(3);
}
