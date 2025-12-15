<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';

$cfg = new Config();
$logoRel = $cfg->get('site_logo');
if (empty($logoRel)) { echo "No site_logo configured\n"; exit(1); }
$logo = realpath(BASE_PATH . '/public/' . $logoRel);
if (!file_exists($logo)) { echo "Logo not found: $logo\n"; exit(2); }

// Use Imagick to build pngs and ico; fallback to GD for pngs
if (class_exists('Imagick')) {
    try {
        $sizes = [64,32,16];
        $ico = new Imagick();
        foreach ($sizes as $s) {
            $frame = new Imagick($logo);
            $frame->setImageBackgroundColor(new ImagickPixel('transparent'));
            $frame->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
            $frame->resizeImage($s, $s, Imagick::FILTER_LANCZOS, 1, true);
            $pngPath = BASE_PATH . "/public/favicon-{$s}x{$s}.png";
            $frame->setImageFormat('png32');
            $frame->writeImage($pngPath);
            $ico->addImage(clone $frame);
            $frame->clear(); $frame->destroy();
        }
        $ico->setFormat('ico');
        $icoPath = BASE_PATH . '/public/favicon.ico';
        // writeImages to ensure multi-image ico
        $ico->writeImages($icoPath, true);
        $ico->clear(); $ico->destroy();
        // Fix ownership for webserver
        @chown($icoPath, 'www-data'); @chgrp($icoPath, 'www-data');
        foreach ([32,16,64] as $s) { @chown(BASE_PATH . "/public/favicon-{$s}x{$s}.png", 'www-data'); @chgrp(BASE_PATH . "/public/favicon-{$s}x{$s}.png", 'www-data'); }
        echo "Favicons written (Imagick)\n";
        exit(0);
    } catch (Exception $e) {
        echo "Imagick generation failed: " . $e->getMessage() . "\n";
    }
}

// GD fallback for pngs only
if (extension_loaded('gd')) {
    list($w,$h) = getimagesize($logo);
    $src = null;
    $mime = mime_content_type($logo);
    switch ($mime) {
        case 'image/png': $src = imagecreatefrompng($logo); break;
        case 'image/jpeg': $src = imagecreatefromjpeg($logo); break;
        case 'image/gif': $src = imagecreatefromgif($logo); break;
        case 'image/webp': $src = imagecreatefromwebp($logo); break;
    }
    if ($src) {
        foreach ([32,16] as $s) {
            $dst = imagecreatetruecolor($s,$s);
            imagesavealpha($dst,true);
            $trans = imagecolorallocatealpha($dst,0,0,0,127);
            imagefill($dst,0,0,$trans);
            imagecopyresampled($dst,$src,0,0,0,0,$s,$s,$w,$h);
            $out = BASE_PATH . "/public/favicon-{$s}x{$s}.png";
            imagepng($dst,$out);
            @chown($out,'www-data'); @chgrp($out,'www-data');
            imagedestroy($dst);
        }
        imagedestroy($src);
        echo "Favicons written (GD PNGs only)\n";
        exit(0);
    }
}

echo "No method to create favicons available\n";
exit(3);
