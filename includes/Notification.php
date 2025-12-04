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
    private static function sendEmail($to, $subject, $body) {
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
}
