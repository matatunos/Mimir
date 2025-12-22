<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../classes/Email.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    if ($username === '' || !preg_match('/^[A-Za-z0-9_\.\-@]+$/', $username)) {
        $error = 'Introduce un nombre de usuario válido.';
    } else {
        $db = Database::getInstance()->getConnection();

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
            // Do not reveal whether user exists; generic message
            $success = 'Si existe una cuenta asociada a ese usuario, recibirás un correo con instrucciones.';
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
                    $success = 'Se ha enviado un correo a tu dirección ' . anonymize_email($email) . ' con instrucciones para restablecer la contraseña.';
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
