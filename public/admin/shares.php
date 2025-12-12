<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Config.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/Share.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();
$shareClass = new Share();
$db = Database::getInstance()->getConnection();

// Handle bulk actions from admin UI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && !empty($_POST['share_ids'])) {
    $action = $_POST['action'];
    $shareIds = array_map('intval', $_POST['share_ids']);
    $success = 0;
    $errors = 0;

    try {
        $db->beginTransaction();
        foreach ($shareIds as $sid) {
            $share = $shareClass->getById($sid);
            if (!$share) { $errors++; continue; }

            switch ($action) {
                case 'unshare':
                    $stmt = $db->prepare("UPDATE shares SET is_active = 0 WHERE id = ?");
                    if ($stmt->execute([$sid])) {
                        // Update file shared status
                        $fileId = $share['file_id'];
                        $fileClass = new File();
                        $fileClass->updateSharedStatus($fileId);
                        $logger = new Logger();
                        $logger->log($user['id'], 'share_deactivate', 'share', $sid, 'Admin desactivó share: ' . $share['share_name']);
                        $success++;
                    } else {
                        $errors++;
                    }
                    break;
                case 'delete':
                    $stmt = $db->prepare("DELETE FROM shares WHERE id = ?");
                    if ($stmt->execute([$sid])) {
                        // Update file shared status
                        $fileClass = new File();
                        $fileClass->updateSharedStatus($share['file_id']);
                        $logger = new Logger();
                        $logger->log($user['id'], 'share_deleted', 'share', $sid, 'Admin eliminó share: ' . $share['share_name']);
                        $success++;
                    } else {
                        $errors++;
                    }
                    break;
                case 'resend_notification':
                    // Allow admin to pass an override recipient via POST (override_recipient)
                        $override = trim($_POST['override_recipient'] ?? '');
                        $override = $override === '' ? null : $override;

                        // Optional override fields: extend_days, add_downloads, max_downloads, password
                        $extendDays = isset($_POST['extend_days']) ? intval($_POST['extend_days']) : null;
                        $addDownloads = isset($_POST['add_downloads']) ? intval($_POST['add_downloads']) : null;
                        $maxDownloads = isset($_POST['max_downloads']) && $_POST['max_downloads'] !== '' ? intval($_POST['max_downloads']) : null;
                        $passwordPlain = trim($_POST['password'] ?? '');

                        // If any override provided, update the share record accordingly (reactivate if needed)
                        $updates = [];
                        $paramsUpd = [];
                        if ($extendDays && $extendDays > 0) {
                            if (!empty($share['expires_at'])) {
                                $newExp = date('Y-m-d H:i:s', strtotime($share['expires_at'] . " +{$extendDays} days"));
                            } else {
                                $newExp = date('Y-m-d H:i:s', strtotime("+{$extendDays} days"));
                            }
                            $updates[] = 'expires_at = ?';
                            $paramsUpd[] = $newExp;
                            $updates[] = 'is_active = 1';
                        }

                        if ($addDownloads && $addDownloads > 0) {
                            $currentMax = $share['max_downloads'];
                            if ($currentMax === null) {
                                $newMax = $addDownloads;
                            } else {
                                $newMax = $currentMax + $addDownloads;
                            }
                            $updates[] = 'max_downloads = ?';
                            $paramsUpd[] = $newMax;
                            $updates[] = 'is_active = 1';
                        }

                        if ($maxDownloads !== null) {
                            $updates[] = 'max_downloads = ?';
                            $paramsUpd[] = $maxDownloads;
                            $updates[] = 'is_active = 1';
                        }

                        if ($passwordPlain !== '') {
                            $updates[] = 'password = ?';
                            $paramsUpd[] = password_hash($passwordPlain, PASSWORD_DEFAULT);
                            $updates[] = 'is_active = 1';
                        }

                        if (!empty($updates)) {
                            // Remove possible duplicate 'is_active = 1'
                            $updates = array_values(array_unique($updates));
                            $sql = 'UPDATE shares SET ' . implode(', ', $updates) . ' WHERE id = ?';
                            $paramsUpd[] = $sid;
                            $stmtUpd = $db->prepare($sql);
                            if ($stmtUpd->execute($paramsUpd)) {
                                $logger = new Logger();
                                $logger->log($user['id'], 'share_overrides_applied', 'share', $sid, 'Admin applied overrides before resend');
                            }
                            // Refresh $share after update
                            $share = $shareClass->getById($sid);
                        }

                        // Ensure share is active before resend (reactivate if it was inactive)
                        if (empty($share['is_active']) || intval($share['is_active']) === 0) {
                            try {
                                $stmtAct = $db->prepare('UPDATE shares SET is_active = 1 WHERE id = ?');
                                $stmtAct->execute([$sid]);
                                $logger = new Logger();
                                $logger->log($user['id'], 'share_reactivated', 'share', $sid, 'Admin reactivated share before resend');
                                // Refresh $share
                                $share = $shareClass->getById($sid);
                            } catch (Exception $e) {
                                // If reactivation fails, continue to attempt resend but it may remain inactive
                                error_log('Failed to reactivate share before resend: ' . $e->getMessage());
                            }
                        }

                        // If the share had already reached its download limit, reset the download counter
                        // when admin re-sends without explicitly increasing max_downloads. This prevents
                        // recipients from immediately seeing "Download limit reached" on the resent link.
                        try {
                            if (!empty($share['max_downloads']) && isset($share['download_count']) && intval($share['download_count']) >= intval($share['max_downloads'])) {
                                // Only reset if admin did NOT explicitly set a new max_downloads or add_downloads in this request
                                $adminChangedMax = ($maxDownloads !== null) || ($addDownloads !== null);
                                if (!$adminChangedMax) {
                                    $stmtReset = $db->prepare('UPDATE shares SET download_count = 0 WHERE id = ?');
                                    $stmtReset->execute([$sid]);
                                    $logger = new Logger();
                                    $logger->log($user['id'], 'share_downloads_reset', 'share', $sid, 'Admin reset download_count before resend');
                                    // Refresh share
                                    $share = $shareClass->getById($sid);
                                }
                            }
                        } catch (Exception $e) {
                            error_log('Failed to reset download_count before resend: ' . $e->getMessage());
                        }

                        // If admin requested creating a new share (new token), do that instead of resending the same share
                        $createNew = isset($_POST['create_new_share']) && $_POST['create_new_share'] === '1';
                        $newDays = isset($_POST['new_days']) && $_POST['new_days'] !== '' ? intval($_POST['new_days']) : null;
                        if ($createNew) {
                            try {
                                // Build options for new share based on existing share and overrides
                                $opts = [];
                                // recipient email: prefer override if provided
                                $opts['recipient_email'] = $override ?: ($share['recipient_email'] ?? null);
                                // keep recipient message from original share
                                $opts['recipient_message'] = $share['recipient_message'] ?? null;
                                // password override
                                if ($passwordPlain !== '') $opts['password'] = $passwordPlain;
                                // days: for a new share prefer explicit 'new_days' (if provided), otherwise fallback to extend_days
                                if ($newDays !== null && $newDays > 0) {
                                    $opts['max_days'] = $newDays;
                                } elseif ($extendDays && $extendDays > 0) {
                                    $opts['max_days'] = $extendDays;
                                }
                                // max downloads
                                if ($maxDownloads !== null) {
                                    $opts['max_downloads'] = $maxDownloads;
                                } elseif ($addDownloads && $addDownloads > 0) {
                                    $currentMax = $share['max_downloads'];
                                    if ($currentMax === null) $opts['max_downloads'] = $addDownloads; else $opts['max_downloads'] = $currentMax + $addDownloads;
                                }

                                // Create new share as the original owner
                                $newShare = $shareClass->create($share['file_id'], $share['created_by'], $opts);
                                if ($newShare && is_array($newShare) && !empty($newShare['id'])) {
                                    $logger = new Logger();
                                    // Build descriptive details for audit
                                    $descParts = [];
                                    $descParts[] = 'Admin created new share from existing share id ' . $sid;
                                    if (!empty($opts['recipient_email'])) $descParts[] = 'recipient=' . $opts['recipient_email'];
                                    if (isset($opts['max_days'])) $descParts[] = 'max_days=' . intval($opts['max_days']);
                                    if (isset($opts['max_downloads'])) $descParts[] = 'max_downloads=' . intval($opts['max_downloads']);
                                    $descParts[] = 'password=' . (isset($opts['password']) && $opts['password'] !== '' ? 'yes' : 'no');
                                    $logger->log($user['id'], 'share_cloned', 'share', $newShare['id'], implode('; ', $descParts));
                                    $success++;
                                } else {
                                    $errors++;
                                }
                            } catch (Exception $e) {
                                error_log('Error creating new share during resend: ' . $e->getMessage());
                                $errors++;
                            }
                        } else {
                            if ($shareClass->resendNotification($sid, $override)) {
                                $logger = new Logger();
                                $logger->log($user['id'], 'share_notification_resent', 'share', $sid, 'Admin resent share notification');
                                $success++;
                            } else {
                                $errors++;
                            }
                        }
                    break;
                default:
                    $errors++;
                    break;
            }
        }
        $db->commit();
        $msg = "Acción completada: $success exitosos";
        if ($errors > 0) $msg .= ", $errors errores";
        header('Location: ' . BASE_URL . '/admin/shares.php?success=' . urlencode($msg));
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        header('Location: ' . BASE_URL . '/admin/shares.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Pagination + sorting: per-page option (from GET or config)
require_once __DIR__ . '/../../classes/Config.php';
$config = new Config();
$defaultPerPage = (int)$config->get('items_per_page', 25);
$perPage = isset($_GET['per_page']) ? max(10, min(200, intval($_GET['per_page']))) : $defaultPerPage;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Optionally support search/filter in future via $filters
// Collect filters from GET (search box, active flag, etc.)
$filters = [];
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $filters['search'] = $search;
}

// Sorting
$sortBy = $_GET['sort'] ?? 'created_at';
$sortOrder = $_GET['order'] ?? 'DESC';

$totalShares = $shareClass->getCount(null, $filters);
$shares = $shareClass->getAll($filters, $perPage, $offset, $sortBy, $sortOrder);

// Calculate pagination
$totalPages = $totalShares > 0 ? (int)ceil($totalShares / $perPage) : 1;

renderPageStart('Comparticiones', 'shares', true);
renderHeader('Comparticiones del Sistema', $user);
?>
<div class="content">
    <style>
        /* Shares header controls: responsive two-column layout */
        .shares-header-controls { display:flex; gap:0.5rem; align-items:center; }
        .shares-header-left { flex: 0 0 auto; display:flex; gap:0.5rem; align-items:center; }
        .shares-header-right { flex: 1 1 auto; display:flex; justify-content:flex-end; align-items:center; }
        @media (max-width: 720px) {
            .shares-header-controls { flex-direction:column; align-items:stretch; gap:0.75rem; }
            .shares-header-right { justify-content:flex-start; }
            .shares-header-left { justify-content:flex-start; }
            .shares-header-right input[type="text"] { width: 100% !important; }
        }
    </style>
    <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
        <div class="card-header" style="padding: 1.5rem;">
            <h2 class="card-title" style="font-weight: 700; font-size: 1.5rem;"><i class="fas fa-link"></i> Todas las Comparticiones</h2>
        </div>
        <div class="card-body">
            <?php if (empty($shares)): ?>
                <div style="text-align: center; padding: 4rem; background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%); border-radius: 1rem; border: 2px dashed var(--border-color);">
                    <div style="font-size: 5rem; margin-bottom: 1.5rem; opacity: 0.3;"><i class="fas fa-link"></i></div>
                    <h3 style="font-size: 1.5rem; font-weight: 700; color: var(--text-muted);">No hay comparticiones en el sistema</h3>
                </div>
            <?php else: ?>
                <form method="POST" id="adminSharesForm">
                    <input type="hidden" name="action" id="adminSharesAction" value="">
                    <input type="hidden" name="override_recipient" id="override_recipient" value="">
                    <div class="shares-header-controls" style="margin-bottom:0.75rem;">
                        <div class="shares-header-left">
                            <label style="margin:0; font-weight:600;">Filas por página:</label>
                            <div id="perPageForm" style="margin:0; display:flex; gap:0.5rem; align-items:center;">
                                <select name="per_page" onchange="changePerPage(this.value)" class="form-control" style="width:120px;">
                                <?php foreach ([10,25,50,100,200] as $pp): ?>
                                    <option value="<?php echo $pp; ?>" <?php echo $perPage === $pp ? 'selected' : ''; ?>><?php echo $pp; ?></option>
                                <?php endforeach; ?>
                            </select>
                            </div>
                        </div>
                        <div class="shares-header-right">
                            <input id="adminSearchQ" type="text" value="<?php echo htmlspecialchars($search); ?>" placeholder="Buscar comparticiones..." class="form-control" style="width:260px; max-width:40vw;" onkeydown="if(event.key==='Enter'){ performAdminSearch(); return false; }" />
                            <button type="button" class="btn btn-outline btn-outline--on-dark" onclick="performAdminSearch()">Buscar</button>
                        </div>
                    </div>
                    
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:40px;"><input type="checkbox" id="selectAllShares" class="file-checkbox"></th>
                                <?php
                                // Helper to build sort links preserving per_page
                                                                function sort_link($key, $label, $currentSort, $currentOrder, $perPage, $searchTerm = '') {
                                    $nextOrder = 'ASC';
                                    if ($currentSort === $key && strtoupper($currentOrder) === 'ASC') $nextOrder = 'DESC';
                                    $arrow = '';
                                    if ($currentSort === $key) {
                                        $arrow = strtoupper($currentOrder) === 'ASC' ? ' ▲' : ' ▼';
                                    }
                                                                    $params = ['sort' => $key, 'order' => $nextOrder, 'per_page' => $perPage, 'page' => 1];
                                                                    if ($searchTerm !== '') $params['q'] = $searchTerm;
                                                                    $qs = http_build_query($params);
                                    return '<th><a href="?' . $qs . '">' . htmlspecialchars($label) . htmlspecialchars($arrow) . '</a></th>';
                                }
                                                                echo sort_link('original_name', 'Archivo', $sortBy, $sortOrder, $perPage, $search);
                                                                echo sort_link('owner_username', 'Usuario', $sortBy, $sortOrder, $perPage, $search);
                                                                echo sort_link('is_active', 'Estado', $sortBy, $sortOrder, $perPage, $search);
                                                                echo sort_link('download_count', 'Descargas', $sortBy, $sortOrder, $perPage, $search);
                                                                echo sort_link('created_at', 'Creado', $sortBy, $sortOrder, $perPage, $search);
                                echo '<th>Acciones</th>'; 
                                ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shares as $share): ?>
                            <?php
                                // Determine recipient email: prefer stored recipient_email, fallback to last activity_log entry
                                $recipient = '';
                                if (!empty($share['recipient_email'])) {
                                    $recipient = $share['recipient_email'];
                                } else {
                                    try {
                                        $stmtR = $db->prepare("SELECT description FROM activity_log WHERE action = 'share_notification_sent' AND entity_type = 'share' AND entity_id = ? ORDER BY created_at DESC LIMIT 1");
                                        $stmtR->execute([$share['id']]);
                                        $rowR = $stmtR->fetch();
                                        if ($rowR && !empty($rowR['description'])) {
                                            // Expect description like: "Share notification sent to user@example.com"
                                            if (preg_match('/to\s+([\w.%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/i', $rowR['description'], $m)) {
                                                $recipient = $m[1];
                                            }
                                        }
                                    } catch (Exception $e) {
                                        // ignore and leave recipient empty
                                    }
                                }
                            ?>
                            <tr>
                                <td><input id="share_checkbox_<?php echo $share['id']; ?>" data-recipient="<?php echo htmlspecialchars($recipient); ?>" data-max-downloads="<?php echo htmlspecialchars($share['max_downloads'] ?? ''); ?>" data-expires-at="<?php echo htmlspecialchars($share['expires_at'] ?? ''); ?>" type="checkbox" name="share_ids[]" value="<?php echo $share['id']; ?>" class="file-checkbox share-item"></td>
                                <td><?php echo htmlspecialchars($share['original_name']); ?></td>
                                <td><?php echo htmlspecialchars($share['owner_username']); ?></td>
                                <td><span class="badge badge-<?php echo $share['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $share['is_active'] ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td><?php echo $share['download_count']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($share['created_at'])); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" onclick="openResendModalForShare(<?php echo $share['id']; ?>)"><i class="fas fa-envelope"></i> Reenviar</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>

                <div class="bulk-actions-bar" id="adminSharesBulkBar">
                    <span id="adminSharesCount">0</span> comparticiones seleccionadas
                    <button type="button" class="btn btn-warning" onclick="adminSharesDoAction('unshare')"><i class="fas fa-ban"></i> Desactivar seleccionadas</button>
                    <button type="button" class="btn btn-danger" onclick="adminSharesDoAction('delete')"><i class="fas fa-trash"></i> Eliminar seleccionadas</button>
                    <button type="button" class="btn btn-outline btn-outline--on-dark" onclick="adminSharesClearSelection()">Cancelar</button>
                    <button type="button" class="btn btn-info" onclick="adminSharesDoAction('resend_notification')"><i class="fas fa-envelope"></i> Reenviar notificación</button>
                </div>
                
                <!-- Resend override modal -->
                <div id="resendModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center;">
                    <div style="background:white; width:420px; max-width:calc(100% - 40px); margin:auto; padding:1.25rem; border-radius:0.5rem; box-shadow:0 8px 30px rgba(0,0,0,0.2);">
                        <h3 style="margin-top:0;">Reenviar notificación</h3>
                        <p>Opcional: introduce un correo destino para reenviar el enlace. Si lo dejas vacío, se usará el email almacenado en cada compartición.</p>
                        <div style="margin-bottom:0.75rem;">
                            <label style="display:block; font-weight:600; margin-bottom:0.25rem;">Correo destino (opcional)</label>
                            <input id="resendOverrideEmail" name="resend_override_email_temp" type="email" class="form-control" placeholder="destinatario@ejemplo.com" style="width:100%;" />
                        </div>
                        <div style="display:flex; gap:0.75rem; margin-bottom:0.75rem;">
                            <div style="flex:1; min-width:140px;">
                                <label style="display:block; font-weight:600; margin-bottom:0.25rem;">Extender expiración (días)</label>
                                <input id="resendExtendDays" name="extend_days" type="number" min="0" class="form-control" placeholder="p.ej. 7" style="width:100%;" />
                            </div>
                            <div style="flex:0 0 160px;">
                                <label style="display:block; font-weight:600; margin-bottom:0.25rem;">Añadir descargas</label>
                                <input id="resendAddDownloads" name="add_downloads" type="number" min="0" class="form-control" placeholder="p.ej. 10" style="width:100%;" />
                            </div>
                        </div>
                        <div style="display:flex; gap:0.75rem; margin-bottom:0.75rem;">
                            <div style="flex:1; min-width:140px;">
                                <label style="display:block; font-weight:600; margin-bottom:0.25rem;">Duración nuevo enlace (días)</label>
                                <input id="resendNewDays" name="new_days" type="number" min="0" class="form-control" placeholder="p.ej. 7" style="width:100%;" />
                                <small style="color:var(--text-muted);">Sólo se aplica si marcas "Crear nuevo enlace".</small>
                            </div>
                        </div>
                        <div style="display:flex; gap:0.75rem; margin-bottom:0.75rem; align-items:flex-end;">
                            <div style="flex:1; min-width:180px;">
                                <label style="display:block; font-weight:600; margin-bottom:0.25rem;">Establecer máximo de descargas (opcional)</label>
                                <input id="resendSetMaxDownloads" name="max_downloads" type="number" min="0" class="form-control" placeholder="Dejar vacío para no cambiar" style="width:100%;" />
                            </div>
                            <div style="flex:1; min-width:180px;">
                                <label style="display:block; font-weight:600; margin-bottom:0.25rem;">Contraseña (opcional)</label>
                                <input id="resendSetPassword" name="password" type="text" class="form-control" placeholder="Contraseña para el enlace" style="width:100%;" />
                            </div>
                        </div>
                        <div style="display:flex; gap:0.5rem; justify-content:flex-end;">
                            <button type="button" class="btn btn-outline" onclick="closeResendModal()">Cancelar</button>
                            <label style="display:flex; align-items:center; gap:0.5rem; margin-right:auto; font-weight:600;">
                                <input id="resendCreateNew" type="checkbox" style="width:16px; height:16px;"> Crear nuevo enlace (generar token nuevo)
                            </label>
                            <button type="button" class="btn btn-primary" onclick="submitResendModal()">Enviar</button>
                        </div>
                    </div>
                </div>
                <?php if ($totalPages > 1): ?>
                <div class="pagination" style="margin-top:1rem;">
                    <?php
                        // Build base params to preserve search, sort and order
                        $baseParams = ['per_page' => $perPage, 'sort' => $sortBy, 'order' => $sortOrder];
                        if ($search !== '') $baseParams['q'] = $search;
                    ?>
                    <?php if ($page > 1): ?>
                        <?php $pPrev = http_build_query(array_merge($baseParams, ['page' => $page - 1])); ?>
                        <a href="?<?php echo $pPrev; ?>">« Anterior</a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <?php $pLink = http_build_query(array_merge($baseParams, ['page' => $i])); ?>
                        <a href="?<?php echo $pLink; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <?php $pNext = http_build_query(array_merge($baseParams, ['page' => $page + 1])); ?>
                        <a href="?<?php echo $pNext; ?>">Siguiente »</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Visual confirmation modal for creating new share -->
                <div id="resendConfirmModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2100; align-items:center; justify-content:center;">
                    <div style="background:white; width:520px; max-width:calc(100% - 40px); margin:auto; padding:1rem 1.25rem; border-radius:0.5rem; box-shadow:0 10px 40px rgba(0,0,0,0.25);">
                        <h3 style="margin-top:0;">Confirmar nuevo enlace</h3>
                        <p style="margin:0 0 0.75rem 0; color:var(--text-muted);">Revise los parámetros del nuevo enlace antes de crear y enviar el correo.</p>
                        <div id="resendConfirmBody" style="margin-bottom:1rem; max-height:240px; overflow:auto; padding:0.5rem; border-radius:0.375rem; background:#fbfbfb; border:1px solid var(--border-color);"></div>
                        <div style="display:flex; gap:0.5rem; justify-content:flex-end;">
                            <button type="button" class="btn btn-outline" onclick="closeResendConfirm()">Cancelar</button>
                            <button type="button" class="btn btn-primary" onclick="confirmResendAndSubmit()">Confirmar y enviar</button>
                        </div>
                    </div>
                </div>
                <script>
                function changePerPage(v) {
                    const params = new URLSearchParams(window.location.search);
                    params.set('per_page', v);
                    params.set('page', 1);
                    // preserve search (q), sort and order if present
                    window.location.search = params.toString();
                }
                document.addEventListener('DOMContentLoaded', function() {
                    const selectAll = document.getElementById('selectAllShares');
                    const items = Array.from(document.querySelectorAll('.share-item'));
                    const bulkBar = document.getElementById('adminSharesBulkBar');
                    const countEl = document.getElementById('adminSharesCount');

                    function updateBar() {
                        const checked = document.querySelectorAll('.share-item:checked').length;
                        countEl.textContent = checked;
                        if (checked > 0) bulkBar.classList.add('show'); else bulkBar.classList.remove('show');
                    }

                    if (selectAll) {
                        selectAll.addEventListener('change', function() { items.forEach(i => i.checked = this.checked); updateBar(); });
                    }
                    items.forEach(i => i.addEventListener('change', updateBar));
                });

                function openResendModal() {
                    const modal = document.getElementById('resendModal');
                    if (!modal) return;
                    document.getElementById('resendOverrideEmail').value = '';
                    // sensible default: extend 7 days
                    document.getElementById('resendExtendDays').value = '7';
                    document.getElementById('resendAddDownloads').value = '';
                    // leave max_downloads empty by default to avoid accidental overwrite
                    document.getElementById('resendSetMaxDownloads').value = '';
                    document.getElementById('resendSetPassword').value = '';
                    modal.style.display = 'flex';
                }

                function openResendModalForShare(shareId) {
                    // Uncheck all and check only the provided share
                    document.querySelectorAll('.share-item').forEach(i => i.checked = false);
                    const checkbox = document.getElementById('share_checkbox_' + shareId) || document.querySelector('.share-item[value="' + shareId + '"]');
                    if (checkbox) checkbox.checked = true;
                    // Update count and bulk bar
                    const checked = document.querySelectorAll('.share-item:checked').length;
                    document.getElementById('adminSharesCount').textContent = checked;
                    if (checked > 0) document.getElementById('adminSharesBulkBar').classList.add('show');
                    // Prefill override email if we have it on the checkbox
                    let pre = '';
                    try { pre = checkbox ? (checkbox.dataset ? checkbox.dataset.recipient || '' : checkbox.getAttribute('data-recipient') || '') : ''; } catch (e) { pre = ''; }
                    document.getElementById('resendOverrideEmail').value = pre;
                    // Prefill extend days/add downloads/max_downloads/password where possible
                    try {
                        const maxDownloads = checkbox ? (checkbox.dataset ? checkbox.dataset.maxDownloads || '' : checkbox.getAttribute('data-max-downloads') || '') : '';
                        const expiresAt = checkbox ? (checkbox.dataset ? checkbox.dataset.expiresAt || '' : checkbox.getAttribute('data-expires-at') || '') : '';
                        // Default: clear fields
                        document.getElementById('resendExtendDays').value = '';
                        document.getElementById('resendAddDownloads').value = '';
                        document.getElementById('resendSetMaxDownloads').value = maxDownloads || '';
                        document.getElementById('resendSetPassword').value = '';
                        // If expiresAt exists, compute days remaining and set as extendDays=0 (leave empty), but show nothing by default.
                    } catch (e) {
                        // ignore
                    }
                    // Open modal
                    openResendModal();
                }

                function closeResendModal() {
                    const modal = document.getElementById('resendModal');
                    if (!modal) return;
                    modal.style.display = 'none';
                }

                function submitResendModal() {
                    const email = document.getElementById('resendOverrideEmail').value.trim();
                    const extendDays = document.getElementById('resendExtendDays').value.trim();
                    const addDownloads = document.getElementById('resendAddDownloads').value.trim();
                    const maxDownloads = document.getElementById('resendSetMaxDownloads').value.trim();
                    const password = document.getElementById('resendSetPassword').value;

                    // Simple client-side validation
                    function isNonNegInt(v) {
                        if (v === '') return true;
                        return /^\d+$/.test(v) && parseInt(v,10) >= 0;
                    }
                    if (!isNonNegInt(extendDays) || !isNonNegInt(addDownloads) || !isNonNegInt(maxDownloads)) {
                        alert('Extender días, añadir descargas y máximo de descargas deben ser números enteros no negativos.');
                        return;
                    }

                    document.getElementById('override_recipient').value = email;
                    // Create hidden inputs to pass through the form
                    function setOrReplaceHidden(name, value) {
                        let el = document.querySelector('input[name="' + name + '"]');
                        if (!el) {
                            el = document.createElement('input');
                            el.type = 'hidden';
                            el.name = name;
                            document.getElementById('adminSharesForm').appendChild(el);
                        }
                        el.value = value;
                    }

                    if (extendDays !== '') setOrReplaceHidden('extend_days', extendDays);
                    if (addDownloads !== '') setOrReplaceHidden('add_downloads', addDownloads);
                    if (maxDownloads !== '') setOrReplaceHidden('max_downloads', maxDownloads);
                    if (password !== '') setOrReplaceHidden('password', password);
                    // create_new_share flag
                    var createNew = document.getElementById('resendCreateNew') && document.getElementById('resendCreateNew').checked;
                    if (createNew) {
                        setOrReplaceHidden('create_new_share', '1');
                        var newDaysVal = document.getElementById('resendNewDays') ? document.getElementById('resendNewDays').value.trim() : '';
                        if (newDaysVal !== '') setOrReplaceHidden('new_days', newDaysVal);
                    }

                    // If creating a new share, show a visual confirmation modal with summary
                    var createNew = document.getElementById('resendCreateNew') && document.getElementById('resendCreateNew').checked;
                    if (createNew) {
                        var em = email || '(usar email guardado por compartición)';
                        var nd = document.getElementById('resendNewDays') ? document.getElementById('resendNewDays').value.trim() : '';
                        var md = document.getElementById('resendSetMaxDownloads').value.trim();
                        var ad = document.getElementById('resendAddDownloads').value.trim();
                        var pw = document.getElementById('resendSetPassword').value;
                        var lines = [];
                        lines.push('<strong>Destinatario:</strong> ' + htmlspecialcharsClient(em));
                        if (nd !== '') lines.push('<strong>Duración nuevo enlace:</strong> ' + htmlspecialcharsClient(nd) + ' días');
                        if (md !== '') lines.push('<strong>Máximo descargas:</strong> ' + htmlspecialcharsClient(md));
                        if (ad !== '') lines.push('<strong>Añadir descargas:</strong> ' + htmlspecialcharsClient(ad));
                        lines.push('<strong>Contraseña:</strong> ' + (pw !== '' ? '(establecida)' : '(ninguna)'));
                        var modal = document.getElementById('resendConfirmModal');
                        if (modal) {
                            document.getElementById('resendConfirmBody').innerHTML = '<div style="display:flex;gap:10px;flex-direction:column;">' + lines.map(function(l){return '<div>' + l + '</div>';}).join('') + '</div>';
                            modal.style.display = 'flex';
                            return; // wait for the visual confirm action
                        }
                    }

                    document.getElementById('adminSharesAction').value = 'resend_notification';
                    document.getElementById('adminSharesForm').submit();
                }

                function adminSharesDoAction(action) {
                    const checked = document.querySelectorAll('.share-item:checked').length;
                    if (checked === 0) { alert('Selecciona al menos una compartición'); return; }
                    if (action === 'resend_notification') {
                        // Open modal to allow optional override email
                        openResendModal();
                        return;
                    }
                    if (!confirm('¿Confirmar acción en las comparticiones seleccionadas?')) return;
                    document.getElementById('adminSharesAction').value = action;
                    document.getElementById('adminSharesForm').submit();
                }

                function adminSharesClearSelection() {
                    document.querySelectorAll('.share-item').forEach(i => i.checked = false);
                    document.getElementById('selectAllShares').checked = false;
                    document.getElementById('adminSharesBulkBar').classList.remove('show');
                }
                
                // Perform a GET search without nesting forms: preserve existing query params, set q and page=1
                function performAdminSearch() {
                    const q = (document.getElementById('adminSearchQ') || {value:''}).value.trim();
                    const params = new URLSearchParams(window.location.search);
                    if (q !== '') params.set('q', q); else params.delete('q');
                    params.set('page', '1');
                    // Leave per_page, sort, order as-is if present in current URL
                    window.location.search = params.toString();
                }
                
                // Helper to escape simple text for insertion into modal (client-side)
                function htmlspecialcharsClient(s) {
                    if (!s) return '';
                    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;');
                }

                // Confirmation modal actions
                function closeResendConfirm() {
                    const modal = document.getElementById('resendConfirmModal');
                    if (!modal) return; modal.style.display = 'none';
                }

                function confirmResendAndSubmit() {
                    // Submit the form after confirming
                    document.getElementById('adminSharesAction').value = 'resend_notification';
                    document.getElementById('adminSharesForm').submit();
                }
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php renderPageEnd(); ?>
