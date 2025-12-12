<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();
$fileClass = new File();
$logger = new Logger();
$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/user/files.php?error=' . urlencode('Método inválido'));
    exit;
}

$action = $_POST['action'] ?? '';
// CSRF validation
if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
    header('Location: ' . BASE_URL . '/user/files.php?error=' . urlencode('Token CSRF inválido'));
    exit;
}

$selectAll = isset($_POST['select_all']) && $_POST['select_all'] === '1';
$search = $_POST['search'] ?? '';
$folder = $_POST['folder'] ?? '';
// target_folder removed from user UI; backend does not process move actions for users

$fileIds = [];

try {
    if ($selectAll) {
        // Build query to fetch all matching IDs for this user
        if (!empty($search)) {
            $stmt = $db->prepare("SELECT id FROM files WHERE user_id = ? AND (original_name LIKE ? OR description LIKE ?)");
            $term = '%' . $search . '%';
            $stmt->execute([$user['id'], $term, $term]);
            $rows = $stmt->fetchAll();
            foreach ($rows as $r) $fileIds[] = $r['id'];
        } elseif (!empty($folder)) {
            // Get folder contents (includes subfolders/files only one level)
            $items = $fileClass->getFolderContents($user['id'], (int)$folder);
            foreach ($items as $it) $fileIds[] = $it['id'];
        } else {
            // All user files
            $stmt = $db->prepare("SELECT id FROM files WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            $rows = $stmt->fetchAll();
            foreach ($rows as $r) $fileIds[] = $r['id'];
        }
    } else {
        if (!empty($_POST['file_ids']) && is_array($_POST['file_ids'])) {
            $fileIds = array_map('intval', $_POST['file_ids']);
        }
    }

    if (empty($fileIds)) {
        header('Location: ' . BASE_URL . '/user/files.php?error=' . urlencode('No se encontraron elementos para procesar'));
        exit;
    }

    $processed = 0;
    $errors = 0;
    $db->beginTransaction();

    foreach ($fileIds as $fid) {
        // Ensure file belongs to user
        $f = $fileClass->getById($fid);
        if (!$f || $f['user_id'] != $user['id']) {
            $errors++;
            continue;
        }

        try {
            switch ($action) {
                case 'delete':
                    if ($f['is_folder']) {
                        if ($fileClass->deleteFolder($fid, $user['id'])) {
                            $processed++;
                        } else {
                            $errors++;
                        }
                    } else {
                        if ($fileClass->delete($fid, $user['id'])) {
                            $processed++;
                        } else {
                            $errors++;
                        }
                    }
                    break;
                case 'share':
                    // Mark file as shared
                    if (!$f['is_folder']) {
                        $stmt = $db->prepare("UPDATE files SET is_shared = 1 WHERE id = ? AND user_id = ?");
                        if ($stmt->execute([$fid, $user['id']])) {
                            $logger->log($user['id'], 'file_share', 'file', $fid, 'User bulk shared file: ' . $f['original_name']);
                            $processed++;
                        } else {
                            $errors++;
                        }
                    } else {
                        // For folders, skip or handle recursively if desired
                        $errors++;
                    }
                    break;
                case 'unshare':
                    // Deactivate any active shares for this file and update file status
                    if (!$f['is_folder']) {
                        // find active shares
                        $sStmt = $db->prepare("SELECT id FROM shares WHERE file_id = ? AND is_active = 1");
                        $sStmt->execute([$fid]);
                        $activeShares = $sStmt->fetchAll(PDO::FETCH_COLUMN);

                        if (!empty($activeShares)) {
                            $uStmt = $db->prepare("UPDATE shares SET is_active = 0 WHERE file_id = ?");
                            if ($uStmt->execute([$fid])) {
                                // Update files.is_shared based on remaining active shares
                                $fileClass->updateSharedStatus($fid);

                                // Log each deactivated share
                                foreach ($activeShares as $sid) {
                                    $logger->log($user['id'], 'share_deactivate', 'share', $sid, 'User bulk deactivated share for file: ' . $f['original_name']);
                                }

                                $processed++;
                            } else {
                                $errors++;
                            }
                        } else {
                            // nothing to do
                            $errors++;
                        }
                    } else {
                        $errors++;
                    }
                    break;
                // 'move' action removed from user UI and is not supported here
                default:
                    $errors++;
                    break;
            }
        } catch (Exception $e) {
            error_log('Bulk action error: ' . $e->getMessage());
            $errors++;
        }
    }

    $db->commit();
    $msg = "Operación completada: $processed procesados";
    if ($errors > 0) $msg .= ", $errors errores";
    header('Location: ' . BASE_URL . '/user/files.php?success=' . urlencode($msg));
    exit;

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    header('Location: ' . BASE_URL . '/user/files.php?error=' . urlencode($e->getMessage()));
    exit;
}
