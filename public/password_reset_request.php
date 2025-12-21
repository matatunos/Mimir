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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Introduce una dirección de correo válida.';
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

        $stmt = $db->prepare("SELECT id,username FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Do not reveal whether email exists
            $success = 'Si existe una cuenta asociada a esa dirección, recibirás un correo con instrucciones.';
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
                $success = 'Se ha enviado un correo con instrucciones si la cuenta existe.';
            } else {
                $error = 'No se pudo enviar el correo. Revisa la configuración de correo del servidor.';
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
                <label for="email">Dirección de correo</label>
                <input id="email" name="email" type="email" class="form-control" required>
            </div>
            <button class="btn btn-primary">Enviar instrucciones</button>
        </form>
    <?php endif; ?>
    <p><a href="<?php echo BASE_URL; ?>/login.php">Volver al inicio de sesión</a></p>
</div>
</body>
</html>
