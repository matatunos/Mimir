<?php
/**
 * Lightweight Email helper
 * - Uses SMTP if configured in `config` (smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password)
 * - Falls back to PHP `mail()` when SMTP not configured or on error
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/../includes/database.php';

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
            $body = $body . "<div style=\"margin-top:1rem; border-top:1px solid #e0e0e0; padding-top:0.75rem;\">" . $signature . "</div>";
        }

        $enabled = (bool)$this->config->get('enable_email', '0');
        if (!$enabled) {
            // Still attempt mail() as fallback to avoid silent failures
            $res = $this->sendMailFunction($to, $subject, $body, $options);
            $logger->log($actor, $res ? 'email_sent' : 'email_failed', 'email', null, ($res ? "Sent email to {$to}" : "Failed to send email to {$to}"), ['to' => $to, 'subject' => $subject, 'method' => 'mail']);
            try {
                $this->auditEmailEvent($actor, $to, $subject, $res ? 'email_sent' : 'email_failed', 'mail');
            } catch (Throwable $e) {
                error_log('Email::send audit error: ' . $e->getMessage());
            }
            return $res;
        }

        $host = $this->config->get('smtp_host', '');
        $port = intval($this->config->get('smtp_port', 587));
        $enc = $this->config->get('smtp_encryption', 'tls'); // 'tls'|'ssl'|''
        $user = $this->config->get('smtp_username', '');
        $pass = $this->config->get('smtp_password', '');
        $pass_decryption_failed = false;

        // If password is stored encrypted (ENC:<iv>:<ciphertext>), attempt to decrypt.
        // If decryption fails, set to empty to avoid attempting an invalid auth payload.
        if (is_string($pass) && strpos($pass, 'ENC:') === 0) {
            $decoded = $this->decryptConfigValue($pass);
            if ($decoded !== false) {
                $pass = $decoded;
            } else {
                error_log('Email::send: smtp_password decryption failed; treating as empty');
                // mark the fact so we can provide a clearer, less alarming message later
                $pass = '';
                $pass_decryption_failed = true;
            }
        }

        // Prepare headers and message
        $fromEmail = $options['from_email'] ?? $this->config->get('email_from_address', '');
        $fromName = $options['from_name'] ?? $this->config->get('email_from_name', '');
        $headers = [];
        $headers[] = 'From: ' . ($fromName ? ($fromName . ' <' . $fromEmail . '>') : $fromEmail);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'X-Mailer: Mimir PHP/' . phpversion();

        // Try SMTP with primary settings; on failure attempt fallback to 465/ssl once
        try {
            $this->smtpSendRaw($host, $port, $enc, $user, $pass, $fromEmail, $to, $subject, $body, $headers, $pass_decryption_failed);
            $logger->log($actor, 'email_sent', 'email', null, "Sent email to {$to} via SMTP", ['to' => $to, 'subject' => $subject, 'method' => 'smtp', 'from' => $fromEmail]);
            try { $this->auditEmailEvent($actor, $to, $subject, 'email_sent', 'smtp'); } catch (Throwable $e) {}
            return true;
        } catch (Exception $e) {
            // Try fallback SSL/465 if not already using it
            try {
                if (!($enc === 'ssl' || $port === 465)) {
                    $fallbackPort = 465;
                    $fallbackEnc = 'ssl';
                    $this->smtpSendRaw($host, $fallbackPort, $fallbackEnc, $user, $pass, $fromEmail, $to, $subject, $body, $headers, $pass_decryption_failed);
                    $logger->log($actor, 'email_sent', 'email', null, "Sent email to {$to} via SMTP fallback", ['to' => $to, 'subject' => $subject, 'method' => 'smtp', 'from' => $fromEmail, 'fallback' => '465/ssl']);
                    try { $this->auditEmailEvent($actor, $to, $subject, 'email_sent', 'smtp'); } catch (Throwable $e) {}
                    return true;
                }
            } catch (Exception $e2) {
                error_log('Email::send SMTP fallback error: ' . $e2->getMessage());
            }

            // Do not fallback to mail() (sendmail); record failure and return false
            error_log('Email::send SMTP error: ' . $e->getMessage());
            $logger->log($actor, 'email_failed', 'email', null, "Failed to send email to {$to} via SMTP: " . $e->getMessage(), ['to' => $to, 'subject' => $subject, 'method' => 'smtp']);
            try { $this->auditEmailEvent($actor, $to, $subject, 'email_failed', 'smtp', ['error' => $e->getMessage()]); } catch (Throwable $e) {}
            return false;
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
    private function smtpSendRaw($host, $port, $enc, $user, $pass, $fromEmail, $to, $subject, $body, $headers, $pass_decryption_failed = false) {
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

        // If server advertises AUTH but we have a username without a usable password,
        // previously we aborted to avoid unauthenticated MAIL FROM. That linkage between
        // `smtp_username` and `smtp_password` is intentionally relaxed: log a warning
        // and proceed without attempting AUTH (caller may rely on anonymous sending
        // or relay rules on the mail server).
        if (stripos($ehlo, 'AUTH') !== false && !empty($user) && empty($pass)) {
            $this->smtpLog('C: WARNING - server advertises AUTH but no SMTP password configured for user ' . $user . '; proceeding without auth');
            try {
                $logger = new Logger();
                $msg = 'SMTP server advertises AUTH but no SMTP password configured; proceeding without authentication';
                if (!empty($pass_decryption_failed)) {
                    $msg .= ' (encrypted password present but decryption failed; check .secrets/smtp_key)';
                }
                $logger->log(null, 'email_config_missing_credentials', 'email', null, $msg, ['smtp_user' => $user, 'host' => $host]);
            } catch (Throwable $e) {
                error_log('Email::send logger failure: ' . $e->getMessage());
            }
            // continue without auth
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
            try {
                $this->auditEmailEvent($actor, $to, $subject, $res ? 'email_sent' : 'email_failed', 'mail');
            } catch (Throwable $e) {
                error_log('Email::send audit error: ' . $e->getMessage());
            }
            return $res;
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
    /**
     * Audit email send/failure in the security_events table.
     * Ensures the enum supports email event types and inserts a row.
     */
    private function auditEmailEvent($userId, $to, $subject, $eventType, $method = 'smtp', $details = []) {
        try {
            $db = Database::getInstance()->getConnection();

            // Ensure enum contains the event types 'email_sent' and 'email_failed'
            $stmt = $db->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_events' AND COLUMN_NAME = 'event_type'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $colType = $row['COLUMN_TYPE'];
                if (strpos($colType, "'email_sent'") === false || strpos($colType, "'email_failed'") === false) {
                    preg_match_all("/'([^']+)'/", $colType, $m);
                    $values = $m[1];
                    if (!in_array('email_sent', $values)) $values[] = 'email_sent';
                    if (!in_array('email_failed', $values)) $values[] = 'email_failed';
                    $enumList = implode("','", $values);
                    $sql = "ALTER TABLE security_events MODIFY event_type ENUM('" . $enumList . "') NOT NULL";
                    $db->exec($sql);
                }
            }

            $severity = ($eventType === 'email_sent') ? 'low' : 'medium';
            $description = ($eventType === 'email_sent') ? "Email sent to {$to}" : "Email failed to send to {$to}";
            $detailsArr = array_merge(['to' => $to, 'subject' => $subject, 'method' => $method], $details ?: []);

            $ins = $db->prepare("INSERT INTO security_events (event_type, username, severity, user_id, ip_address, user_agent, description, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([
                $eventType,
                $to,
                $severity,
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $description,
                json_encode($detailsArr)
            ]);
            return true;
        } catch (Exception $e) {
            error_log('Email::auditEmailEvent error: ' . $e->getMessage());
            return false;
        }
    }

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
