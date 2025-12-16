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

        // If password is stored encrypted (ENC:<iv>:<ciphertext>), attempt to decrypt
        if (is_string($pass) && strpos($pass, 'ENC:') === 0) {
            $decoded = $this->decryptConfigValue($pass);
            if ($decoded !== false) $pass = $decoded;
        }

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

        // Try SMTP with primary settings; on failure attempt fallback to 465/ssl once
        try {
            $this->smtpSendRaw($host, $port, $enc, $user, $pass, $fromEmail, $to, $subject, $body, $headers);
            $logger->log($actor, 'email_sent', 'email', null, "Sent email to {$to} via SMTP", ['to' => $to, 'subject' => $subject, 'method' => 'smtp', 'from' => $fromEmail]);
            return true;
        } catch (Exception $e) {
            // Try fallback SSL/465 if not already using it
            try {
                if (!($enc === 'ssl' || $port === 465)) {
                    $fallbackPort = 465;
                    $fallbackEnc = 'ssl';
                    $this->smtpSendRaw($host, $fallbackPort, $fallbackEnc, $user, $pass, $fromEmail, $to, $subject, $body, $headers);
                    $logger->log($actor, 'email_sent', 'email', null, "Sent email to {$to} via SMTP fallback", ['to' => $to, 'subject' => $subject, 'method' => 'smtp', 'from' => $fromEmail, 'fallback' => '465/ssl']);
                    return true;
                }
            } catch (Exception $e2) {
                error_log('Email::send SMTP fallback error: ' . $e2->getMessage());
            }

            // Final fallback to mail()
            error_log('Email::send SMTP error: ' . $e->getMessage());
            $res = $this->sendMailFunction($to, $subject, $body, $options);
            $logger->log($actor, $res ? 'email_sent' : 'email_failed', 'email', null, ($res ? "Sent email to {$to} via mail() after SMTP failure" : "Failed to send email to {$to} after SMTP failure"), ['to' => $to, 'subject' => $subject, 'method' => 'mail', 'error' => $e->getMessage()]);
            return $res;
        }
    }

    /**
     * Decrypt a config value stored as ENC:<base64_iv>:<base64_cipher>
     * Returns plaintext or false on failure
     */
    private function decryptConfigValue($encValue) {
        $parts = explode(':', $encValue, 3);
        if (count($parts) !== 3) return false;
        list($_tag, $b64iv, $b64cipher) = $parts;
        $iv = base64_decode($b64iv);
        $cipher = base64_decode($b64cipher);
        $keyFile = rtrim(dirname(__DIR__), '/') . '/.secrets/smtp_key';
        if (!file_exists($keyFile)) return false;
        $key = trim(@file_get_contents($keyFile));
        if ($key === '') return false;
        $keyRaw = base64_decode($key);
        if ($keyRaw === false) return false;
        $plain = @openssl_decrypt($cipher, 'AES-256-CBC', $keyRaw, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? false : $plain;
    }

    /**
     * Perform the low-level SMTP send using provided connection parameters.
     * Throws Exception on failure.
     */
    private function smtpSendRaw($host, $port, $enc, $user, $pass, $fromEmail, $to, $subject, $body, $headers) {
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
        return true;
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
        // Log outgoing SMTP command (do not log sensitive auth payloads)
        $this->smtpLog('C: ' . preg_replace('/(AUTH PLAIN |AUTH LOGIN|AUTH LOGIN\r?\n)/i', '$0(REDACTED)', $line));
        fwrite($fp, $line . "\r\n");
    }

    private function smtpGetLine($fp) {
        $res = rtrim(fgets($fp, 512));
        if ($res !== false && $res !== '') $this->smtpLog('S: ' . $res);
        return $res;
    }

    private function smtpReadMultiline($fp) {
        $out = '';
        $start = microtime(true);
        while (!feof($fp)) {
            $line = fgets($fp, 512);
            if ($line === false) break;
            $out .= $line;
            $this->smtpLog('S: ' . rtrim($line));
            if (preg_match('/^[0-9]{3} /', $line)) break;
            if ((microtime(true) - $start) > 5) break;
        }
        return $out;
    }

    /**
     * Append SMTP debug lines to LOGS_PATH/smtp_debug.log
     * This is a temporary diagnostic helper; avoid logging secrets.
     */
    private function smtpLog($msg) {
        try {
            $path = defined('LOGS_PATH') ? LOGS_PATH : (dirname(__DIR__) . '/storage/logs');
            if (!is_dir($path)) @mkdir($path, 0755, true);
            $f = $path . '/smtp_debug.log';
            $line = date('c') . ' ' . $msg . "\n";
            @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // best-effort logging only
        }
    }
}
