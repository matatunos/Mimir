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
$filters = [];

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
    <div class="card" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
        <div class="card-header" style="background: linear-gradient(135deg, #9b59b6, #e74c3c); color: white; padding: 1.5rem;">
            <h2 class="card-title" style="color: white; font-weight: 700; font-size: 1.5rem;"><i class="fas fa-link"></i> Todas las Comparticiones</h2>
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
                    <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.75rem;">
                        <label style="margin:0; font-weight:600;">Filas por página:</label>
                        <div id="perPageForm" style="margin:0; display:flex; gap:0.5rem; align-items:center;">
                            <select name="per_page" onchange="changePerPage(this.value)" class="form-control" style="width:120px;">
                                <?php foreach ([10,25,50,100,200] as $pp): ?>
                                    <option value="<?php echo $pp; ?>" <?php echo $perPage === $pp ? 'selected' : ''; ?>><?php echo $pp; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:40px;"><input type="checkbox" id="selectAllShares" class="file-checkbox"></th>
                                <?php
                                // Helper to build sort links preserving per_page
                                function sort_link($key, $label, $currentSort, $currentOrder, $perPage) {
                                    $nextOrder = 'ASC';
                                    if ($currentSort === $key && strtoupper($currentOrder) === 'ASC') $nextOrder = 'DESC';
                                    $arrow = '';
                                    if ($currentSort === $key) {
                                        $arrow = strtoupper($currentOrder) === 'ASC' ? ' ▲' : ' ▼';
                                    }
                                    $qs = http_build_query(['sort' => $key, 'order' => $nextOrder, 'per_page' => $perPage, 'page' => 1]);
                                    return '<th><a href="?' . $qs . '">' . htmlspecialchars($label) . htmlspecialchars($arrow) . '</a></th>';
                                }
                                echo sort_link('original_name', 'Archivo', $sortBy, $sortOrder, $perPage);
                                echo sort_link('owner_username', 'Usuario', $sortBy, $sortOrder, $perPage);
                                echo sort_link('is_active', 'Estado', $sortBy, $sortOrder, $perPage);
                                echo sort_link('download_count', 'Descargas', $sortBy, $sortOrder, $perPage);
                                echo sort_link('created_at', 'Creado', $sortBy, $sortOrder, $perPage);
                                ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shares as $share): ?>
                            <tr>
                                <td><input type="checkbox" name="share_ids[]" value="<?php echo $share['id']; ?>" class="file-checkbox share-item"></td>
                                <td><?php echo htmlspecialchars($share['original_name']); ?></td>
                                <td><?php echo htmlspecialchars($share['owner_username']); ?></td>
                                <td><span class="badge badge-<?php echo $share['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $share['is_active'] ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td><?php echo $share['download_count']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($share['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>

                <div class="bulk-actions-bar" id="adminSharesBulkBar">
                    <span id="adminSharesCount">0</span> comparticiones seleccionadas
                    <button type="button" class="btn btn-warning" onclick="adminSharesDoAction('unshare')"><i class="fas fa-ban"></i> Desactivar seleccionadas</button>
                    <button type="button" class="btn btn-danger" onclick="adminSharesDoAction('delete')"><i class="fas fa-trash"></i> Eliminar seleccionadas</button>
                    <button type="button" class="btn btn-outline" onclick="adminSharesClearSelection()">Cancelar</button>
                </div>
                <?php if ($totalPages > 1): ?>
                <div class="pagination" style="margin-top:1rem;">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&per_page=<?php echo $perPage; ?>">« Anterior</a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&per_page=<?php echo $perPage; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&per_page=<?php echo $perPage; ?>">Siguiente »</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <script>
                function changePerPage(v) {
                    const params = new URLSearchParams(window.location.search);
                    params.set('per_page', v);
                    params.set('page', 1);
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

                function adminSharesDoAction(action) {
                    if (!confirm('¿Confirmar acción en las comparticiones seleccionadas?')) return;
                    document.getElementById('adminSharesAction').value = action;
                    document.getElementById('adminSharesForm').submit();
                }

                function adminSharesClearSelection() {
                    document.querySelectorAll('.share-item').forEach(i => i.checked = false);
                    document.getElementById('selectAllShares').checked = false;
                    document.getElementById('adminSharesBulkBar').classList.remove('show');
                }
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php renderPageEnd(); ?>
