<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();
$fileClass = new File();
$userClass = new User();
$logger = new Logger();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST received - FILES count: " . (isset($_FILES['files']) ? count($_FILES['files']['name']) : 0));
    
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
    } elseif (!isset($_FILES['files']) || empty($_FILES['files']['tmp_name'][0])) {
        $error = 'Selecciona al menos un archivo';
    } else {
        $uploadedCount = 0;
        $errors = [];
        $description = $_POST['description'] ?? '';
        $fileCount = count($_FILES['files']['tmp_name']);
        
        for ($i = 0; $i < $fileCount; $i++) {
            if (empty($_FILES['files']['tmp_name'][$i]) || $_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
                if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = $_FILES['files']['name'][$i] . ': Error al subir';
                }
                continue;
            }
            
            $fileData = [
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'name' => $_FILES['files']['name'][$i],
                'size' => $_FILES['files']['size'][$i],
                'type' => $_FILES['files']['type'][$i],
                'error' => $_FILES['files']['error'][$i]
            ];
            
            try {
                $result = $fileClass->upload($fileData, $user['id'], $description);
                if ($result) {
                    $uploadedCount++;
                    $logger->log($user['id'], 'file_upload', 'file', $result, "Archivo subido: {$fileData['name']}");
                } else {
                    $errors[] = $fileData['name'] . ': No se pudo procesar';
                }
            } catch (Exception $e) {
                $errors[] = $fileData['name'] . ': ' . $e->getMessage();
            }
        }
        
        if ($uploadedCount > 0) {
            $success = $uploadedCount === 1 ? 'Archivo subido correctamente' : "$uploadedCount archivos subidos correctamente";
            if (!empty($errors)) {
                $success .= ' (algunos fallaron)';
            }
            header('Location: ' . BASE_URL . '/user/files.php?success=' . urlencode($success));
            exit;
        } else {
            $error = 'No se pudo subir ningún archivo. ' . implode(', ', $errors);
        }
    }
}

$stats = $userClass->getStatistics($user['id']);
$storageUsedGB = ($stats['total_size'] ?? 0) / 1024 / 1024 / 1024;
$storageQuotaGB = $user['storage_quota'] / 1024 / 1024 / 1024;
$storagePercent = $storageQuotaGB > 0 ? min(100, ($storageUsedGB / $storageQuotaGB) * 100) : 0;
$maxSize = MAX_FILE_SIZE / 1024 / 1024;

renderPageStart('Subir Archivos', 'upload', $user['role'] === 'admin');
renderHeader('Subir Archivos', $user);
?>

<div class="content">
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header" style="background: linear-gradient(135deg, #e9b149, #444e52); color: white; padding: 1.5rem;">
            <h2 class="card-title" style="color: white; margin: 0;">Subir Archivo</h2>
        </div>
        <div class="card-body">
            <div class="mb-3" style="background: var(--bg-secondary); padding: 1rem; border-radius: 0.5rem; border-left: 4px solid var(--primary);">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span>Almacenamiento usado:</span>
                    <strong><?php echo number_format($storageUsedGB, 2); ?> GB / <?php echo number_format($storageQuotaGB, 2); ?> GB</strong>
                </div>
                <div style="background: var(--bg-main); height: 1.5rem; border-radius: 0.75rem; overflow: hidden;">
                    <div style="width: <?php echo $storagePercent; ?>%; height: 100%; background: var(--primary); transition: width 0.3s;"></div>
                </div>
                <div style="font-size: 0.8125rem; color: var(--text-muted); margin-top: 0.5rem;">
                    Tamaño máximo por archivo: <?php echo $maxSize; ?> MB
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                
                <div class="form-group">
                    <label>Archivos *</label>
                    <input type="file" name="files[]" class="form-control" multiple required>
                    <small class="form-text">Mantén presionado Ctrl (o Cmd en Mac) para seleccionar múltiples archivos</small>
                </div>

                <div class="form-group">
                    <label>Descripción (opcional)</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Añade una descripción para estos archivos..."></textarea>
                </div>

                <div style="display: flex; gap: 0.75rem;">
                    <button type="submit" class="btn btn-primary">⬆️ Subir Archivos</button>
                    <a href="<?php echo BASE_URL; ?>/user/files.php" class="btn btn-outline">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php renderPageEnd(); ?>
