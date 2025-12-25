<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/Config.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/Share.php';

$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();
$config = new Config();
$fileClass = new File();
$shareClass = new Share();
$logger = new Logger();

$fileId = intval($_GET['file_id'] ?? 0);
$error = '';
$success = '';

$file = $fileClass->getById($fileId);
    if (!$file || $file['user_id'] != $user['id']) {
    header('Location: ' . BASE_URL . '/user/files.php?error=' . urlencode(t('error_file_not_found')));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = t('error_invalid_csrf');
    } else {
        try {
            $defaultMaxDays = $config->get('default_max_share_days', DEFAULT_MAX_SHARE_DAYS);
            $isGallery = isset($_POST['publish_gallery']) && $_POST['publish_gallery'] == '1';

            if ($isGallery) {
                // gallery: no expiration, unlimited downloads
                $maxDays = 0;
                $maxDownloads = null;
            } else {
                $maxDays = min(intval($_POST['max_days'] ?? $defaultMaxDays), $defaultMaxDays);
                $maxDownloads = intval($_POST['max_downloads'] ?? 0) ?: null;
            }

            $password = !empty($_POST['password']) ? $_POST['password'] : null;

            // Validate recipient email if provided
            $recipientEmail = null;
            $recipientMessage = null;
            if (!empty($_POST['recipient_email'])) {
                require_once __DIR__ . '/../../classes/SecurityValidator.php';
                $validator = SecurityValidator::getInstance();
                $candidate = trim($_POST['recipient_email']);
                if ($validator->validateEmail($candidate)) {
                    $recipientEmail = $candidate;
                } else {
                    throw new Exception(t('error_invalid_recipient_email'));
                }
            }
            if (!empty($_POST['recipient_message'])) {
                $recipientMessage = trim($_POST['recipient_message']);
            }

            $result = $shareClass->create($fileId, $user['id'], [
                'max_days' => $maxDays,
                'max_downloads' => $maxDownloads,
                'password' => $password,
                'recipient_email' => $recipientEmail,
                'recipient_message' => $recipientMessage
            ]);

            $logger->log($user['id'], 'share_create', 'share', $result['id'], 'Usuario compartió archivo', [
                'file_id' => $fileId,
                'max_days' => $maxDays
            ]);

            header('Location: ' . BASE_URL . '/user/shares.php?success=' . urlencode(t('share_link_created')));
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$isAdmin = ($user['role'] === 'admin');
renderPageStart(t('share'), 'files', $isAdmin);
renderHeader(t('share') . ': ' . htmlspecialchars($file['original_name']), $user);
?>

<div class="content">
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card">

    <div class="card-header" style="padding: 1.5rem;">
            <h2 class="card-title" style="margin: 0;"><?php echo t('create'); ?> <?php echo t('share'); ?></h2>
        </div>
        <div class="card-body">
                <div class="mb-3" style="background: var(--bg-secondary); padding: 1rem; border-radius: 0.5rem;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div style="font-size: 2rem;"><i class="fas fa-file"></i></div>
                    <div style="flex: 1;">
                        <div style="font-weight: 500;"><?php echo htmlspecialchars($file['original_name']); ?></div>
                        <div style="font-size: 0.8125rem; color: var(--text-muted);">
                            <?php echo number_format($file['file_size'] / 1024 / 1024, 2); ?> MB
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">

                

                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('share_days_valid_label')); ?> *</label>
                    <?php $defaultMaxDays = $config->get('default_max_share_days', DEFAULT_MAX_SHARE_DAYS); ?>
                    <input type="number" name="max_days" class="form-control" value="<?php echo $defaultMaxDays; ?>" min="1" max="<?php echo $defaultMaxDays; ?>" required>
                    <small style="color: var(--text-muted);"><?php echo htmlspecialchars(sprintf(t('max_days_hint'), $defaultMaxDays)); ?></small>
                </div>

                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('share_max_downloads_label')); ?></label>
                    <input type="number" name="max_downloads" class="form-control" placeholder="<?php echo htmlspecialchars(t('unlimited')); ?>" min="1">
                    <small style="color: var(--text-muted);"><?php echo htmlspecialchars(t('leave_blank_unlimited')); ?></small>
                </div>

                

                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('share_password_optional')); ?></label>
                    <input type="password" name="password" class="form-control" placeholder="<?php echo htmlspecialchars(t('protect_with_password')); ?>">
                    <small style="color: var(--text-muted);"><?php echo htmlspecialchars(t('share_password_hint')); ?></small>
                </div>

                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('share_send_to_email_label')); ?></label>
                    <input type="email" name="recipient_email" class="form-control" placeholder="correo@ejemplo.com">
                    <small style="color: var(--text-muted);"><?php echo htmlspecialchars(t('share_send_to_email_help')); ?></small>
                </div>

                <div class="form-group">
                    <label>Mensaje breve al destinatario (opcional)</label>
                    <textarea name="recipient_message" class="form-control" rows="3" placeholder="Texto breve que recibirá el destinatario"></textarea>
                </div>

                <div style="display: flex; gap: 0.75rem;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-link"></i> <?php echo htmlspecialchars(t('create') . ' ' . t('share')); ?></button>
                    <a href="<?php echo BASE_URL; ?>/user/files.php" class="btn btn-outline btn-outline--on-dark"><?php echo htmlspecialchars(t('cancel')); ?></a>
                </div>
            </form>
        </div>
    </div>
</div>



<?php renderPageEnd(); ?>
