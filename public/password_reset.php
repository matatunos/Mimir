<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';

use PDO;

$db = Database::getInstance()->getConnection();
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$success = '';

if (empty($token)) {
    $error = t('error_token_missing');
} else {
    // Validate token
    $stmt = $db->prepare("SELECT pr.id AS pr_id, pr.user_id, pr.expires_at, u.username FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $error = t('error_token_invalid');
    } else {
        if (strtotime($row['expires_at']) < time()) {
            $error = t('error_token_expired');
        } else {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $password = $_POST['password'] ?? '';
                $password2 = $_POST['password2'] ?? '';
                if (empty($password) || $password !== $password2) {
                    $error = t('error_password_mismatch_or_empty');
                } else {
                    // Update password
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $upd = $db->prepare("UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?");
                    $upd->execute([$hash, $row['user_id']]);

                    // Remove all tokens for this user
                    $del = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
                    $del->execute([$row['user_id']]);

                    $success = t('password_updated_success');
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/layout.php';
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($_SESSION['lang'] ?? 'es'); ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars(t('reset_password_title')); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
<div class="container" style="max-width:600px;margin:2rem auto;">
    <h2><?php echo htmlspecialchars(t('reset_password_heading')); ?></h2>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <p><a href="<?php echo BASE_URL; ?>/login.php"><?php echo htmlspecialchars(t('login_button')); ?></a></p>
    <?php else: ?>
        <?php if (isset($row) && $row): ?>
            <form method="POST" action="">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <div class="form-group">
                    <label for="password"><?php echo htmlspecialchars(t('label_new_password')); ?></label>
                    <input id="password" name="password" type="password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="password2"><?php echo htmlspecialchars(t('label_confirm_password')); ?></label>
                    <input id="password2" name="password2" type="password" class="form-control" required>
                </div>
                <button class="btn btn-primary"><?php echo htmlspecialchars(t('password_set_button')); ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
