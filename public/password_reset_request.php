<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../classes/Email.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/ForensicLogger.php';

use PDO;

$cfg = new Config();
if (!$cfg->get('enable_email', '0')) {
    http_response_code(403);
    echo "Email sending is not enabled on this instance.";
    exit;
}

$error = '';
$success = '';

function anonymize_email($email) {
    // simple anonymization: keep first char of local, last char of local, mask middle, keep domain with partial masking
    $parts = explode('@', $email);
    if (count($parts) !== 2) return '****@****';
    $local = $parts[0];
    $domain = $parts[1];

    $localLen = strlen($local);
    if ($localLen <= 2) {
        $localMasked = substr($local, 0, 1) . str_repeat('*', max(1, $localLen - 1));
    } else {
        $localMasked = substr($local, 0, 1) . str_repeat('*', max(1, $localLen - 2)) . substr($local, -1);
    }

    // mask domain except first and last label char groups
    $domParts = explode('.', $domain);
    foreach ($domParts as &$dp) {
        $len = strlen($dp);
        if ($len <= 2) {
            $dp = substr($dp, 0, 1) . str_repeat('*', max(1, $len - 1));
        } else {
            $dp = substr($dp, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($dp, -1);
        }
    }
    $domainMasked = implode('.', $domParts);

    return $localMasked . '@' . $domainMasked;
}

function generate_fake_anonymized_email($username = null, $email = null) {
    // If a real email is provided and valid, anonymize it
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return anonymize_email($email);
    }

    // Deterministically generate a plausible-looking fake address based on the submitted username
    // so repeated requests for the same username return the same anonymized address.
    $seed = $username ?? 'anonymous';
    $h = sha1(strtolower($seed));

    // local part: use first and last char from username if available, otherwise derived from hash
    $cleanUser = preg_replace('/[^a-z0-9]/i', '', (string)$username);
    if (strlen($cleanUser) >= 2) {
        $localFirst = strtolower($cleanUser[0]);
        $localLast = strtolower($cleanUser[strlen($cleanUser)-1]);
    } else {
        $localFirst = $h[0];
        $localLast = $h[1];
    }

    // local length between 3 and 8 based on hash
    $localLen = 3 + (hexdec(substr($h, 0, 2)) % 6);
    $stars = str_repeat('*', max(1, $localLen - 2));
    $localMasked = $localFirst . $stars . $localLast;

    // choose domain from deterministic list using hash
    $domains = ['example.com','mail.example','example.org','domain.com','service.net'];
    $domIndex = hexdec(substr($h, 2, 2)) % count($domains);
    $d = $domains[$domIndex];
    $domParts = explode('.', $d);
    $maskedParts = [];
    foreach ($domParts as $p) {
        $len = strlen($p);
        if ($len <= 2) {
            $maskedParts[] = substr($p, 0, 1) . str_repeat('*', max(1, $len - 1));
        } else {
            $maskedParts[] = substr($p, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($p, -1);
        }
    }
    $domainMasked = implode('.', $maskedParts);

    return $localMasked . '@' . $domainMasked;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    if ($username === '' || !preg_match('/^[A-Za-z0-9_\.\-@]+$/', $username)) {
        $error = 'Introduce un nombre de usuario válido.';
    } else {
        $db = Database::getInstance()->getConnection();

            // If IP is currently blocked, behave as if the request succeeded (avoid info leak)
            try {
                $stmtBlockCheck = $db->prepare("SELECT COUNT(*) FROM ip_blocks WHERE ip_address = ? AND expires_at > NOW()");
                $stmtBlockCheck->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
                $isBlocked = intval($stmtBlockCheck->fetchColumn() ?: 0) > 0;
                if ($isBlocked) {
                    // Show generic success message (do not reveal block)
                    $anon = generate_fake_anonymized_email($username);
                    $success = 'Se ha enviado un correo a tu dirección ' . $anon . ' con instrucciones para restablecer la contraseña.';
                    // Log for forensic visibility
                    try {
                        $logger = new Logger();
                        $logger->log(null, 'password_reset_request_blocked_ip', null, null, 'Password reset requested but IP is blocked: ' . ($_SERVER['REMOTE_ADDR'] ?? ''), ['username' => $username]);
                        $forensic = new ForensicLogger();
                        $forensic->logSecurityEvent('password_reset_request_blocked', 'medium', 'Password reset request received from blocked IP', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'username' => $username], null);
                    } catch (Exception $e) {}

                    // Render result (skip processing)
                    require_once __DIR__ . '/../includes/layout.php';
                    ?>
                    <!doctype html>
                    <html lang="es">
                    <head>
                        <meta charset="utf-8">
                        <title>Restablecer contraseña</title>
                        <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
                    </head>
                    <body>
                    <div class="container" style="max-width:600px;margin:2rem auto;">
                        <h2>Restablecer contraseña</h2>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                        <p><a href="<?php echo BASE_URL; ?>/login.php">Volver al inicio de sesión</a></p>
                    </div>
                    </body>
                    </html>
                    <?php
                    exit;
                }
            } catch (Exception $e) {
                // If ip_blocks table missing or error, fail open and continue processing
            }

        // Ensure table exists (simple migration-on-demand)
        $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(128) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            UNIQUE KEY (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Lookup user by username
        $stmt = $db->prepare("SELECT id,username,email FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Do not reveal whether user exists; create a fake anonymized address for the confirmation message
            $anon = generate_fake_anonymized_email();

            // Log the attempt for audit (no sensitive data leaked)
            try {
                // security_events entry (existing style)
                $stmtLog = $db->prepare("INSERT INTO security_events (event_type, username, severity, ip_address, user_agent, description, details) VALUES ('password_reset_request', ?, 'low', ?, ?, ?, ?)");
                $detailsJson = json_encode(['username_submitted' => $username, 'time' => date('Y-m-d H:i:s')]);
                $stmtLog->execute([$username, $_SERVER['REMOTE_ADDR'] ?? 'unknown', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 'Password reset requested for submitted username (user not found)', $detailsJson]);

                // activity_log entry via Logger
                $logger = new Logger();
                $logger->log(null, 'password_reset_request_nonexistent', null, null, 'Password reset requested for non-existent username: ' . $username, ['anon_email' => $anon]);

                // Forensic log
                $forensic = new ForensicLogger();
                $forensic->logSecurityEvent('password_reset_request_nonexistent', 'medium', 'Password reset requested for non-existent username', ['username_submitted' => $username, 'anon_email' => $anon], null);
            } catch (Exception $e) {
                // ignore logging errors
            }

            $success = 'Se ha enviado un correo a tu dirección ' . $anon . ' con instrucciones para restablecer la contraseña.';

            // Detection: check if many requests for same username or from same IP in time window
            try {
                $cfgLocal = new Config();
                $threshold = max(3, intval($cfgLocal->get('password_reset_detection_threshold', 5)));
                $window = max(1, intval($cfgLocal->get('password_reset_detection_window_minutes', 10)));

                $stmtCount = $db->prepare("SELECT COUNT(*) FROM security_events WHERE event_type LIKE 'password_reset_request%' AND (username = ? OR ip_address = ?) AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
                $stmtCount->execute([$username, $_SERVER['REMOTE_ADDR'] ?? '', $window]);
                $cnt = intval($stmtCount->fetchColumn() ?: 0);
                if ($cnt >= $threshold) {
                    // escalate: forensic high and notify admins
                    try {
                        $forensic->logSecurityEvent('password_reset_enumeration_suspected', 'high', 'Suspected password reset enumeration attack', ['username_submitted' => $username, 'count' => $cnt, 'window_minutes' => $window, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null], null);
                        $logger->log(null, 'password_reset_attack_escalated', null, null, 'Escalated suspected password reset enumeration for ' . $username, ['count' => $cnt, 'window' => $window, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
                    } catch (Exception $e) {}

                    // Notify admins via email (collect admin emails from users table)
                    try {
                        $stmtA = $db->prepare("SELECT email FROM users WHERE role = 'admin' AND email IS NOT NULL AND email != ''");
                        $stmtA->execute();
                        $admins = $stmtA->fetchAll(PDO::FETCH_COLUMN, 0);
                        $emailer = new Email();
                        $siteName = (new Config())->get('site_name', 'Mimir');
                        $subject = "[{$siteName}] Alerta: posible ataque de enumeración de contraseñas";
                        $body = '<p>Se ha detectado un posible ataque de enumeración de contraseñas.</p>';
                        $body .= '<ul>';
                        $body .= '<li><strong>Usuario solicitado:</strong> ' . htmlspecialchars($username) . '</li>';
                        $body .= '<li><strong>IP origen:</strong> ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '</li>';
                        $body .= '<li><strong>Intentos en ' . intval($window) . ' minutos:</strong> ' . intval($cnt) . '</li>';
                        $body .= '</ul>';
                        foreach ($admins as $a) {
                            if ($a && filter_var($a, FILTER_VALIDATE_EMAIL)) {
                                try { $emailer->send($a, $subject, $body); } catch (Exception $e) {}
                            }
                        }
                    } catch (Exception $e) {
                        // ignore email errors
                    }
                }
            } catch (Exception $e) {
                // ignore detection errors
            }
        } else {
            $email = $user['email'];
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'La cuenta indicada no tiene una dirección de correo válida configurada.';
            } else {
                $token = bin2hex(random_bytes(24));
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
                $ins = $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
                $ins->execute([$user['id'], $token, $expires]);

                $emailer = new Email();
                $resetUrl = rtrim(BASE_URL, '/') . '/password_reset.php?token=' . urlencode($token);
                $subject = 'Restablece tu contraseña';
                $body = '<p>Hola ' . htmlspecialchars($user['username']) . ',</p>' .
                    '<p>Has pedido restablecer tu contraseña. Haz clic en el siguiente enlace para establecer una nueva contraseña (válido 1 hora):</p>' .
                    '<p><a href="' . htmlspecialchars($resetUrl) . '">' . htmlspecialchars($resetUrl) . '</a></p>' .
                    '<p>Si no has solicitado este cambio, puedes ignorar este correo.</p>';

                $sent = $emailer->send($email, $subject, $body);
                if ($sent) {
                    $anon = anonymize_email($email);
                    $success = 'Se ha enviado un correo a tu dirección ' . $anon . ' con instrucciones para restablecer la contraseña.';
                } else {
                    $error = 'No se pudo enviar el correo. Revisa la configuración de correo del servidor.';
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/layout.php';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Restablecer contraseña</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
<div class="container" style="max-width:600px;margin:2rem auto;">
    <h2>Restablecer contraseña</h2>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php else: ?>
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Usuario</label>
                <input id="username" name="username" type="text" class="form-control" required autofocus>
            </div>
            <button class="btn btn-primary">Enviar instrucciones</button>
        </form>
    <?php endif; ?>
    <p><a href="<?php echo BASE_URL; ?>/login.php">Volver al inicio de sesión</a></p>
</div>
</body>
</html>
