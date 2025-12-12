<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/SecurityHeaders.php';

SecurityHeaders::applyAll();

$auth = new Auth();
$userClass = new User();
$error = '';

// Determine user id: either logged in or forced-change flow
$currentUserId = null;
if ($auth->isLoggedIn()) {
    $currentUserId = $auth->getUserId();
} elseif (!empty($_SESSION['force_password_change_user_id'])) {
    $currentUserId = $_SESSION['force_password_change_user_id'];
}

if (!$currentUserId) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($new) || empty($confirm)) {
        $error = 'Introduce la nueva contraseña y su confirmación.';
    } elseif ($new !== $confirm) {
        $error = 'Las contraseñas no coinciden.';
    } elseif (strlen($new) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } else {
        $changed = $userClass->changePassword($currentUserId, $new);
        if ($changed) {
            if (!empty($_SESSION['force_password_change_username'])) {
                $username = $_SESSION['force_password_change_username'];
                unset($_SESSION['force_password_change_user_id']);
                unset($_SESSION['force_password_change_username']);
                if ($auth->login($username, $new)) {
                    header('Location: ' . BASE_URL . '/index.php');
                    exit;
                }
            }
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        } else {
            $error = 'No se pudo cambiar la contraseña. Contacta con el administrador.';
        }
    }
}

require_once __DIR__ . '/../includes/layout.php';
$siteName = (new Config())->get('site_name', 'Mimir');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar contraseña - <?php echo htmlspecialchars($siteName); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        .change-card { max-width: 420px; margin: 3rem auto; }
    </style>
</head>
<body>
    <div class="change-card">
        <h2>Cambiar contraseña</h2>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="form-group">
                <label>Nueva contraseña</label>
                <input type="password" name="new_password" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Confirmar contraseña</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
            <button class="btn btn-primary" type="submit">Cambiar contraseña</button>
        </form>
    </div>
</body>
</html>
