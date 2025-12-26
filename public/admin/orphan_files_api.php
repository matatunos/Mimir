<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Logger.php';

header('Content-Type: application/json');

$auth = new Auth();
$adminUser = $auth->getUser();

// If this is an API call and the user is not logged in or not admin, return JSON error
if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('error_auth_required'), 'code' => 'AUTH_REQUIRED']);
    exit;
}

// Ensure any PHP errors/exceptions are returned as JSON (helpful for debugging client-side parse errors)
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function($e) {
    http_response_code(500);
    header('Content-Type: application/json');
    $msg = $e->getMessage();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $msg]);
    exit;
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && ($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Fatal error: ' . ($err['message'] ?? 'unknown')]);
        exit;
    }
});

$fileClass = new File();
$userClass = new User();
$logger = new Logger();
// Orphan delete debug log
$orphanDebugLog = LOGS_PATH . '/orphan_delete_debug.log';
function orphan_debug($msg) {
    global $orphanDebugLog;
    @file_put_contents($orphanDebugLog, date('c') . ' | ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

// GET requests - search users
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'search_users') {
        $query = trim($_GET['q'] ?? '');
        
        if (strlen($query) < 2) {
            echo json_encode(['success' => false, 'message' => t('error_query_too_short')]);
            exit;
        }
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, username, email, full_name, role
            FROM users
            WHERE is_active = 1
            AND (username LIKE ? OR email LIKE ? OR full_name LIKE ?)
            ORDER BY username ASC
            LIMIT 10");

        $searchTerm = '%' . $query . '%';
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'users' => $users]);
        exit;
    }
    
    // Return list of file IDs matching filters (used for batching)
    if ($action === 'list_ids') {
        $search = trim($_GET['search'] ?? '');
        $filterUser = $_GET['user'] ?? '';
        $filterShared = $_GET['shared'] ?? '';
        $filterType = $_GET['type'] ?? '';

        // Log that an admin requested ids (helps diagnosing auth/session problems)
        try {
            $logger->log($adminUser['id'] ?? null, 'list_ids_requested', 'files', 0, 'Requested list_ids', [
                'search' => $search,
                'user' => $filterUser,
                'shared' => $filterShared,
                'type' => $filterType
            ]);
        } catch (Exception $e) {
            // don't break functionality on logging failure
        }

        $db = Database::getInstance()->getConnection();
        $where = ["1=1"];
        $params = [];
        if ($search) {
            $where[] = "(f.original_name LIKE ? OR f.description LIKE ? )";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if ($filterUser) {
            $where[] = "u.username LIKE ?";
            $params[] = "%$filterUser%";
        }
        if ($filterShared === 'yes') {
            $where[] = "f.is_shared = 1";
        } elseif ($filterShared === 'no') {
            $where[] = "f.is_shared = 0";
        }
        if ($filterType) {
            $where[] = "f.mime_type LIKE ?";
            $params[] = "$filterType%";
        }

        $whereClause = implode(' AND ', $where);

        // Pagination support: if limit/offset provided, return a page and total count
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 0;
        $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

        if ($limit > 0) {
            // get total count
            $countStmt = $db->prepare("SELECT COUNT(*) as c FROM files f LEFT JOIN users u ON f.user_id = u.id WHERE $whereClause");
            $countStmt->execute($params);
            $total = intval($countStmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

            $stmt = $db->prepare("SELECT f.id FROM files f LEFT JOIN users u ON f.user_id = u.id WHERE $whereClause ORDER BY f.id ASC LIMIT ? OFFSET ?");
            $execParams = array_merge($params, [$limit, $offset]);
            $stmt->execute($execParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ids = array_map(function($r){ return intval($r['id']); }, $rows);
            echo json_encode(['success' => true, 'ids' => $ids, 'total' => $total]);
            exit;
        } else {
            $stmt = $db->prepare("SELECT f.id FROM files f LEFT JOIN users u ON f.user_id = u.id WHERE $whereClause ORDER BY f.id ASC");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ids = array_map(function($r){ return intval($r['id']); }, $rows);
            echo json_encode(['success' => true, 'ids' => $ids, 'total' => count($ids)]);
            exit;
        }
    }
}

// POST requests - file operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'assign') {
        $userId = intval($_POST['user_id'] ?? 0);
        
        // Support both single file and multiple files
        $fileIds = [];
        if (isset($_POST['file_ids'])) {
            $fileIds = json_decode($_POST['file_ids'], true);
            if (!is_array($fileIds)) {
                echo json_encode(['success' => false, 'message' => t('error_invalid_id_format')]);
                exit;
            }
            $fileIds = array_map('intval', $fileIds);
        } elseif (isset($_POST['file_id'])) {
            $fileIds = [intval($_POST['file_id'])];
        }

        // If `all` flag is provided, select all orphan files possibly filtered by search
        if ((empty($fileIds) || !is_array($fileIds)) && isset($_POST['all']) && $_POST['all'] == '1') {
            $search = trim($_POST['search'] ?? '');
            $db = Database::getInstance()->getConnection();
            if ($search !== '') {
                $stmt = $db->prepare("SELECT id FROM files WHERE user_id IS NULL AND original_name LIKE ?");
                $like = '%' . $search . '%';
                $stmt->execute([$like]);
            } else {
                $stmt = $db->prepare("SELECT id FROM files WHERE user_id IS NULL");
                $stmt->execute();
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $fileIds = array_map(function($r){ return intval($r['id']); }, $rows);
        }
        
        if (empty($fileIds) || !$userId) {
            echo json_encode(['success' => false, 'message' => t('error_invalid_parameters')]);
            exit;
        }
        
        // Verify user exists
        $user = $userClass->getById($userId);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => t('error_user_not_found')]);
            exit;
        }
        
        $db = Database::getInstance()->getConnection();
        // Provide actor info to DB trigger (optional but recommended)
        try {
            $set = $db->prepare("SET @current_actor_id = ?");
            $set->execute([ $adminUser['id'] ?? null ]);
        } catch (Exception $e) {
            // ignore; trigger will still record old/new ids without actor
        }
        $successCount = 0;
        $failedFiles = [];
        
        foreach ($fileIds as $fileId) {
            // Verify file exists and is orphaned
            $stmt = $db->prepare("SELECT id, original_name FROM files WHERE id = ? AND user_id IS NULL");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$file) {
                $failedFiles[] = "ID $fileId (no encontrado o no es huérfano)";
                continue;
            }
            
            try {
                if ($fileClass->reassignOwner($fileId, $userId)) {
                    $logger->log(
                        $adminUser['id'],
                        'orphan_assigned',
                        'file',
                        $fileId,
                        "Archivo huérfano '{$file['original_name']}' asignado a {$user['username']}"
                    );
                    $successCount++;
                } else {
                    $failedFiles[] = $file['original_name'];
                }
            } catch (Exception $e) {
                $failedFiles[] = $file['original_name'] . " (error: {$e->getMessage()})";
            }
        }

        // clear actor variable
        try { $db->query("SET @current_actor_id = NULL"); } catch (Exception $e) {}
        
        if ($successCount > 0 && empty($failedFiles)) {
            echo json_encode(['success' => true, 'count' => $successCount]);
        } elseif ($successCount > 0) {
            echo json_encode([
                'success' => true, 
                'count' => $successCount,
                'message' => "Se asignaron $successCount archivo(s), pero algunos fallaron: " . implode(', ', $failedFiles)
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => sprintf(t('error_no_assign_files'), implode(', ', $failedFiles))
            ]);
        }
        exit;
    }

    if ($action === 'reassign_any') {
        $userId = intval($_POST['user_id'] ?? 0);

        $db = Database::getInstance()->getConnection();
        // provide actor for trigger
        try {
            $db->prepare("SET @current_actor_id = ?")->execute([ $adminUser['id'] ?? null ]);
        } catch (Exception $e) {}

        // Collect file IDs
        $fileIds = [];
        if (isset($_POST['file_ids'])) {
            $fileIds = json_decode($_POST['file_ids'], true);
            if (!is_array($fileIds)) {
                echo json_encode(['success' => false, 'message' => t('error_invalid_id_format')]);
                exit;
            }
            $fileIds = array_map('intval', $fileIds);
        } elseif (isset($_POST['file_id'])) {
            $fileIds = [intval($_POST['file_id'])];
        }

        // If `select_all` flag is provided, select all files matching filters
        if ((empty($fileIds) || !is_array($fileIds)) && isset($_POST['select_all']) && $_POST['select_all'] == '1') {
            $filters = $_POST['filters'] ?? '';
            $farr = [];
            if ($filters !== '') parse_str($filters, $farr);

            $search = $farr['search'] ?? '';
            $filterUser = $farr['user'] ?? '';
            $filterShared = $farr['shared'] ?? '';
            $filterType = $farr['type'] ?? '';

            $where = ["1=1"];
            $params = [];
            if ($search) {
                $where[] = "(f.original_name LIKE ? OR f.description LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            if ($filterUser) {
                $where[] = "u.username LIKE ?";
                $params[] = "%$filterUser%";
            }
            if ($filterShared === 'yes') {
                $where[] = "f.is_shared = 1";
            } elseif ($filterShared === 'no') {
                $where[] = "f.is_shared = 0";
            }
            if ($filterType) {
                $where[] = "f.mime_type LIKE ?";
                $params[] = "$filterType%";
            }

            $whereClause = implode(' AND ', $where);
            $stmt = $db->prepare("SELECT f.id FROM files f LEFT JOIN users u ON f.user_id = u.id WHERE $whereClause");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $fileIds = array_map(function($r){ return intval($r['id']); }, $rows);
        }

        if (empty($fileIds) || !$userId) {
            echo json_encode(['success' => false, 'message' => t('error_invalid_parameters')]);
            exit;
        }

        $user = $userClass->getById($userId);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => t('error_user_not_found')]);
            exit;
        }

        $successCount = 0;
        $failedFiles = [];

        foreach ($fileIds as $fileId) {
            $stmt = $db->prepare("SELECT id, original_name FROM files WHERE id = ?");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$file) {
                $failedFiles[] = "ID $fileId (no encontrado)";
                continue;
            }

            try {
                if ($fileClass->reassignOwner($fileId, $userId)) {
                    $logger->log(
                        $adminUser['id'],
                        'file_reassigned',
                        'file',
                        $fileId,
                        "Archivo '{$file['original_name']}' reasignado a {$user['username']}"
                    );
                    $successCount++;
                } else {
                    $failedFiles[] = $file['original_name'];
                }
            } catch (Exception $e) {
                $failedFiles[] = $file['original_name'] . " (error: {$e->getMessage()})";
            }
        }

        // clear actor variable
        try { $db->query("SET @current_actor_id = NULL"); } catch (Exception $e) {}

        if ($successCount > 0 && empty($failedFiles)) {
            echo json_encode(['success' => true, 'count' => $successCount]);
        } elseif ($successCount > 0) {
            echo json_encode([
                'success' => true,
                'count' => $successCount,
                'message' => "Se reasignaron $successCount archivo(s), pero algunos fallaron: " . implode(', ', $failedFiles)
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => sprintf(t('error_no_reassign_files'), implode(', ', $failedFiles))]);
        }
        exit;
    }
    
    if ($action === 'delete') {
        $fileId = intval($_POST['file_id'] ?? 0);
        
        if (!$fileId) {
            echo json_encode(['success' => false, 'message' => t('error_invalid_file_id')]);
            exit;
        }
        
        // Verify file exists and is orphaned
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, original_name, file_path, is_folder FROM files WHERE id = ? AND user_id IS NULL");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$file) {
            echo json_encode(['success' => false, 'message' => t('error_file_not_found_or_not_orphan')]);
            exit;
        }
        
        try {
            // If folder, use recursive deleteFolder
            if (!empty($file['is_folder'])) {
                orphan_debug("DELETE folder id={$fileId} name='{$file['original_name']}'");
                $ok = $fileClass->deleteFolder($fileId, null);
                if ($ok) {
                    echo json_encode(['success' => true]);
                } else {
                    orphan_debug("  deleteFolder returned false for id={$fileId}");
                    echo json_encode(['success' => false, 'message' => t('error_delete_folder')]);
                }
                exit;
            }

            // Resolve path (support absolute or relative). Skip if empty.
            $fp = $file['file_path'] ?? '';
            if ($fp === '' || $fp === null) {
                orphan_debug("DELETE file id={$fileId} has empty file_path");
            } else {
                if (strpos($fp, '/') === 0) {
                    $fullPath = $fp;
                } else {
                    $fullPath = rtrim(UPLOADS_PATH, '/') . '/' . ltrim($fp, '/');
                }
                orphan_debug("DELETE attempt id={$fileId} name='{$file['original_name']}' db_path='{$fp}' resolved='{$fullPath}'");
                if ($fullPath && file_exists($fullPath)) {
                    $beforePerms = sprintf('%o', fileperms($fullPath) & 0777);
                    orphan_debug("  exists=yes perms={$beforePerms} owner=" . @fileowner($fullPath));
                    $unlinkOk = @unlink($fullPath);
                    orphan_debug("  unlink_result=" . ($unlinkOk ? 'ok' : 'failed') . " error=" . json_encode(error_get_last()));
                } else {
                    orphan_debug("  exists=no for resolved={$fullPath}");
                }
            }

            // Delete DB record
            $stmt = $db->prepare("DELETE FROM files WHERE id = ?");
            $execOk = $stmt->execute([$fileId]);
            orphan_debug("  db_delete_result=" . ($execOk ? 'ok' : 'failed'));
                if ($execOk) {
                    $logger->log(
                        $adminUser['id'],
                        'orphan_deleted',
                        'file',
                        $fileId,
                        "Archivo huérfano '{$file['original_name']}' eliminado"
                    );
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => t('error_delete_file')]);
                }
        } catch (Exception $e) {
            orphan_debug('  exception: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'bulk_delete') {
        $db = Database::getInstance()->getConnection();

        // If 'all' flag is set, select all orphan files (optionally filtered by search)
        if (isset($_POST['all']) && $_POST['all'] == '1') {
            $search = trim($_POST['search'] ?? '');
            if ($search !== '') {
                $stmt = $db->prepare("SELECT id, original_name, file_path, is_folder FROM files WHERE user_id IS NULL AND original_name LIKE ?");
                $like = '%' . $search . '%';
                $stmt->execute([$like]);
            } else {
                $stmt = $db->prepare("SELECT id, original_name, file_path, is_folder FROM files WHERE user_id IS NULL");
                $stmt->execute();
            }
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $fileIds = json_decode($_POST['file_ids'] ?? '[]', true);
            if (!is_array($fileIds) || empty($fileIds)) {
                echo json_encode(['success' => false, 'message' => 'No se seleccionaron archivos']);
                exit;
            }
            $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
            $types = str_repeat('i', count($fileIds));
            
            // Get orphaned files
            $stmt = $db->prepare("SELECT id, original_name, file_path, is_folder FROM files WHERE id IN ($placeholders) AND user_id IS NULL");
            $stmt->execute($fileIds);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($files)) {
            echo json_encode(['success' => false, 'message' => t('no_orphans_found')]);
            exit;
        }

        $deleted = 0;
        foreach ($files as $file) {
            try {
                // If folder, use deleteFolder
                if (!empty($file['is_folder'])) {
                    orphan_debug("BULK deleteFolder id={$file['id']} name='{$file['original_name']}'");
                    if ($fileClass->deleteFolder($file['id'], null)) {
                        $deleted++;
                        $logger->log(
                            $adminUser['id'],
                            'orphan_deleted',
                            'file',
                            $file['id'],
                            "Carpeta huérfana '{$file['original_name']}' eliminada (bulk)"
                        );
                    }
                    continue;
                }

                // Delete physical file (support absolute and relative stored paths)
                $fp = $file['file_path'] ?? '';
                if ($fp === '' || $fp === null) {
                    orphan_debug("BULK id={$file['id']} has empty file_path");
                } else {
                    if (strpos($fp, '/') === 0) {
                        $fullPath = $fp;
                    } else {
                        $fullPath = rtrim(UPLOADS_PATH, '/') . '/' . ltrim($fp, '/');
                    }
                    orphan_debug("BULK attempt id={$file['id']} name='{$file['original_name']}' db_path='{$fp}' resolved='{$fullPath}'");
                    if ($fullPath && file_exists($fullPath)) {
                        $beforePerms = sprintf('%o', fileperms($fullPath) & 0777);
                        orphan_debug("  exists=yes perms={$beforePerms} owner=" . @fileowner($fullPath));
                        $unlinkOk = @unlink($fullPath);
                        orphan_debug("  unlink_result=" . ($unlinkOk ? 'ok' : 'failed') . " error=" . json_encode(error_get_last()));
                    } else {
                        orphan_debug("  exists=no for resolved={$fullPath}");
                    }
                }
                
                // Delete database record
                $stmt = $db->prepare("DELETE FROM files WHERE id = ?");
                if ($stmt->execute([$file['id']])) {
                    $deleted++;
                    $logger->log(
                        $adminUser['id'],
                        'orphan_deleted',
                        'file',
                        $file['id'],
                        "Archivo huérfano '{$file['original_name']}' eliminado (bulk)"
                    );
                }
            } catch (Exception $e) {
                // Continue with next file
                continue;
            }
        }
        
        echo json_encode(['success' => true, 'deleted' => $deleted]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Acción no válida']);
