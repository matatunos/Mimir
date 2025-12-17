<?php
/**
 * Invitation helper
 * - Create single-use invitation tokens
 * - Resend and revoke support
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Notification.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/TwoFactor.php';

class Invitation {
    private $db;
    private $logger;
    private $config;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->config = new Config();
    }

    /**
     * Create an invitation and return token
     */
    public function create($email, $inviterId = null, $options = []) {
        try {
            $token = bin2hex(random_bytes(24));
            $role = $options['role'] ?? 'user';
            $message = $options['message'] ?? null;
            // Normalize message: treat common placeholder texts or empty-only values as null
            if (!is_null($message)) {
                $message = trim((string)$message);
                $lower = strtolower(preg_replace('/[^a-z0-9\s\(\)\-]/i', '', $message));
                $placeholders = [
                    'mensaje opcional', 'mensaje (opcional)', 'opcional',
                    'optional message', 'message optional', 'message (optional)'
                ];
                if ($message === '' || in_array($lower, $placeholders, true)) {
                    $message = null;
                }
            }
            $expiresAt = null;
            // expiry can be provided in hours via options['expires_hours'] or via config key 'invitation_expires_hours'
            if (!empty($options['expires_hours'])) {
                $hours = intval($options['expires_hours']);
                $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $hours . ' hours'));
            } else {
                $cfgHours = intval($this->config->get('invitation_expires_hours', DEFAULT_INVITE_EXPIRES_HOURS));
                if ($cfgHours > 0) {
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $cfgHours . ' hours'));
                }
            }

            $forcedUsername = $options['forced_username'] ?? null;
            $force2fa = $options['force_2fa'] ?? 'none';

            $totpSecret = null;
            if ($force2fa === 'totp') {
                $twoFactor = new TwoFactor();
                $totpSecret = $twoFactor->generateSecret();
            }

            $stmt = $this->db->prepare("INSERT INTO invitations (token, email, inviter_id, role, message, expires_at, forced_username, force_2fa, totp_secret) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$token, $email, $inviterId, $role, $message, $expiresAt, $forcedUsername, $force2fa, $totpSecret]);
            $id = $this->db->lastInsertId();

            $this->logger->log($inviterId, 'invitation_created', 'invitation', $id, "Invitation created for {$email}");

            // Send invitation email if requested
            if (!empty($options['send_email'])) {
                $sent = $this->sendInviteEmail($email, $token, $message, $expiresAt);
                // Log result to activity_log for easier auditing
                try {
                    if ($sent) {
                        $this->logger->log($inviterId, 'invitation_email_sent', 'invitation', $id, "Invitation email sent to {$email}");
                    } else {
                        $this->logger->log($inviterId, 'invitation_email_failed', 'invitation', $id, "Invitation email failed to send to {$email}");
                    }
                } catch (Exception $e) {
                    error_log('Failed to record invitation email send result: ' . $e->getMessage());
                }
            }

            return $token;
        } catch (Exception $e) {
            error_log('Invitation create error: ' . $e->getMessage());
            return false;
        }
    }

    public function getByToken($token) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM invitations WHERE token = ? AND is_revoked = 0");
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            if (!empty($row['used_at'])) return null; // single-use
            if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) return null;
            return $row;
        } catch (Exception $e) {
            error_log('Invitation getByToken error: ' . $e->getMessage());
            return null;
        }
    }

    public function markUsed($invitationId, $userId) {
        try {
            $stmt = $this->db->prepare("UPDATE invitations SET used_at = NOW(), used_by = ? WHERE id = ?");
            $stmt->execute([$userId, $invitationId]);
            $this->logger->log($userId, 'invitation_used', 'invitation', $invitationId, "Invitation used by user ID {$userId}");
            return true;
        } catch (Exception $e) {
            error_log('Invitation markUsed error: ' . $e->getMessage());
            return false;
        }
    }

    public function revoke($invitationId, $byUserId = null) {
        try {
            $stmt = $this->db->prepare("UPDATE invitations SET is_revoked = 1 WHERE id = ?");
            $stmt->execute([$invitationId]);
            $this->logger->log($byUserId, 'invitation_revoked', 'invitation', $invitationId, "Invitation revoked");
            return true;
        } catch (Exception $e) {
            error_log('Invitation revoke error: ' . $e->getMessage());
            return false;
        }
    }

    public function resend($invitationId) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM invitations WHERE id = ?");
            $stmt->execute([$invitationId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;
            if (!empty($row['used_at']) || $row['is_revoked']) return false;
            $this->sendInviteEmail($row['email'], $row['token'], $row['message']);
            $this->logger->log($_SESSION['user_id'] ?? null, 'invitation_resent', 'invitation', $invitationId, "Invitation resent to {$row['email']}");
            return true;
        } catch (Exception $e) {
            error_log('Invitation resend error: ' . $e->getMessage());
            return false;
        }
    }

    private function sendInviteEmail($email, $token, $message = null, $expiresAt = null) {
        try {
            $base = rtrim((string)($this->config->get('base_url') ?? BASE_URL), '/');
            $link = $base . '/invite.php?token=' . urlencode($token);
            // Load invitation record to include any TOTP secret / forced username in the email
            $stmt = $this->db->prepare("SELECT * FROM invitations WHERE token = ? LIMIT 1");
            $stmt->execute([$token]);
            $invRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $siteName = $this->config->get('site_name', 'Mimir');
            $subject = 'Invitación a ' . $siteName;

            $html = '<div style="font-family: Arial, sans-serif;">';
            $html .= '<p>Has sido invitado a unirte a ' . htmlspecialchars($siteName) . '.</p>';
            // Only include inviter message if it's non-empty and not a placeholder
            $includeMessage = false;
            if (!empty($message)) {
                $m = trim((string)$message);
                $mLower = strtolower(preg_replace('/[^a-z0-9\s\(\)\-]/i', '', $m));
                $placeholders = ['mensaje opcional', 'mensaje (opcional)', 'opcional', 'optional message', 'message optional', 'message (optional)'];
                if ($m !== '' && !in_array($mLower, $placeholders, true)) {
                    $includeMessage = true;
                }
            }
            if ($includeMessage) {
                $html .= '<p><strong>Mensaje del invitador:</strong><br>' . nl2br(htmlspecialchars($message)) . '</p>';
            }

            // Indicate username policy for this invitation
            if (!empty($invRow['forced_username'])) {
                $html .= '<p><strong>Nombre de usuario reservado:</strong> ' . htmlspecialchars($invRow['forced_username']) . '. Deberás usar este nombre al aceptar la invitación.</p>';
            } else {
                $html .= '<p><strong>Nombre de usuario:</strong> Podrás elegir tu nombre de usuario al aceptar la invitación.</p>';
            }

            // If invitation enforces 2FA (TOTP), inform user they will configure it after first login.
            if (!empty($invRow['force_2fa']) && $invRow['force_2fa'] === 'totp') {
                $html .= '<h3>Configuración 2FA (TOTP)</h3>';
                $html .= '<p>Esta invitación requiere configurar 2FA mediante TOTP. Por motivos de seguridad no incluimos el código TOTP en el correo; tras crear tu cuenta se te pedirá configurar la aplicación autenticadora (Google Authenticator, Microsoft Authenticator, Authy, FreeOTP) en la web.</p>';
            }
            $html .= '<p>Para aceptar la invitación y crear tu contraseña segura, visita el siguiente enlace (válido una sola vez):</p>';
            $html .= '<p><a href="' . $link . '">' . $link . '</a></p>';
            if (!empty($expiresAt)) {
                $html .= '<p><strong>Caduca:</strong> ' . htmlspecialchars($expiresAt) . ' (hora del servidor)</p>';
            } else {
                $html .= '<p>Este enlace expirará si no se usa dentro del periodo establecido.</p>';
            }
            $html .= '<p>Saludos,<br>El equipo</p>';
            $html .= '</div>';

            $emailSender = new Notification();

            // Write a short diagnostic line to invite_debug.log before sending
            try {
                $dpath = defined('LOGS_PATH') ? LOGS_PATH : (dirname(__DIR__) . '/storage/logs');
                if (!is_dir($dpath)) @mkdir($dpath, 0755, true);
                $dbgFile = $dpath . '/invite_debug.log';
                $line = date('c') . " | SEND_INVITE_ATTEMPT | to={$email} | token={$token} | subject=" . preg_replace('/\s+/', ' ', substr($subject,0,120)) . "\n";
                @file_put_contents($dbgFile, $line, FILE_APPEND | LOCK_EX);
            } catch (Throwable $e) {}

            $res = $emailSender->send($email, $subject, $html);

            // Record result to invite_debug.log and activity_log
            try {
                $dline = date('c') . ' | SEND_INVITE_RESULT | to=' . $email . ' | token=' . $token . ' | result=' . ($res ? 'OK' : 'FAIL') . "\n";
                @file_put_contents($dbgFile, $dline, FILE_APPEND | LOCK_EX);
            } catch (Throwable $e) {}

            return $res;
        } catch (Exception $e) {
            error_log('Invitation sendInviteEmail error: ' . $e->getMessage());
            try {
                $dpath = defined('LOGS_PATH') ? LOGS_PATH : (dirname(__DIR__) . '/storage/logs');
                $dbgFile = $dpath . '/invite_debug.log';
                @file_put_contents($dbgFile, date('c') . ' | SEND_INVITE_EXCEPTION | ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
            } catch (Throwable $e2) {}
            return false;
        }
    }
}
