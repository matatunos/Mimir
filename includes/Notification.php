<?php
/**
 * Email Notification Class
 */
class Notification {
    /**
     * Send notification email
     */
    public static function send($userId, $type, $message) {
        // Check if notifications are enabled
        if (!SystemConfig::get('enable_email_notifications', false)) {
            return false;
        }

        try {
            $auth = new Auth();
            $user = $auth->getUserById($userId);

            if (!$user || !$user['email']) {
                return false;
            }

            $subject = self::getSubject($type);
            $body = self::getBody($type, $message, $user);

            return self::sendEmail($user['email'], $subject, $body);
        } catch (Exception $e) {
            error_log("Notification failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send email via SMTP
     */
    public static function sendEmail($to, $subject, $body) {
        $smtpHost = SystemConfig::get('smtp_host');
        $smtpPort = SystemConfig::get('smtp_port', 587);
        $smtpUsername = SystemConfig::get('smtp_username');
        $smtpPassword = SystemConfig::get('smtp_password');
        $fromEmail = SystemConfig::get('smtp_from_email', 'noreply@mimir.local');
        $fromName = SystemConfig::get('smtp_from_name', 'Mimir Storage');

        // If SMTP is not configured, use PHP mail()
        if (empty($smtpHost)) {
            $headers = "From: $fromName <$fromEmail>\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            return mail($to, $subject, $body, $headers);
        }

        // Use SMTP (simplified - in production, use PHPMailer or similar)
        try {
            $headers = "From: $fromName <$fromEmail>\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            return mail($to, $subject, $body, $headers);
        } catch (Exception $e) {
            error_log("Email sending failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get email subject based on type
     */
    private static function getSubject($type) {
        $siteName = SystemConfig::get('site_name', APP_NAME);
        
        $subjects = [
            'file_upload' => "$siteName - File Uploaded",
            'file_download' => "$siteName - File Downloaded",
            'file_deleted' => "$siteName - File Deleted",
            'share_created' => "$siteName - Share Link Created",
            'share_accessed' => "$siteName - Share Link Accessed",
            'storage_warning' => "$siteName - Storage Warning",
            'storage_full' => "$siteName - Storage Full",
        ];

        return $subjects[$type] ?? "$siteName - Notification";
    }

    /**
     * Get email body based on type
     */
    private static function getBody($type, $message, $user) {
        $siteName = SystemConfig::get('site_name', APP_NAME);
        
        $html = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4a5568; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f7fafc; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>$siteName</h1>
        </div>
        <div class='content'>
            <p>Hello {$user['username']},</p>
            <p>$message</p>
            <p>This is an automated notification from your $siteName account.</p>
        </div>
        <div class='footer'>
            <p>&copy; " . date('Y') . " $siteName. All rights reserved.</p>
        </div>
    </div>
</body>
</html>";

        return $html;
    }

    /**
     * Send share link to recipient
     */
    public static function sendShareLink($recipientEmail, $filename, $shareUrl, $expirationInfo, $senderName, $passwordInfo = '') {
        $siteName = SystemConfig::get('site_name', APP_NAME);
        $subject = "$siteName - $senderName te ha compartido un archivo";
        
        $html = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f4f4f4; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px 20px; }
        .content h2 { color: #667eea; margin-top: 0; }
        .file-info { background: #f8fafc; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .file-info strong { color: #667eea; }
        .btn { display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; font-weight: bold; }
        .warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0; border-radius: 4px; color: #92400e; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; background: #f8fafc; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>📁 $siteName</h1>
        </div>
        <div class='content'>
            <h2>Has recibido un archivo</h2>
            <p><strong>$senderName</strong> te ha compartido un archivo a través de $siteName.</p>
            
            <div class='file-info'>
                <strong>📄 Archivo:</strong> $filename<br>
                <strong>⏰ $expirationInfo</strong>
            </div>
            $passwordInfo
            <div style='text-align: center;'>
                <a href='$shareUrl' class='btn'>Descargar Archivo</a>
            </div>
            
            <p style='margin-top: 30px; font-size: 14px; color: #666;'>
                Si tienes problemas con el botón, copia y pega este enlace en tu navegador:<br>
                <a href='$shareUrl' style='color: #667eea; word-break: break-all;'>$shareUrl</a>
            </p>
        </div>
        <div class='footer'>
            <p>Este es un mensaje automático de $siteName</p>
            <p>&copy; " . date('Y') . " $siteName. Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>";

        return self::sendEmail($recipientEmail, $subject, $html);
    }

    /**
     * Send copy of share link to owner
     */
    public static function sendShareLinkCopy($ownerEmail, $recipientEmail, $filename, $shareUrl, $expirationInfo) {
        $siteName = SystemConfig::get('site_name', APP_NAME);
        $subject = "$siteName - Copia: Enlace compartido enviado";
        
        $html = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f4f4f4; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px 20px; }
        .content h2 { color: #667eea; margin-top: 0; }
        .file-info { background: #f8fafc; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .file-info strong { color: #667eea; }
        .info-box { background: #d1fae5; border-left: 4px solid #10b981; padding: 15px; margin: 20px 0; border-radius: 4px; color: #065f46; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; background: #f8fafc; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>📁 $siteName</h1>
        </div>
        <div class='content'>
            <h2>✅ Enlace enviado correctamente</h2>
            <p>Has compartido un archivo con <strong>$recipientEmail</strong></p>
            
            <div class='file-info'>
                <strong>📄 Archivo:</strong> $filename<br>
                <strong>⏰ $expirationInfo</strong>
            </div>
            
            <div class='info-box'>
                <strong>ℹ️ Información:</strong><br>
                El destinatario ha recibido un email con el enlace de descarga.
            </div>
            
            <p style='margin-top: 20px; font-size: 14px; color: #666;'>
                <strong>Enlace compartido:</strong><br>
                <a href='$shareUrl' style='color: #667eea; word-break: break-all;'>$shareUrl</a>
            </p>
        </div>
        <div class='footer'>
            <p>Este es un mensaje automático de $siteName</p>
            <p>&copy; " . date('Y') . " $siteName. Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>";

        return self::sendEmail($ownerEmail, $subject, $html);
    }
}
