<?php
// Usage: php tools/resend_share_notification.php SHARE_ID recipient@example.com
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Share.php';
require_once __DIR__ . '/../classes/Notification.php';

$shareId = intval($argv[1] ?? 0);
$recipient = $argv[2] ?? null;
if (!$shareId || !$recipient) {
    echo "Usage: php tools/resend_share_notification.php SHARE_ID recipient@example.com\n";
    exit(1);
}

$shareClass = new Share();
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare('SELECT s.*, f.original_name, f.file_path, f.file_size, u.full_name as owner_name, u.email as owner_email FROM shares s JOIN files f ON s.file_id = f.id JOIN users u ON s.created_by = u.id WHERE s.id = ? LIMIT 1');
$stmt->execute([$shareId]);
$share = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$share) {
    echo "Share id $shareId not found\n";
    exit(2);
}

// Compose email similar to Share::create notification
$shareUrl = BASE_URL . '/s/' . ($share['share_token'] ?? '');
$cfgTool = new Config();
$siteNameTool = $cfgTool->get('site_name', '');
$subject = trim($siteNameTool) ? 'Se ha compartido un archivo — ' . $siteNameTool : 'Se ha compartido un archivo';

$ownerName = $share['owner_name'] ?? '';
$ownerEmail = $share['owner_email'] ?? '';
$brandPrimary = $cfgTool->get('brand_primary_color', '#667eea');
$brandAccent = $cfgTool->get('brand_accent_color', $brandPrimary);
$btnColor = $brandAccent;

$siteLogo = $cfgTool->get('site_logo', '');
$siteLogoUrl = '';
if (!empty($siteLogo)) {
    if (preg_match('#^https?://#i', $siteLogo)) { $siteLogoUrl = $siteLogo; }
    elseif (strpos($siteLogo, '/') === 0) { $siteLogoUrl = $siteLogo; }
    else { $siteLogoUrl = '/' . ltrim($siteLogo, '/'); }
}

$body = '<div style="font-family: Arial, sans-serif; max-width:600px; margin:0 auto; background:#ffffff; padding:18px; border-radius:8px; box-shadow:0 6px 18px rgba(0,0,0,0.06);">';
if ($siteLogoUrl || $siteNameTool) {
    $body .= '<div style="display:flex; align-items:center; justify-content:center; gap:12px; margin-bottom:12px;">';
    if ($siteLogoUrl) $body .= '<img src="' . $siteLogoUrl . '" alt="' . htmlspecialchars($siteNameTool ?: 'Site') . '" style="max-height:48px; margin-bottom:0;">';
    if ($siteNameTool) $body .= '<div style="font-size:14px;color:#333;font-weight:700;">' . htmlspecialchars($siteNameTool) . '</div>';
    $body .= '</div>';
}

$body .= '<div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">';
$body .= '<div style="width:48px;height:48px;border-radius:24px;background:' . $btnColor . ';display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:18px;">' . strtoupper(substr($ownerName ?: 'S',0,1)) . '</div>';
$body .= '<div>';
$body .= '<div>';
$body .= '<div style="font-size:16px;font-weight:700;color:#222;">' . htmlspecialchars($ownerName ?: 'Un usuario') . '</div>';
if (!empty($ownerEmail)) $body .= '<div style="font-size:12px;color:#666;">' . htmlspecialchars($ownerEmail) . '</div>';
$body .= '</div></div>';

$body .= '<h3 style="margin:0 0 8px 0;color:#222;font-size:18px;">Se ha compartido: ' . htmlspecialchars($share['original_name']) . '</h3>';
if (!empty($share['recipient_message'])) {
    $body .= '<div style="margin:8px 0 12px 0;color:#444;">' . nl2br(htmlspecialchars($share['recipient_message'])) . '</div>';
}
$body .= '<div style="text-align:center;margin:18px 0;">';
$body .= '<a href="' . $shareUrl . '" target="_blank" style="display:inline-block;padding:12px 22px;background:' . $btnColor . ';color:#000;text-decoration:none;border-radius:6px;font-weight:700;">Abrir enlace de descarga</a>';
$body .= '</div>';
$body .= '<div style="font-size:12px;color:#666;word-break:break-all;">Enlace directo: <a href="' . $shareUrl . '" target="_blank">' . $shareUrl . '</a></div>';
$body .= '<div style="margin-top:18px;font-size:12px;color:#999;">Si no ha solicitado este correo, ignórelo.</div>';
$body .= '</div>';

$email = new Notification();
try {
    $fromEmailCfg = $cfgTool->get('email_from_address', '');
    $fromNameCfg = $cfgTool->get('email_from_name', '');
    $ok = $email->send($recipient, $subject, $body, ['from_email' => $fromEmailCfg, 'from_name' => $fromNameCfg]);
    if ($ok) {
        echo "Notification sent to $recipient\n";
        exit(0);
    } else {
        echo "Email::send returned false\n";
        exit(3);
    }
} catch (Exception $e) {
    echo "Exception sending email: " . $e->getMessage() . "\n";
    exit(4);
}
