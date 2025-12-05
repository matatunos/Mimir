<?php
// Minimal PHP QR code generator for TOTP (Google Authenticator)
// https://github.com/endroid/qr-code is a good alternative for production
require_once __DIR__ . '/../vendor/autoload.php';
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

if (!isset($_GET['secret']) || !isset($_GET['label'])) {
    http_response_code(400);
    exit('Missing parameters');
}
$secret = $_GET['secret'];
$label = $_GET['label'];
$issuer = $_GET['issuer'] ?? 'Mimir';

$uri = sprintf('otpauth://totp/%s?secret=%s&issuer=%s', urlencode($label), $secret, urlencode($issuer));

$qr = QrCode::create($uri);
$writer = new PngWriter();
$result = $writer->write($qr);
header('Content-Type: image/png');
echo $result->getString();
