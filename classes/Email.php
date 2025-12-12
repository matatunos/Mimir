<?php
/**
 * Lightweight Email helper
 * - Uses SMTP if configured in `config` (smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password)
 * - Falls back to PHP `mail()` when SMTP not configured or on error
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Logger.php';

class Email {
    private $config;

    public function __construct() {
        $this->config = new Config();
    }

    /**
     * Send an email
     * @param string $to
     * @param string $subject
     * @param string $body HTML body
     * @param array $options ['from_email','from_name']
     * @return bool
     */
    public function send($to, $subject, $body, $options = []) {
        $logger = new Logger();
        $actor = $_SESSION['user_id'] ?? null;

        // Append configured email signature (HTML) to all outgoing HTML emails
        $signature = $this->config->get('email_signature', '');
        if (!empty($signature)) {
            // Ensure signature is wrapped so it visually separates from the message
            $body = $body . "<div style=\"margin-top:1rem; border-top:1px solid #e0e0e0; padding-top:0.75rem;\">" . $signature . "</div>";
        }

        $enabled = $this->config->get('enable_email', '0');
        if ($enabled === '0' || $enabled === 0) {
            // Still attempt mail() as fallback to avoid silent failures
            $res = $this->sendMailFunction($to, $subject, $body, $options);
            $logger->log($actor, $res ? 'email_sent' : 'email_failed', 'email', null, ($res ? "Sent email to {$to}" : "Failed to send email to {$to}"), ['to' => $to, 'subject' => $subject, 'method' => 'mail']);
            return $res;
        }

        $host = $this->config->get('smtp_host', '');
        $port = intval($this->config->get('smtp_port', 587));
        $enc = $this->config->get('smtp_encryption', 'tls'); // 'tls'|'ssl'|''
        $user = $this->config->get('smtp_username', '');
        $pass = $this->config->get('smtp_password', '');

        if (empty($host)) {
            $res = $this->sendMailFunction($to, $subject, $body, $options);
            $logger->log($actor, $res ? 'email_sent' : 'email_failed', 'email', null, ($res ? "Sent email to {$to}" : "Failed to send email to {$to}"), ['to' => $to, 'subject' => $subject, 'method' => 'mail']);
            return $res;
        }

        // Prepare headers and message
        $fromEmail = $options['from_email'] ?? $this->config->get('email_from_address', '');
        $fromName = $options['from_name'] ?? $this->config->get('email_from_name', '');
        if (empty($fromEmail)) $fromEmail = 'noreply@localhost';

        $headers = [];
        $headers[] = 'From: ' . ($fromName ? ($fromName . ' <' . $fromEmail . '>') : $fromEmail);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'X-Mailer: Mimir PHP/' . phpversion();

        // Try SMTP
        try {
            $timeout = 10;
            $connected = false;
            $fp = null;

            if ($enc === 'ssl' || $port === 465) {
                $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, $timeout);
                if ($fp) { $connected = true; stream_set_timeout($fp, $timeout); }
            } else {
                $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout);
                if ($fp) { $connected = true; stream_set_timeout($fp, $timeout); }
            }

            if (!$connected || !$fp) {
                throw new Exception('SMTP connect failed: ' . ($errstr ?? ''));
            }

            $this->smtpGetLine($fp); // banner
            $this->smtpSend($fp, "EHLO " . gethostname());
            $ehlo = $this->smtpReadMultiline($fp);

            if ($enc === 'tls') {
                if (stripos($ehlo, 'STARTTLS') !== false) {
                    $this->smtpSend($fp, "STARTTLS");
                    $resp = $this->smtpGetLine($fp);
                    if (strpos($resp, '220') === 0) {
                        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                            throw new Exception('STARTTLS negotiation failed');
                        }
                        // re-EHLO
                        $this->smtpSend($fp, "EHLO " . gethostname());
                        $ehlo = $this->smtpReadMultiline($fp);
                    }
                }
            }

            // AUTH if credentials provided
            if (!empty($user) && !empty($pass)) {
                $plain = base64_encode("\0" . $user . "\0" . $pass);
                $this->smtpSend($fp, "AUTH PLAIN $plain");
                $resp = $this->smtpGetLine($fp);
                if (strpos($resp, '235') !== 0) {
                    // try LOGIN
                    $this->smtpSend($fp, "AUTH LOGIN");
                    $step = $this->smtpGetLine($fp);
                    if (strpos($step, '334') === 0) {
                        $this->smtpSend($fp, base64_encode($user));
                        $this->smtpGetLine($fp);
                        $this->smtpSend($fp, base64_encode($pass));
                        $final = $this->smtpGetLine($fp);
                        if (strpos($final, '235') !== 0) {
                            throw new Exception('SMTP auth failed');
                        }
                    }
                }
            }

            // MAIL FROM
            $this->smtpSend($fp, 'MAIL FROM:<' . $fromEmail . '>');
            $this->smtpGetLine($fp);
            // RCPT TO
            $this->smtpSend($fp, 'RCPT TO:<' . $to . '>');
            $this->smtpGetLine($fp);
            // DATA
            $this->smtpSend($fp, 'DATA');
            $this->smtpGetLine($fp);

            // Construct message
            $message = "Subject: " . $this->escapeHeader($subject) . "\r\n";
            foreach ($headers as $h) { $message .= $h . "\r\n"; }
            $message .= "\r\n";
            $message .= $body . "\r\n";
            $message .= ".\r\n";

            fwrite($fp, $message);
            $this->smtpGetLine($fp);

            $this->smtpSend($fp, 'QUIT');
            fclose($fp);

            // Log SMTP send
            $logger->log($actor, 'email_sent', 'email', null, "Sent email to {$to} via SMTP", ['to' => $to, 'subject' => $subject, 'method' => 'smtp', 'from' => $fromEmail]);

            return true;

        } catch (Exception $e) {
            // Fallback to mail()
            error_log('Email::send SMTP error: ' . $e->getMessage());
            $res = $this->sendMailFunction($to, $subject, $body, $options);
            $logger->log($actor, $res ? 'email_sent' : 'email_failed', 'email', null, ($res ? "Sent email to {$to} via mail() after SMTP failure" : "Failed to send email to {$to} after SMTP failure"), ['to' => $to, 'subject' => $subject, 'method' => 'mail', 'error' => $e->getMessage()]);
            return $res;
        }
    }

    private function escapeHeader($s) {
        return str_replace(["\r", "\n"], '', $s);
    }

    private function sendMailFunction($to, $subject, $body, $options = []) {
        $logger = new Logger();
        $actor = $_SESSION['user_id'] ?? null;

        // Also append signature for the mail() fallback path in case caller passed raw body
        $signature = $this->config->get('email_signature', '');
        if (!empty($signature)) {
            $body = $body . "\n\n" . strip_tags($signature);
        }

        $fromEmail = $options['from_email'] ?? $this->config->get('email_from_address', 'noreply@localhost');
        $fromName = $options['from_name'] ?? $this->config->get('email_from_name', 'Mimir');
        $headers = "From: " . ($fromName ? ($fromName . ' <' . $fromEmail . '>') : $fromEmail) . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: Mimir PHP/" . phpversion();
        $res = @mail($to, $subject, $body, $headers);
        $logger->log($actor, $res ? 'email_sent' : 'email_failed', 'email', null, ($res ? "Sent email to {$to} via mail()" : "Failed to send email to {$to} via mail()"), ['to' => $to, 'subject' => $subject, 'method' => 'mail', 'from' => $fromEmail]);
        return $res;
    }

    private function smtpSend($fp, $line) {
        fwrite($fp, $line . "\r\n");
    }

    private function smtpGetLine($fp) {
        $res = rtrim(fgets($fp, 512));
        return $res;
    }

    private function smtpReadMultiline($fp) {
        $out = '';
        $start = microtime(true);
        while (!feof($fp)) {
            $line = fgets($fp, 512);
            if ($line === false) break;
            $out .= $line;
            if (preg_match('/^[0-9]{3} /', $line)) break;
            if ((microtime(true) - $start) > 5) break;
        }
        return $out;
    }
}
