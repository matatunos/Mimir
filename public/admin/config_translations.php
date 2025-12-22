<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/Config.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();
$configClass = new Config();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!$auth->validateCsrfToken($csrf)) {
        $error = t('error_invalid_csrf');
    } else {
        $updates = $_POST['desc'] ?? [];
        $ok = true;
        foreach ($updates as $key => $text) {
            $res = $configClass->setTranslatedDescription($key, $text, 'es');
            if (!$res) $ok = false;
        }
        $message = $ok ? t('config_translations_saved') : t('error_update_user');
    }
}

// Load all config details
$configs = $configClass->getAllDetails();

renderPageStart(t('system_config_header'), 'configuration', true);
renderHeader(t('system_config_header'), $user);
?>
<div class="content">
    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
        <input type="hidden" name="save" value="1">

        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?php echo t('config_translations_title'); ?></h2>
            </div>
            <div class="card-body">
                <p class="text-muted"><?php echo t('config_translations_desc'); ?></p>

                <?php foreach ($configs as $cfg):
                    $key = $cfg['config_key'];
                    $current = $configClass->getTranslatedDescription($key, 'es') ?? '';
                ?>
                <div class="form-group" style="margin-bottom:1rem;">
                    <label style="font-weight:600; display:block; margin-bottom:0.35rem;"><?php echo htmlspecialchars($key); ?></label>
                    <textarea name="desc[<?php echo htmlspecialchars($key); ?>]" class="form-control" rows="2"><?php echo htmlspecialchars($current); ?></textarea>
                    <small class="form-text text-muted"><?php echo htmlspecialchars($cfg['config_type']); ?> — <?php echo htmlspecialchars($cfg['config_value']); ?></small>
                </div>
                <?php endforeach; ?>

                <div style="margin-top:1rem;">
                    <button class="btn btn-primary" type="submit"><?php echo t('save_changes'); ?></button>
                </div>
            </div>
        </div>
    </form>
</div>

<?php renderPageEnd();
