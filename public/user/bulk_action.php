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
            // Collect all files inside the folder recursively so downloads include nested files
            $filesInFolder = $fileClass->getFilesInFolderRecursive($user['id'], (int)$folder);
            foreach ($filesInFolder as $f) $fileIds[] = $f['id'];
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

    // Handle bulk download separately: stream a zip of selected files
    if ($action === 'download') {
        $tmp = sys_get_temp_dir() . '/mimir_bulk_' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE) !== TRUE) {
            header('Location: ' . BASE_URL . '/user/files.php?error=' . urlencode('No se pudo crear el archivo ZIP temporal'));
            exit;
        }

        $added = 0;
        $namesSeen = [];
        // If folder download requested, determine root folder id and name for relative paths
        $rootFolderId = isset($_POST['folder']) && $_POST['folder'] !== '' ? (int)$_POST['folder'] : null;
        $rootFolderName = null;
        if ($rootFolderId) {
            $rf = $fileClass->getById($rootFolderId);
            $rootFolderName = $rf ? $rf['original_name'] : null;
        }
        foreach ($fileIds as $fid) {
            $f = $fileClass->getById($fid);
            if (!$f || $f['user_id'] != $user['id']) continue;
            if ($f['is_folder']) continue;

            // Normalize file path: support installations that store relative paths
            $filePath = $f['file_path'] ?? '';
            if (!empty($filePath) && !preg_match('#^(\/|[A-Za-z]:\\\\)#', $filePath)) {
                if (defined('UPLOADS_PATH') && UPLOADS_PATH) {
                    $filePath = rtrim(UPLOADS_PATH, '/') . '/' . ltrim($filePath, '/');
                } else {
                    $filePath = rtrim(constant('BASE_PATH'), '/') . '/' . ltrim($filePath, '/');
                }
            }
            if (empty($filePath) || !is_file($filePath)) continue;

            $name = $f['original_name'];
            // If downloading a folder, compute a relative path inside the zip to preserve hierarchy
            if ($rootFolderId) {
                // Build path segments from file's parents up to (but not including) the root folder
                $segments = [];
                $currentParent = $f['parent_folder_id'] ?? null;
                while ($currentParent && $currentParent != $rootFolderId) {
                    $parent = $fileClass->getById($currentParent);
                    if (!$parent) break;
                    array_unshift($segments, $parent['original_name']);
                    $currentParent = $parent['parent_folder_id'] ?? null;
                }
                // Sanitize segments and filename
                $sanitize = function($s){ return preg_replace('/[^A-Za-z0-9_\-\. ]+/', '_', $s); };
                $safeName = $sanitize($name);
                $safeRoot = $rootFolderName ? $sanitize($rootFolderName) : 'folder';
                $safeSegments = array_map($sanitize, $segments);
                $pathPrefix = $safeRoot . (empty($safeSegments) ? '' : '/' . implode('/', $safeSegments));
                $name = ltrim($pathPrefix . '/' . $safeName, '/');
            }
            // Avoid duplicate names inside zip
            if (isset($namesSeen[$name])) {
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $base = pathinfo($name, PATHINFO_FILENAME);
                $name = $base . '_' . $namesSeen[$f['original_name']] . ($ext ? '.' . $ext : '');
                $namesSeen[$f['original_name']]++;
            } else {
                $namesSeen[$f['original_name']] = 1;
            }

            $zip->addFile($filePath, $name);
            $added++;
        }

        $zip->close();

        if ($added === 0) {
            @unlink($tmp);
            header('Location: ' . BASE_URL . '/user/files.php?error=' . urlencode('No se encontraron archivos válidos para descargar'));
            exit;
        }

        // Stream zip and remove temp file
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="download_' . date('Ymd_His') . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
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
