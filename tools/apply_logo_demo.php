<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../classes/ColorExtractor.php';

// Use existing logo in uploads/branding if present
$src = __DIR__ . '/../public/uploads/branding/logo_1765313118.png';
if (!file_exists($src)) {
    echo "Source logo not found: $src\n";
    exit(1);
}

$dstName = 'logo_demo_' . time() . '.png';
$dstRel = 'uploads/branding/' . $dstName;
$dst = __DIR__ . '/../public/' . $dstRel;

if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0755, true);
if (!@copy($src, $dst)) {
    echo "Failed to copy logo to $dst\n";
    exit(1);
}

$config = new Config();
$ce = new ColorExtractor();

try {
    $fullPath = realpath($dst);
    $colors = $ce->extractBrandColors($fullPath);
    // Update config
    $config->set('site_logo', $dstRel);
    $config->set('brand_primary_color', $colors['primary']);
    $config->set('brand_secondary_color', $colors['secondary']);
    $config->set('brand_accent_color', $colors['accent']);

    echo "Logo applied: $dstRel\n";
    echo "Colors extracted: Primary={$colors['primary']} Secondary={$colors['secondary']} Accent={$colors['accent']}\n";
    exit(0);
} catch (Exception $e) {
    echo "Color extraction failed: " . $e->getMessage() . "\n";
    exit(2);
}
