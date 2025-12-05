<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');
if (!Auth::isLoggedIn()) {
    echo json_encode(['success'=>false, 'error'=>'No autenticado']);
    exit;
}
if (!Auth::isAdmin()) {
    echo json_encode(['success'=>false, 'error'=>'No autorizado']);
    exit;
}

use OTPHP\TOTP;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

$db = Database::getInstance()->getConnection();

$action = $_GET['action'] ?? '';
$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$regenerate = isset($_GET['regenerate']);

if ($action === 'block' && $userId > 0) {
    // Desactivar 2FA para el usuario
    $db->prepare('UPDATE users SET twofa_secret = NULL, twofa_enabled = 0 WHERE id = ?')->execute([$userId]);
    echo json_encode(['success'=>true]);
    exit;
}
if ($action === 'generate' && $userId > 0) {
    $stmt = $db->prepare('SELECT id, username, email, twofa_secret FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        echo json_encode(['success'=>false, 'error'=>'Usuario no encontrado']);
        exit;
    }
    $secret = $user['twofa_secret'];
    if (!$secret || $regenerate) {
        $totp = TOTP::create();
        $secret = $totp->getSecret();
        $db->prepare('UPDATE users SET twofa_secret = ?, twofa_enabled = 1 WHERE id = ?')->execute([$secret, $userId]);
    }
    $label = $user['username'] . ' @ Mimir';
    $issuer = 'Mimir';
    $totp = TOTP::create($secret);
    $totp->setLabel($label);
    $totp->setIssuer($issuer);
    $uri = $totp->getProvisioningUri();
    $qrUrl = 'data:image/png;base64,';
    try {
        $qr = new QrCode($uri);
        $writer = new PngWriter();
        $result = $writer->write($qr);
        $qrUrl .= base64_encode($result->getString());
    } catch (Exception $e) {
        echo json_encode(['success'=>false, 'error'=>'Error generando QR']);
        exit;
    }
    echo json_encode([
        'success'=>true,
        'secret'=>$secret,
        'qr_url'=>$qrUrl
    ]);
    exit;
}
echo json_encode(['success'=>false, 'error'=>'Acción inválida']);
