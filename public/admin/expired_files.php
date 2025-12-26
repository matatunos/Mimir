<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();
$fileClass = new File();
$logger = new Logger();
$db = Database::getInstance()->getConnection();

// AJAX endpoint: return list of IDs matching current filters (used for client-side batching)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'list_ids') {
    // Ensure PHP warnings/notices are not emitted into the response
    ini_set('display_errors', '0');
    error_reporting(E_ALL);

    try {
        $q_get = trim((string)($_GET['q'] ?? ''));
    $filter_get = $_GET['filter'] ?? 'expired';
    $expiredFrom_get = trim((string)($_GET['expired_from'] ?? ''));
    $expiredTo_get = trim((string)($_GET['expired_to'] ?? ''));

    $where = [];
    $params = [];
    if ($filter_get === 'expired') {
        $where[] = 'f.is_expired = 1';
    } elseif ($filter_get === 'not') {
        $where[] = 'f.is_expired = 0';
    } elseif ($filter_get === 'never') {
        $where[] = 'f.never_expire = 1';
    }
    if ($q_get !== '') {
        $where[] = '(f.original_name LIKE ? OR u.username LIKE ?)';
        $params[] = "%$q_get%";
        $params[] = "%$q_get%";
    }
    if ($expiredFrom_get !== '') { $where[] = 'f.expired_at >= ?'; $params[] = $expiredFrom_get . ' 00:00:00'; }
    if ($expiredTo_get !== '') { $where[] = 'f.expired_at <= ?'; $params[] = $expiredTo_get . ' 23:59:59'; }

    $whereSql = '';
    if (!empty($where)) $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmtIds = $db->prepare("SELECT f.id FROM files f LEFT JOIN users u ON f.user_id = u.id $whereSql ORDER BY f.id ASC");
        $stmtIds->execute($params);
        $ids = $stmtIds->fetchAll(PDO::FETCH_COLUMN);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'ids' => array_map('intval', $ids)]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

// Handle bulk admin actions
// Handle bulk admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    // Ensure PHP warnings/notices are not emitted into AJAX responses
    ini_set('display_errors', '0');
    error_reporting(E_ALL);

    $action = $_POST['action'];
    $success = 0;
    $errors = 0;

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    // Determine target file IDs. Support select_all (apply to all results matching current filters)
    $fileIds = [];
    if (!empty($_POST['select_all']) && $_POST['select_all'] === '1') {
        // Reconstruct filters from GET to select all matching IDs
        $where = [];
        $params = [];
        $q_post = trim((string)($_GET['q'] ?? ''));
        $filter_post = $_GET['filter'] ?? 'expired';
        $expiredFrom_post = trim((string)($_GET['expired_from'] ?? ''));
        $expiredTo_post = trim((string)($_GET['expired_to'] ?? ''));

        if ($filter_post === 'expired') {
            $where[] = 'f.is_expired = 1';
        } elseif ($filter_post === 'not') {
            $where[] = 'f.is_expired = 0';
        } elseif ($filter_post === 'never') {
            $where[] = 'f.never_expire = 1';
        }
        if ($q_post !== '') {
            $where[] = '(f.original_name LIKE ? OR u.username LIKE ?)';
            $params[] = "%$q_post%";
            $params[] = "%$q_post%";
        }
        if ($expiredFrom_post !== '') {
            $where[] = 'f.expired_at >= ?';
            $params[] = $expiredFrom_post . ' 00:00:00';
        }
        if ($expiredTo_post !== '') {
            $where[] = 'f.expired_at <= ?';
            $params[] = $expiredTo_post . ' 23:59:59';
        }
        $whereSql = '';
        if (!empty($where)) $whereSql = 'WHERE ' . implode(' AND ', $where);

        $idSql = "SELECT f.id FROM files f LEFT JOIN users u ON f.user_id = u.id $whereSql";
        $stmtIds = $db->prepare($idSql);
        $stmtIds->execute($params);
        $fileIds = array_map('intval', $stmtIds->fetchAll(PDO::FETCH_COLUMN));
    } else {
        if (!empty($_POST['file_ids'])) {
            $raw = $_POST['file_ids'];
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $fileIds = array_map('intval', $decoded);
                } else {
                    // fallback: if string but not JSON, treat as CSV of ints
                    $parts = preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
                    if (!empty($parts)) $fileIds = array_map('intval', $parts);
                }
            } elseif (is_array($raw)) {
                $fileIds = array_map('intval', $raw);
            }
        }
    }

    if (empty($fileIds)) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No hay archivos seleccionados']);
            exit;
        }
        header('Location: ' . BASE_URL . '/admin/expired_files.php?error=' . urlencode('No hay archivos seleccionados'));
        exit;
    }

    try {
        // If select_all was used, $fileIds may contain many items; process server-side in pages
        $isSelectAll = !empty($_POST['select_all']) && $_POST['select_all'] === '1';

        if ($isSelectAll) {
            // Rebuild filters from GET as above
            $where = [];
            $params = [];
            $q_post = trim((string)($_GET['q'] ?? ''));
            $filter_post = $_GET['filter'] ?? 'expired';
            $expiredFrom_post = trim((string)($_GET['expired_from'] ?? ''));
            $expiredTo_post = trim((string)($_GET['expired_to'] ?? ''));

            if ($filter_post === 'expired') {
                $where[] = 'f.is_expired = 1';
            } elseif ($filter_post === 'not') {
                $where[] = 'f.is_expired = 0';
            } elseif ($filter_post === 'never') {
                $where[] = 'f.never_expire = 1';
            }
            if ($q_post !== '') {
                $where[] = '(f.original_name LIKE ? OR u.username LIKE ?)';
                $params[] = "%$q_post%";
                $params[] = "%$q_post%";
            }
            if ($expiredFrom_post !== '') { $where[] = 'f.expired_at >= ?'; $params[] = $expiredFrom_post . ' 00:00:00'; }
            if ($expiredTo_post !== '') { $where[] = 'f.expired_at <= ?'; $params[] = $expiredTo_post . ' 23:59:59'; }

            $whereSql = '';
            if (!empty($where)) $whereSql = 'WHERE ' . implode(' AND ', $where);

            // Process in pages to avoid large memory use and huge JSON payloads
            $pageSize = 500;
            $offset = 0;
            $deleted = 0;
            $errors = 0;
            $processed = 0;

            if ($action === 'delete') {
                // Delete files (existing behavior)
                while (true) {
                    $stmt = $db->prepare("SELECT f.id, f.original_name, f.file_path FROM files f LEFT JOIN users u ON f.user_id = u.id $whereSql ORDER BY f.id ASC LIMIT ? OFFSET ?");
                    $execParams = array_merge($params, [$pageSize, $offset]);
                    $stmt->execute($execParams);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($rows)) break;

                    foreach ($rows as $f) {
                        try {
                            // Delete physical file (support absolute and relative stored paths)
                            $fp = $f['file_path'] ?? '';
                            if (strpos($fp, '/') === 0) {
                                $fullPath = $fp;
                            } else {
                                $fullPath = rtrim(UPLOADS_PATH, '/') . '/' . ltrim($fp, '/');
                            }
                            if ($fullPath && file_exists($fullPath)) {
                                @unlink($fullPath);
                            }

                            // Delete database record
                            $delStmt = $db->prepare("DELETE FROM files WHERE id = ?");
                            if ($delStmt->execute([$f['id']])) {
                                $deleted++;
                                $logger->log($user['id'], 'orphan_deleted', 'file', $f['id'], "Archivo huérfano '{$f['original_name']}' eliminado (bulk paged)");
                            } else {
                                $errors++;
                            }
                        } catch (Exception $e) {
                            $errors++;
                            continue;
                        }
                    }

                    // advance offset
                    if (count($rows) < $pageSize) break;
                    $offset += $pageSize;
                }
            } else {
                // Other actions (restore / never / reexpire) applied page-by-page
                while (true) {
                    $stmt = $db->prepare("SELECT f.id, f.original_name FROM files f LEFT JOIN users u ON f.user_id = u.id $whereSql ORDER BY f.id ASC LIMIT ? OFFSET ?");
                    $execParams = array_merge($params, [$pageSize, $offset]);
                    $stmt->execute($execParams);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($rows)) break;

                    foreach ($rows as $f) {
                        try {
                            switch ($action) {
                                case 'restore':
                                    $uStmt = $db->prepare("UPDATE files SET is_expired = 0, expired_at = NULL WHERE id = ?");
                                    if ($uStmt->execute([$f['id']])) {
                                        $processed++;
                                        $logger->log($user['id'], 'file_restore', 'file', $f['id'], "Admin restauró archivo expirado: {$f['original_name']} (bulk paged)");
                                    } else { $errors++; }
                                    break;
                                case 'never':
                                    $uStmt = $db->prepare("UPDATE files SET never_expire = 1 WHERE id = ?");
                                    if ($uStmt->execute([$f['id']])) {
                                        $processed++;
                                        $logger->log($user['id'], 'file_never_expire', 'file', $f['id'], "Admin marcó como nunca expirar: {$f['original_name']} (bulk paged)");
                                    } else { $errors++; }
                                    break;
                                case 'reexpire':
                                    $uStmt = $db->prepare("UPDATE files SET never_expire = 0, is_expired = 1, expired_at = NOW() WHERE id = ?");
                                    if ($uStmt->execute([$f['id']])) {
                                        $processed++;
                                        $logger->log($user['id'], 'file_reexpired', 'file', $f['id'], "Admin re-expiró archivo: {$f['original_name']} (bulk paged)");
                                    } else { $errors++; }
                                    break;
                                default:
                                    $errors++; break;
                            }
                        } catch (Exception $e) {
                            $errors++; continue;
                        }
                    }

                    // advance offset
                    if (count($rows) < $pageSize) break;
                    $offset += $pageSize;
                }
            }

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'deleted' => $deleted, 'processed' => $processed, 'errors' => $errors]);
                exit;
            }

            // Non-AJAX response
            if ($action === 'delete') {
                $msg = "Acción completada: $deleted eliminados";
                if ($errors > 0) $msg .= ", $errors errores";
            } else {
                $msg = "Acción completada: $processed aplicadas";
                if ($errors > 0) $msg .= ", $errors errores";
            }
            header('Location: ' . BASE_URL . '/admin/expired_files.php?success=' . urlencode($msg));
            exit;
        }

        // Non-select_all path: existing behavior (process provided $fileIds array)
        $db->beginTransaction();
        foreach ($fileIds as $fid) {
            $f = $fileClass->getById($fid);
            if (!$f) { $errors++; continue; }

            switch ($action) {
                case 'restore':
                    $stmt = $db->prepare("UPDATE files SET is_expired = 0, expired_at = NULL WHERE id = ?");
                    if ($stmt->execute([$fid])) {
                        $logger->log($user['id'], 'file_restore', 'file', $fid, 'Admin restauró archivo expirado: ' . $f['original_name']);
                        $success++;
                    } else { $errors++; }
                    break;
                case 'never':
                    // Changed behavior: only mark as never_expire, do NOT modify is_expired/expired_at
                    $stmt = $db->prepare("UPDATE files SET never_expire = 1 WHERE id = ?");
                    if ($stmt->execute([$fid])) {
                        $logger->log($user['id'], 'file_never_expire', 'file', $fid, 'Admin marcó como nunca expirar: ' . $f['original_name']);
                        $success++;
                    } else { $errors++; }
                    break;
                case 'delete':
                    if ($fileClass->delete($fid, $user['id'])) {
                        $logger->log($user['id'], 'file_delete', 'file', $fid, 'Admin eliminó archivo expirado: ' . $f['original_name']);
                        $success++;
                    } else { $errors++; }
                    break;
                case 'reexpire':
                    $stmt = $db->prepare("UPDATE files SET never_expire = 0, is_expired = 1, expired_at = NOW() WHERE id = ?");
                    if ($stmt->execute([$fid])) {
                        $logger->log($user['id'], 'file_reexpired', 'file', $fid, 'Admin re-expiró archivo: ' . $f['original_name']);
                        $success++;
                    } else { $errors++; }
                    break;
                default:
                    $errors++; break;
            }
        }
        $db->commit();

        // If the admin used "select all" (apply to all matching results), add a single bulk log entry
        if (!empty($_POST['select_all']) && $_POST['select_all'] === '1') {
            // collect applied filters for auditability
            $applied = [];
            $filterKeys = ['filter','q','expired_from','expired_to'];
            foreach ($filterKeys as $k) {
                if (isset($_GET[$k]) && $_GET[$k] !== '') {
                    $applied[$k] = (string)$_GET[$k];
                }
            }
            $filterStr = '';
            if (!empty($applied)) {
                $parts = [];
                foreach ($applied as $k => $v) {
                    $val = preg_replace('/\s+/', ' ', $v);
                    $parts[] = "$k=" . $val;
                }
                $filterStr = ' Filters: ' . implode(', ', $parts);
            }

            $details = sprintf('Acción masiva "%s" aplicada por admin %d a %d elementos (exitosos=%d, errores=%d)', $action, $user['id'], count($fileIds), $success, $errors) . $filterStr;
            $logger->log($user['id'], 'file_bulk_action_all', 'files', null, $details);
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'deleted' => $success, 'errors' => $errors]);
            exit;
        }

        $msg = "Acción completada: $success exitosos";
        if ($errors > 0) $msg .= ", $errors errores";
        header('Location: ' . BASE_URL . '/admin/expired_files.php?success=' . urlencode($msg));
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        header('Location: ' . BASE_URL . '/admin/expired_files.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Pagination
$layoutIncluded = false;
$config = (function(){
    require_once __DIR__ . '/../../classes/Config.php';
    return new Config();
})();
$perPage = isset($_GET['per_page']) ? max(10, min(200, intval($_GET['per_page']))) : (int)$config->get('items_per_page', 25);
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Search / filter / sort parameters
$q = trim((string)($_GET['q'] ?? ''));
$filter = 'expired'; // force view to only show expired files
$expiredFrom = trim((string)($_GET['expired_from'] ?? ''));
$expiredTo = trim((string)($_GET['expired_to'] ?? ''));
$sortBy = $_GET['sort_by'] ?? 'expired_at';
$sortOrder = strtolower($_GET['sort_order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

// Whitelist sortable columns
$sortable = [
    'name' => 'f.original_name',
    'user' => 'u.username',
    'expired_at' => 'f.expired_at',
    'size' => 'f.file_size',
    'created_at' => 'f.created_at',
    'status' => 'f.is_expired',
    'never' => 'f.never_expire',
];

$orderBy = $sortable[$sortBy] ?? 'f.expired_at';

// Build WHERE
$where = [];
$params = [];
if ($filter === 'expired') {
    $where[] = 'f.is_expired = 1';
} elseif ($filter === 'not') {
    $where[] = 'f.is_expired = 0';
} elseif ($filter === 'never') {
    $where[] = 'f.never_expire = 1';
}
if ($q !== '') {
    $where[] = '(f.original_name LIKE ? OR u.username LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
}

// Date range for expired_at
if ($expiredFrom !== '') {
    $where[] = 'f.expired_at >= ?';
    $params[] = $expiredFrom . ' 00:00:00';
}
if ($expiredTo !== '') {
    $where[] = 'f.expired_at <= ?';
    $params[] = $expiredTo . ' 23:59:59';
}

$whereSql = '';
if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

// Count
$countSql = "SELECT COUNT(*) FROM files f LEFT JOIN users u ON f.user_id = u.id $whereSql";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

// Fetch files with sorting and pagination
$dataSql = "SELECT f.*, u.username FROM files f LEFT JOIN users u ON f.user_id = u.id $whereSql ORDER BY $orderBy $sortOrder LIMIT ? OFFSET ?";
$paramsWithLimit = array_merge($params, [$perPage, $offset]);
$stmt = $db->prepare($dataSql);
$stmt->execute($paramsWithLimit);
$files = $stmt->fetchAll();

// For testing: allow injecting fake rows when ?fake=1 is present
if (empty($files) && isset($_GET['fake']) && $_GET['fake'] === '1') {
    $files = [];
    $now = time();
    for ($i = 1; $i <= 12; $i++) {
        $files[] = [
            'id' => 100000 + $i,
            'original_name' => "test_file_$i.pdf",
            'username' => ($i % 3 === 0) ? 'alice' : (($i % 3) === 1 ? 'bob' : 'carol'),
            'is_expired' => 1,
            'never_expire' => 0,
            'expired_at' => date('Y-m-d H:i:s', $now - ($i * 86400)),
            'file_size' => rand(1024 * 100, 1024 * 1024 * 5),
        ];
    }
    $total = count($files);
    $totalPages = 1;
}

// Include layout just before rendering the HTML page to avoid any accidental
// output before AJAX handlers above. This prevents HTML from polluting JSON.
require_once __DIR__ . '/../../includes/layout.php';
renderPageStart('Archivos Expirados', 'expired_files', true);
renderHeader('Archivos Expirados', $user);
// helper to build links preserving query
function buildQuery(array $overrides = []) {
    $q = array_merge($_GET, $overrides);
    return '?' . http_build_query($q);
}
?>
<div class="content">
    <form method="GET" class="form-inline" style="margin-bottom:1rem; display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sortBy); ?>">
        <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars(strtolower($sortOrder) === 'asc' ? 'asc' : 'desc'); ?>">
        <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="<?php echo t('search'); ?>" class="form-control" />
        <input type="hidden" name="filter" value="expired">
        <div style="padding:0.35rem 0.5rem; color:var(--text-muted);">Mostrando solo archivos expirados</div>
        <label style="display:flex; gap:.25rem; align-items:center;">Desde: <input type="date" name="expired_from" value="<?php echo htmlspecialchars($expiredFrom); ?>" class="form-control" /></label>
        <label style="display:flex; gap:.25rem; align-items:center;">Hasta: <input type="date" name="expired_to" value="<?php echo htmlspecialchars($expiredTo); ?>" class="form-control" /></label>
        <button class="btn btn-primary" type="submit"><?php echo t('search'); ?></button>
        <div style="margin-left:auto; display:flex; gap:.5rem; align-items:center;">Per page:
            <select name="per_page" onchange="this.form.submit()">
                <?php foreach ([10,25,50,100] as $v): ?>
                    <option value="<?php echo $v; ?>" <?php echo $perPage===$v ? 'selected' : ''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php if (isset($_GET['success'])): ?><div class="alert alert-success"><?php echo htmlspecialchars($_GET['success']); ?></div><?php endif; ?>
    <?php if (isset($_GET['error'])): ?><div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div><?php endif; ?>

    <div class="card">
        <div class="card-header"><h3>Archivos Expirados (<?php echo $total; ?>)</h3></div>
        <div class="card-body">
            <?php if (empty($files)): ?>
                <p>No hay archivos expirados.</p>
            <?php else: ?>
                <form method="POST" id="expiredForm">
                    <input type="hidden" name="action" id="expiredAction" value="">
                    <input type="hidden" name="select_all" id="selectAllFlag" value="0">
                    <table id="expiredTable" class="table">
                        <thead>
                            <tr>
                                <th style="width:40px;"><input type="checkbox" id="selectAllExpired"></th>
                                <th><a href="<?php echo buildQuery(['sort_by'=>'name','sort_order'=>($sortBy==='name' && $sortOrder==='ASC') ? 'desc' : 'asc']); ?>">Archivo<?php if($sortBy==='name') echo $sortOrder==='ASC' ? ' ▲' : ' ▼'; ?></a></th>
                                <th><a href="<?php echo buildQuery(['sort_by'=>'user','sort_order'=>($sortBy==='user' && $sortOrder==='ASC') ? 'desc' : 'asc']); ?>">Propietario<?php if($sortBy==='user') echo $sortOrder==='ASC' ? ' ▲' : ' ▼'; ?></a></th>
                                <th><a href="<?php echo buildQuery(['sort_by'=>'status','sort_order'=>($sortBy==='status' && $sortOrder==='ASC') ? 'desc' : 'asc']); ?>">Expirado<?php if($sortBy==='status') echo $sortOrder==='ASC' ? ' ▲' : ' ▼'; ?></a></th>
                                <th><a href="<?php echo buildQuery(['sort_by'=>'never','sort_order'=>($sortBy==='never' && $sortOrder==='ASC') ? 'desc' : 'asc']); ?>">Nunca expirar<?php if($sortBy==='never') echo $sortOrder==='ASC' ? ' ▲' : ' ▼'; ?></a></th>
                                <th><a href="<?php echo buildQuery(['sort_by'=>'expired_at','sort_order'=>($sortBy==='expired_at' && $sortOrder==='ASC') ? 'desc' : 'asc']); ?>">Fecha expirado<?php if($sortBy==='expired_at') echo $sortOrder==='ASC' ? ' ▲' : ' ▼'; ?></a></th>
                                <th><a href="<?php echo buildQuery(['sort_by'=>'size','sort_order'=>($sortBy==='size' && $sortOrder==='ASC') ? 'desc' : 'asc']); ?>">Tamaño<?php if($sortBy==='size') echo $sortOrder==='ASC' ? ' ▲' : ' ▼'; ?></a></th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($files as $f): ?>
                            <tr data-never="<?php echo (int)($f['never_expire'] ?? 0); ?>">
                                <td><input type="checkbox" name="file_ids[]" value="<?php echo $f['id']; ?>" class="expired-item"></td>
                                <td><?php echo htmlspecialchars($f['original_name']); ?></td>
                                <td><?php echo htmlspecialchars($f['username'] ?? '-'); ?></td>
                                <td style="text-align:center;">
                                    <?php $isExpired = ((int)($f['is_expired'] ?? 0) === 1); ?>
                                    <input type="checkbox" disabled <?php echo $isExpired ? 'checked="checked"' : ''; ?> title="<?php echo $isExpired ? 'Sí' : 'No'; ?>" aria-label="<?php echo $isExpired ? 'Sí' : 'No'; ?>" />
                                </td>
                                <td style="text-align:center;">
                                    <?php $never = ((int)($f['never_expire'] ?? 0) === 1); ?>
                                    <input type="checkbox" disabled <?php echo $never ? 'checked="checked"' : ''; ?> title="<?php echo $never ? 'Sí' : 'No'; ?>" aria-label="<?php echo $never ? 'Sí' : 'No'; ?>" />
                                </td>
                                <td>
                                    <?php
                                        $expiredAt = $f['expired_at'] ?? null;
                                        if (!empty($expiredAt) && strtotime($expiredAt) !== false) {
                                            echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($expiredAt)));
                                        } else {
                                            echo htmlspecialchars((string)$expiredAt);
                                        }
                                    ?>
                                </td>
                                <td><?php echo number_format($f['file_size']/1024/1024,2); ?> MB</td>
                                <td class="actions" style="white-space:nowrap;">
                                    <?php $neverFlag = ((int)($f['never_expire'] ?? 0) === 1); ?>
                                    <div class="action-group" style="display:inline-flex; gap:0.25rem; align-items:center;">
                                        <button type="button" class="btn btn-sm btn-success" onclick="expiredDoActionSingle('restore', <?php echo $f['id']; ?>)">Restaurar</button>
                                        <button type="button" class="btn btn-sm btn-warning" onclick="expiredDoActionSingle('never', <?php echo $f['id']; ?>)" <?php echo $neverFlag ? 'disabled="disabled"' : ''; ?>>Nunca expirar</button>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="expiredDoActionSingle('reexpire', <?php echo $f['id']; ?>)" <?php echo $neverFlag ? '' : 'disabled="disabled"'; ?>>Re-expirar</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>

                <div style="display:flex; gap:0.5rem; align-items:center; margin-top:1rem;">
                    <button class="btn btn-warning" onclick="expiredDoAction('restore')">Restaurar seleccionados</button>
                    <button id="bulkNeverBtn" class="btn btn-info" onclick="expiredDoAction('never')">Marcar nunca expirar</button>
                    <button id="bulkReexpireBtn" class="btn btn-secondary" onclick="expiredDoAction('reexpire')">Re-expirar seleccionados</button>
                    <button class="btn btn-danger" onclick="expiredDoAction('delete')">Eliminar seleccionados</button>
                </div>

                <!-- Processing overlay (progress bar + logs) -->
                <div id="processingOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.66); z-index:2147483647; align-items:center; justify-content:center;">
                    <div style="background:rgba(0,0,0,0.7); color:white; text-align:left; width:720px; max-width:95%; border-radius:8px; padding:1rem;">
                        <div style="display:flex; gap:1rem; align-items:center;">
                            <style>
                                .mimir-spinner { width:40px; height:40px; border:5px solid rgba(255,255,255,0.12); border-top-color:#fff; border-radius:50%; animation:mimir-spin 1s linear infinite; margin-right:0.75rem; }
                                @keyframes mimir-spin { to { transform: rotate(360deg); } }
                            </style>
                            <div><div class="mimir-spinner" aria-hidden="true"></div></div>
                            <div style="flex:1;">
                                <div id="processingMessage" style="font-size:1.05rem; margin-bottom:0.5rem;">Procesando, por favor espere...</div>
                                <div style="background: rgba(255,255,255,0.12); border-radius:6px; overflow:hidden; height:14px; position:relative;">
                                    <div id="processingBarFill" style="background: linear-gradient(90deg,#4a90e2,#50c878); height:100%; width:0%; transition:width 250ms ease;"></div>
                                </div>
                                <div id="processingPercent" style="margin-top:6px; font-size:0.9rem; opacity:0.95;">0%</div>
                            </div>
                            <div style="min-width:120px; text-align:right; font-size:0.95rem; color: #fff; opacity:0.9;">
                                <div id="processingMiniStatus">Iniciado</div>
                            </div>
                        </div>
                        <div id="processingLogs" style="margin-top:0.75rem; max-height:180px; overflow:auto; background: rgba(255,255,255,0.04); border-radius:6px; padding:0.5rem; font-size:0.9rem; color:#fff; border:1px solid rgba(255,255,255,0.04);">
                        </div>
                    </div>
                </div>

                <!-- Floating actions (centered) -->
                <div id="floatingActions" style="position:fixed; left:50%; transform:translateX(-50%); bottom:1rem; z-index:1000; display:none; max-width:95%;">
                    <div style="background:#fff; border:1px solid #ddd; padding:0.5rem 0.75rem; border-radius:8px; box-shadow:0 8px 20px rgba(0,0,0,0.12); display:flex; gap:0.5rem; align-items:center; min-width:520px; flex-wrap:nowrap; overflow-x:auto; justify-content:space-between;">
                        <span id="selectedCount">0 seleccionados</span>
                        <span id="selectedAllBadge" style="display:none; background:#007bff; color:#fff; padding:2px 6px; border-radius:12px; font-size:0.85em; margin-left:0.5rem;">Todos <?php echo $total; ?> seleccionados</span>
                        <a href="#" id="floatingSelectAllResults" style="margin-left:0.5rem;">Seleccionar los <?php echo $total; ?> resultados</a>
                        <div style="display:flex; gap:0.5rem;">
                            <button class="btn btn-warning" onclick="promptBulkAction('restore')">Restaurar</button>
                            <button id="floatingNeverBtn" class="btn btn-info" onclick="promptBulkAction('never')">Nunca expirar</button>
                            <button id="floatingReexpireBtn" class="btn btn-secondary" onclick="promptBulkAction('reexpire')">Re-expirar</button>
                            <button class="btn btn-danger" onclick="promptBulkAction('delete')">Eliminar</button>
                        </div>
                    </div>
                </div>

                <style>
                    /* Ensure floating actions don't wrap buttons onto multiple lines */
                    #floatingActions > div { -webkit-overflow-scrolling: touch; }
                    #floatingActions .btn { white-space: nowrap; }
                    /* Ensure per-row action buttons stay on one line */
                    #expiredTable td.actions { white-space: nowrap; }
                    #expiredTable td.actions .action-group { display: inline-flex; gap: 0.25rem; align-items: center; }
                    #expiredTable td.actions .btn { white-space: nowrap; padding: 0.25rem 0.5rem; font-size: 0.86rem; }
                </style>

                <!-- Confirmation modal -->
                <div id="confirmModal" style="display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center;">
                    <div style="background:#fff; padding:1rem 1.25rem; border-radius:8px; max-width:540px; width:90%; box-shadow:0 8px 30px rgba(0,0,0,0.2);">
                        <h4 id="confirmTitle">Confirmar acción</h4>
                        <p id="confirmBody">¿Estás seguro?</p>
                        <div style="display:flex; gap:0.5rem; justify-content:flex-end; margin-top:1rem;">
                            <button id="confirmCancel" class="btn btn-secondary"><?php echo htmlspecialchars(t('cancel')); ?></button>
                            <button id="confirmProceed" class="btn btn-primary">Confirmar</button>
                        </div>
                    </div>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination" style="margin-top:1rem;">
                        <?php if ($page > 1): ?><a href="<?php echo buildQuery(['page'=>$page-1]); ?>" class="page-link"><?php echo t('previous'); ?></a><?php endif; ?>
                        <span class="page-info"><?php echo sprintf(t('page_of'), $page, $totalPages); ?></span>
                        <?php if ($page < $totalPages): ?><a href="<?php echo buildQuery(['page'=>$page+1]); ?>" class="page-link"><?php echo t('next'); ?></a><?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Load jQuery + DataTables (client-side enhancements) -->
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-dt@1.13.6/css/jquery.dataTables.min.css">
                <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
                <script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.6/js/jquery.dataTables.min.js"></script>

                <script>
                // Provide a minimal jQuery-like fallback if CDN is blocked
                if (typeof window.jQuery === 'undefined') {
                    (function(){
                        function $(sel){
                            var nodes = [];
                            if (sel === document || sel === window.document) nodes = [document];
                            else nodes = Array.prototype.slice.call(document.querySelectorAll(sel));
                            var obj = {
                                nodes: nodes,
                                length: nodes.length,
                                on: function(event, selectorOrHandler, handler){
                                    if (typeof selectorOrHandler === 'string' && handler){
                                        // delegated
                                        document.addEventListener(event, function(e){
                                            var t = e.target.closest(selectorOrHandler);
                                            if (t) handler.call(t, e);
                                        });
                                    } else {
                                        var fn = (typeof selectorOrHandler === 'function') ? selectorOrHandler : handler;
                                        this.nodes.forEach(function(n){ n.addEventListener(event, fn); });
                                    }
                                },
                                prop: function(p, v){ if (p === 'checked') this.nodes.forEach(n=>n.checked = v); },
                                val: function(v){ if (v === undefined) return this.nodes[0] ? this.nodes[0].value : undefined; this.nodes.forEach(n=>n.value = v); },
                                text: function(t){ this.nodes.forEach(n=>n.textContent = t); },
                                show: function(){ this.nodes.forEach(n=>n.style.display = 'block'); },
                                hide: function(){ this.nodes.forEach(n=>n.style.display = 'none'); },
                                fadeIn: function(){ this.nodes.forEach(n=>n.style.display = 'flex'); },
                                fadeOut: function(){ this.nodes.forEach(n=>n.style.display = 'none'); },
                                each: function(cb){ this.nodes.forEach(function(n,i){ cb.call(n,i,n); }); },
                                ready: function(cb){ if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', cb); else cb(); }
                            };
                            return obj;
                        }
                        window.$ = window.jQuery = $;
                        // minimal document ready
                        window.$.ready = function(cb){ if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', cb); else cb(); };
                    })();
                }

                // enhance table (disable built-in paging/ordering because server handles that)
                $(document).ready(function(){
                    try { $('#expiredTable').DataTable({ paging:false, ordering:false, info:false }); } catch(e) {}

                    // header checkbox: toggle visible checkboxes
                    $('#selectAllExpired').on('change', function(){
                        $('.expired-item').prop('checked', this.checked);
                        $('#selectAllFlag').val('0');
                        updateSelectedCount();
                    });

                    // select all results across pages (header link removed; use floating bar link)
                        var $selectAllResults = $('#selectAllResults');
                        if ($selectAllResults.length) {
                            $selectAllResults.on('click', function(e){
                                e.preventDefault();
                                $('.expired-item').prop('checked', true);
                                $('#selectAllFlag').val('1');
                                updateSelectedCount();
                                alert('Se seleccionarán todos los resultados al confirmar.');
                            });
                        }

                    // floating bar: select all results link
                    $('#floatingSelectAllResults').on('click', function(e){
                        e.preventDefault();
                        $('.expired-item').prop('checked', true);
                        $('#selectAllFlag').val('1');
                        updateSelectedCount();
                        alert('Se seleccionarán todos los resultados al confirmar.');
                    });

                    // update selected count when individual items change
                    $(document).on('change', '.expired-item', function(){ updateSelectedCount(); });

                    // initial count
                    updateSelectedCount();

                    function updateSelectedCount(){
                        var selectAll = $('#selectAllFlag').val() === '1';
                        var cnt = $('.expired-item:checked').length;
                        if(selectAll){
                            $('#selectedCount').text('Todos ' + <?php echo (int)$total; ?> + ' seleccionados');
                            $('#selectedAllBadge').show();
                        } else {
                            $('#selectedCount').text(cnt + ' seleccionados');
                            $('#selectedAllBadge').hide();
                        }
                        if(cnt > 0 || selectAll){ $('#floatingActions').show(); } else { $('#floatingActions').hide(); $('#selectAllFlag').val('0'); }

                        // Enable/disable bulk/floating action buttons depending on selection's never_expire state
                        try {
                            var selectedRows = Array.from(document.querySelectorAll('.expired-item:checked')).map(function(cb){ return cb.closest('tr'); });
                            var allNever = selectedRows.length > 0 && selectedRows.every(function(r){ return r && r.dataset && r.dataset.never === '1'; });
                            var allNotNever = selectedRows.length > 0 && selectedRows.every(function(r){ return r && r.dataset && r.dataset.never === '0'; });
                            var floatingNever = document.getElementById('floatingNeverBtn');
                            var floatingReexpire = document.getElementById('floatingReexpireBtn');
                            var bulkNever = document.getElementById('bulkNeverBtn');
                            var bulkReexpire = document.getElementById('bulkReexpireBtn');
                            if (floatingNever) floatingNever.disabled = false;
                            if (floatingReexpire) floatingReexpire.disabled = false;
                            if (bulkNever) bulkNever.disabled = false;
                            if (bulkReexpire) bulkReexpire.disabled = false;

                            if (selectedRows.length === 0) {
                                if (floatingNever) floatingNever.disabled = true;
                                if (floatingReexpire) floatingReexpire.disabled = true;
                                if (bulkNever) bulkNever.disabled = true;
                                if (bulkReexpire) bulkReexpire.disabled = true;
                            } else if (allNever) {
                                // all are never: enable reexpire, disable never
                                if (floatingNever) floatingNever.disabled = true;
                                if (bulkNever) bulkNever.disabled = true;
                                if (floatingReexpire) floatingReexpire.disabled = false;
                                if (bulkReexpire) bulkReexpire.disabled = false;
                            } else if (allNotNever) {
                                // none are never: enable never, disable reexpire
                                if (floatingNever) floatingNever.disabled = false;
                                if (bulkNever) bulkNever.disabled = false;
                                if (floatingReexpire) floatingReexpire.disabled = true;
                                if (bulkReexpire) bulkReexpire.disabled = true;
                            } else {
                                // mixed: enable both
                                if (floatingNever) floatingNever.disabled = false;
                                if (floatingReexpire) floatingReexpire.disabled = false;
                                if (bulkNever) bulkNever.disabled = false;
                                if (bulkReexpire) bulkReexpire.disabled = false;
                            }
                        } catch(e) { /* fail silently */ }
                    }
                });

                // Use AJAX for bulk actions to avoid full page submit
                function performBulkAction(action){
                    const form = document.getElementById('expiredForm');
                    const fd = new FormData(form);
                    fd.set('action', action);

                    // If select_all is set, fetch full ID list and process in batches with progress
                    const selectAll = document.getElementById('selectAllFlag').value === '1';
                    const BATCH_SIZE = 50;
                    if (selectAll) {
                        showProcessing('Obteniendo lista de archivos...', { clearLogs: true, percent: 0, status: 'Obteniendo lista' });
                        // build query string from current filters
                        const qs = window.location.search ? window.location.search.substring(1) : '';
                        const listUrl = location.pathname + '?action=list_ids&' + qs;
                        showProcessing('Obteniendo y procesando archivos por páginas...', { clearLogs: true, percent: 0, status: 'Iniciando' });
                        Mimir.processListIdsInPages(listUrl, action, 500, 100, {
                            onProgress: function(processed, total) { updateProcessingProgress(total ? Math.round((processed/total)*100) : 0, `Procesados ${processed} / ${total||'?'} `); },
                            onLog: function(txt) { appendProcessingLog(txt); },
                            onError: function(err) { hideProcessing(); console.error('Error obteniendo lista (expired_files paginado):', err); if (err && (err.code === 'AUTH_REQUIRED')) { Mimir.showAuthBanner('Sesión expirada o no autorizada. Reautentica en otra pestaña y recarga.'); return; } alert('Error obteniendo lista: ' + (err.message || '')); },
                            onComplete: function() { hideProcessing(); window.location.reload(); }
                        });
                        return;
                    }

                    // fallback: process selected ids in batches (even small selections)
                    const selectedInputs = Array.from(document.querySelectorAll('input[name="file_ids[]"]:checked')).map(n => parseInt(n.value));
                    if (!selectedInputs || selectedInputs.length === 0) { alert('No hay archivos seleccionados'); return; }
                    showProcessing('Procesando selección...', { clearLogs: true, percent: 0, status: 'Enviando' });
                    processIdsInBatches(selectedInputs, action, 50, function(){ hideProcessing(); window.location.reload(); });
                }

                // Processing overlay helpers (progress + logs)
                // Simple spinner: show/hide overlay and update message. Keep minimal progress/log support.
                function showProcessing(msg, options = {}) {
                    const overlay = document.getElementById('processingOverlay');
                    const msgEl = document.getElementById('processingMessage');
                    const logs = document.getElementById('processingLogs');
                    if (!overlay) return;
                    if (msgEl && msg) msgEl.textContent = msg;
                    if (options.clearLogs && logs) logs.innerHTML = '';
                    overlay.style.display = 'flex';
                }

                function hideProcessing() {
                    const overlay = document.getElementById('processingOverlay');
                    if (!overlay) return;
                    overlay.style.display = 'none';
                }

                function updateProcessingProgress(percent, statusMessage) {
                    const bar = document.getElementById('processingBarFill');
                    const percentEl = document.getElementById('processingPercent');
                    const mini = document.getElementById('processingMiniStatus');
                    if (bar) bar.style.width = Math.max(0, Math.min(100, Math.round(percent))) + '%';
                    if (percentEl) percentEl.textContent = Math.max(0, Math.min(100, Math.round(percent))) + '%';
                    if (mini && statusMessage) mini.textContent = statusMessage;
                }

                function appendProcessingLog(text) {
                    const logs = document.getElementById('processingLogs');
                    if (!logs) { console.log('[processing log]', text); return; }
                    const div = document.createElement('div');
                    div.textContent = text;
                    logs.appendChild(div);
                    logs.scrollTop = logs.scrollHeight;
                }

                // Generic batching helper for expired files page
                function processIdsInBatches(ids, action, batchSize, onComplete) {
                    let processed = 0;
                    let successTotal = 0;

                    function chunkProcess(start) {
                        const chunk = ids.slice(start, start + batchSize);
                        updateProcessingProgress((processed / ids.length) * 100, `Procesando ${Math.min(start + batchSize, ids.length)} / ${ids.length}`);
                        const chunkFd = new FormData();
                        chunkFd.append('action', action);
                        chunkFd.append('file_ids', JSON.stringify(chunk));

                        fetch(location.pathname + location.search, { method: 'POST', body: chunkFd, credentials: 'same-origin' })
                            .then(function(resp){
                                if (!resp.ok) {
                                    // handle auth specifically
                                    var err = new Error('HTTP ' + resp.status + ' ' + resp.statusText);
                                    err.status = resp.status;
                                    throw err;
                                }
                                return resp.json().catch(function(){ return null; });
                            })
                            .then(function(resp){
                                if (resp && resp.success) {
                                    successTotal += (resp.deleted || resp.count || chunk.length);
                                    appendProcessingLog('Lote procesado: ' + chunk.length + ' elementos' + (resp.message ? ' — ' + resp.message : ''));
                                } else {
                                    appendProcessingLog('Lote con error: ' + (resp && resp.message ? resp.message : 'Desconocido'));
                                }
                                processed += chunk.length;
                                updateProcessingProgress((processed / ids.length) * 100, 'Procesado ' + processed + ' / ' + ids.length);
                                if (start + batchSize < ids.length) setTimeout(function(){ chunkProcess(start + batchSize); }, 150);
                                else { updateProcessingProgress(100, 'Finalizado'); appendProcessingLog('Operación finalizada. Procesados: ' + processed + '. Éxitos estimados: ' + successTotal); if (typeof onComplete === 'function') setTimeout(onComplete, 600); }
                            }).catch(function(err){
                                hideProcessing();
                                if (err && err.status === 401) {
                                    appendProcessingLog('Error de red: Autenticación requerida (sesión expirada o no autorizado)');
                                    alert('Error de red: Autenticación requerida (sesión expirada o no autorizado)');
                                    // as a fallback, perform a full form submit to let server redirect to login or handle logout
                                    try { document.getElementById('expiredForm').submit(); } catch(e) {}
                                    return;
                                }
                                appendProcessingLog('Error de red: ' + (err && err.message ? err.message : 'Desconocido'));
                                alert('Error de red: ' + (err && err.message ? err.message : 'Error desconocido'));
                            });
                    }

                    chunkProcess(0);
                }

                function promptBulkAction(action){
                    // If select_all is set, show modal with total count and action name
                    var selectAll = $('#selectAllFlag').val() === '1';
                    if(selectAll){
                        $('#confirmTitle').text('Confirmar acción en todos los resultados');
                        $('#confirmBody').text('Vas a aplicar "' + action + '" a TODOS los ' + <?php echo (int)$total; ?> + ' resultados que coinciden con los filtros. ¿Confirmas?');
                        $('#confirmModal').fadeIn(150);
                        $('#confirmCancel').off('click').on('click', function(){ $('#confirmModal').fadeOut(100); });
                        $('#confirmProceed').off('click').on('click', function(){ $('#confirmModal').fadeOut(100); performBulkAction(action); });
                    } else {
                        if(!confirm(<?php echo json_encode(t('confirm_action')); ?>)) return;
                        performBulkAction(action);
                    }
                }

                function expiredDoActionSingle(action, id){
                    if(!confirm(<?php echo json_encode(t('confirm_action')); ?>)) return;
                    const ids = [parseInt(id)];
                    showProcessing('Ejecutando acción...', { clearLogs: true, percent: 0, status: 'Enviando' });
                    processIdsInBatches(ids, action, 50, function(){ hideProcessing(); window.location.reload(); });
                }
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php renderPageEnd(); ?>
