<!-- Upload overlay -->
<div id="uploadOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:2000; align-items:center; justify-content:center;">
    <div style="background:var(--bg-main); padding:1rem; border-radius:0.75rem; display:flex; gap:1rem; align-items:flex-start; box-shadow:0 8px 32px rgba(0,0,0,0.4); width:560px; max-width:calc(100% - 40px);">
    <div class="spinner-border" role="status" style="width:3rem; height:3rem; border-width:0.35rem; flex:0 0 auto;"></div>
    <div style="flex:1 1 auto; min-width:0;">
            <div id="uploadOverlayText" style="font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Subiendo archivos...</div>
            <div style="font-size:0.9rem; color:var(--text-muted);">No cierres esta ventana hasta que termine la subida.</div>
            <div id="uploadProgressList" style="margin-top:0.75rem; max-height:260px; overflow:auto; width:100%; box-sizing:border-box;">
                <!-- per-file progress items will be injected here -->
            </div>
    </div>
    </div>
    </div>
    
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
$uploadResults = [];
$currentFolderId = isset($_GET['folder']) && $_GET['folder'] !== '' ? (int)$_GET['folder'] : null;

// Helper to append per-file upload results to session without overwriting previous entries
function appendUploadResultsToSession($results) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['upload_results']) || !is_array($_SESSION['upload_results'])) {
        $_SESSION['upload_results'] = $results;
    } else {
        $_SESSION['upload_results'] = array_merge($_SESSION['upload_results'], $results);
    }
    // Ensure session changes are flushed so a subsequent redirect/read sees them
    if (function_exists('session_write_close')) {
        session_write_close();
    }
}

// Get folder path for breadcrumbs
$breadcrumbs = [];
if ($currentFolderId) {
    $breadcrumbs = $fileClass->getFolderPath($currentFolderId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = false;
    if (!empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        $isAjax = true;
    }
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
    } elseif (!isset($_FILES['files']) || empty($_FILES['files']['tmp_name'][0])) {
        $error = 'Selecciona al menos un archivo';
    } else {
        $uploadedCount = 0;
        $errors = [];
        $results = []; // per-file results to report back to user
        $description = $_POST['description'] ?? '';
        $parentFolderId = isset($_POST['parent_folder_id']) && $_POST['parent_folder_id'] !== '' 
            ? (int)$_POST['parent_folder_id'] 
            : null;
        // Use the 'name' array length so we account for every selected file even if tmp_name is empty
        $fileCount = count($_FILES['files']['name']);
        $clientFileCount = isset($_POST['client_file_count']) ? (int)$_POST['client_file_count'] : $fileCount;

        for ($i = 0; $i < $fileCount; $i++) {
            $origName = $_FILES['files']['name'][$i] ?? ('file_' . $i);
            $errCode = $_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;

            // If there's any upload error, translate it to a human-friendly reason and record it
            if ($errCode !== UPLOAD_ERR_OK) {
                switch ($errCode) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $reason = 'El archivo excede el límite permitido por el servidor';
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $reason = 'Subida incompleta (parcial)';
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $reason = 'No se recibió el archivo';
                        break;
                    case UPLOAD_ERR_NO_TMP_DIR:
                        $reason = 'Falta el directorio temporal en el servidor';
                        break;
                    case UPLOAD_ERR_CANT_WRITE:
                        $reason = 'Error al escribir el archivo en disco';
                        break;
                    case UPLOAD_ERR_EXTENSION:
                        $reason = 'Subida detenida por una extensión';
                        break;
                    default:
                        $reason = 'Error al subir';
                }
                $results[] = ['name' => $origName, 'status' => 'error', 'reason' => $reason];
                if ($errCode !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = $origName . ': ' . $reason;
                }
                continue;
            }

            // Some cases may have no error code but missing tmp_name — handle defensively
            if (empty($_FILES['files']['tmp_name'][$i]) || !is_uploaded_file($_FILES['files']['tmp_name'][$i])) {
                $results[] = ['name' => $origName, 'status' => 'error', 'reason' => 'Archivo temporal no disponible'];
                $errors[] = $origName . ': Archivo temporal no disponible';
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
                $allowDup = isset($_POST['allow_duplicates']) && $_POST['allow_duplicates'] === '1';
                $result = $fileClass->upload($fileData, $user['id'], $description, $parentFolderId, $allowDup);
                if ($result) {
                    $uploadedCount++;
                    $logger->log($user['id'], 'file_upload', 'file', $result, "Archivo subido: {$fileData['name']}");
                    $results[] = ['name' => $fileData['name'], 'status' => 'ok'];
                } else {
                    $results[] = ['name' => $fileData['name'], 'status' => 'error', 'reason' => 'No se pudo procesar'];
                }
            } catch (Exception $e) {
                $results[] = ['name' => $fileData['name'], 'status' => 'error', 'reason' => $e->getMessage()];
            }
        }
        
        if ($uploadedCount > 0) {
            $success = $uploadedCount === 1 ? 'Archivo subido correctamente' : "$uploadedCount archivos subidos correctamente";
            if (!empty($errors)) {
                $success .= ' (algunos fallaron)';
            }
            // Store detailed per-file results in session for display on files page (append when AJAX per-file uploads)
            appendUploadResultsToSession($results);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $success, 'results' => $results]);
                exit;
            } else {
                $redirect = BASE_URL . '/user/files.php?success=' . urlencode($success);
                if ($parentFolderId) {
                    $redirect .= '&folder=' . $parentFolderId;
                }
                header('Location: ' . $redirect);
                exit;
            }
        } else {
            // store results even when no files uploaded so user can see reasons
            // store results even when no files uploaded so user can see reasons
            appendUploadResultsToSession($results);
            // also expose the detailed results inline on this page so users see reasons immediately
            $uploadResults = $results;
            // If client reported more files than server received, it's likely PHP's max_file_uploads or server limits
            if (isset($clientFileCount) && $clientFileCount > $fileCount) {
                // For AJAX per-file uploads we should NOT create placeholder rows on every request
                // because the client uploads files sequentially and 'client_file_count' reflects
                // the overall intent. Creating placeholders per AJAX call causes the session
                // to grow massively. Only add placeholders/hints for non-AJAX full-form submissions
                // where the server truly didn't receive the batch.
                if ($isAjax) {
                    $error = 'No se pudieron procesar todos los archivos en esta petición AJAX. Continua con la subida.' . (empty($errors) ? '' : ' ' . implode(' ', $errors));
                    // Append just the current results (do not fabricate placeholders)
                    appendUploadResultsToSession($results);
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $error, 'results' => $results]);
                    exit;
                }

                $missing = $clientFileCount - $fileCount;
                $phpMax = ini_get('max_file_uploads') ?: 'unknown';
                $hint = "Se detectaron {$clientFileCount} archivos en el cliente, pero el servidor procesó {$fileCount}. " .
                    "Esto suele indicar un límite del servidor (p.ej. PHP 'max_file_uploads' = {$phpMax}) o restricciones de Nginx/Proxy. \n" .
                    "Sube los archivos en lotes más pequeños o aumenta 'max_file_uploads' y 'post_max_size' en el servidor.";
                $error = 'No se pudieron subir todos los archivos. ' . (empty($errors) ? '' : implode(' ', $errors)) . ' ' . $hint;
                // Add placeholder rows for missing files to make the table length match client expectation
                for ($m = 1; $m <= $missing; $m++) {
                    $results[] = ['name' => 'Archivo faltante #' . $m, 'status' => 'error', 'reason' => 'No se recibió en el servidor (posible límite de max_file_uploads)'];
                }
                // update session and inline results to include placeholders (append)
                appendUploadResultsToSession($results);
                $uploadResults = $results;
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $error, 'results' => $results]);
                    exit;
                }
            } else {
                $error = 'No se pudo subir ningún archivo.' . (empty($errors) ? '' : ' ' . implode(', ', $errors));
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $error, 'results' => $results]);
                    exit;
                }
            }
        }
    }
}

$stats = $userClass->getStatistics($user['id']);
$storageUsedGB = ($stats['total_size'] ?? 0) / 1024 / 1024 / 1024;
$storageQuotaGB = $user['storage_quota'] / 1024 / 1024 / 1024;
$storagePercent = $storageQuotaGB > 0 ? min(100, ($storageUsedGB / $storageQuotaGB) * 100) : 0;
$maxSize = MAX_FILE_SIZE / 1024 / 1024;
// Expose allowed extensions and max bytes for client-side validation
require_once __DIR__ . '/../../classes/Config.php';
$config = new Config();
$allowedExtensionsStr = $config->get('allowed_extensions', ALLOWED_EXTENSIONS);
$allowedExtsArr = array_values(array_filter(array_map('trim', explode(',', $allowedExtensionsStr))));
$maxFileBytes = MAX_FILE_SIZE;

renderPageStart('Subir Archivos', 'upload', $user['role'] === 'admin');
renderHeader('Subir Archivos', $user);
?>

<div class="content">
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (!empty($uploadResults)): ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header"><strong>Resultado de la subida</strong></div>
            <div class="card-body">
                <div style="margin-bottom:0.5rem;">
                    <strong><?php echo count(array_filter($uploadResults, function($r){ return $r['status']==='ok'; })); ?></strong> archivos subidos correctamente,
                    <strong><?php echo count(array_filter($uploadResults, function($r){ return $r['status']!=='ok'; })); ?></strong> fallaron.
                </div>
                <div style="max-height:220px; overflow:auto;">
                    <div style="display:flex; justify-content:space-between; gap:0.5rem; margin-bottom:0.5rem; align-items:center;">
                        <div style="display:flex; gap:0.5rem; align-items:center;">
                            <input id="uploadResultsSearch" type="text" placeholder="Buscar archivo..." class="form-control" style="padding:0.4rem 0.6rem; width:220px;">
                            <select id="uploadResultsStatus" class="form-control" style="padding:0.35rem 0.5rem; width:160px;">
                                <option value="all">Todos</option>
                                <option value="ok">OK</option>
                                <option value="error">Error</option>
                            </select>
                        </div>
                        <div style="font-size:0.9rem; color:var(--text-muted);">Haz clic en los encabezados para ordenar</div>
                    </div>
                    <table id="uploadResultsTable" class="table table-sm">
                        <thead>
                            <tr><th>Archivo</th><th>Estado</th><th>Motivo</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($uploadResults as $res): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($res['name']); ?></td>
                                    <td><?php echo $res['status'] === 'ok' ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">Error</span>'; ?></td>
                                    <td><?php echo $res['status'] === 'ok' ? '-' : htmlspecialchars($res['reason']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
    // Expose interactive table helper so it can be called after AJAX-updates
    function makeInteractiveTable(tableId, searchId, statusId){
        var table = document.getElementById(tableId);
        if (!table) return;
        var tbody = table.tBodies[0];
        if (!tbody) return;
        var rows = Array.prototype.slice.call(tbody.rows);

        // Sorting
        var headers = table.tHead && table.tHead.rows[0] ? table.tHead.rows[0].cells : [];
        for (let i=0;i<headers.length;i++){
            headers[i].style.cursor='pointer';
            headers[i].addEventListener('click', function(){
                var asc = this.getAttribute('data-asc') !== '1';
                rows.sort(function(a,b){
                    var A = (a.cells[i].innerText || '').trim().toLowerCase();
                    var B = (b.cells[i].innerText || '').trim().toLowerCase();
                    return A === B ? 0 : (A > B ? 1 : -1);
                });
                if (!asc) rows.reverse();
                rows.forEach(function(r){ tbody.appendChild(r); });
                this.setAttribute('data-asc', asc ? '1' : '0');
            });
        }

        // Filtering
        var search = document.getElementById(searchId);
        var status = document.getElementById(statusId);
        function applyFilter(){
            var q = (search && search.value || '').toLowerCase();
            var s = (status && status.value) || 'all';
            rows.forEach(function(r){
                var name = (r.cells[0].innerText || '').toLowerCase();
                var st = (r.cells[1].innerText || '').toLowerCase().trim();
                var ok = (q === '' || name.indexOf(q) !== -1) && (s === 'all' || st === s);
                r.style.display = ok ? '' : 'none';
            });
        }
        if (search) search.addEventListener('input', applyFilter);
        if (status) status.addEventListener('change', applyFilter);
    }

    document.addEventListener('DOMContentLoaded', function(){
        makeInteractiveTable('uploadResultsTable','uploadResultsSearch','uploadResultsStatus');
    });
    </script>
    <script>
    // Server-provided upload limits
    var ALLOWED_EXTENSIONS = <?php echo json_encode(array_map('strtolower', $allowedExtsArr)); ?>;
    var MAX_FILE_SIZE_BYTES = <?php echo (int)$maxFileBytes; ?>; // bytes
    </script>

    <div class="card">
        <div class="card-header" style="padding: 1.5rem;">
            <h2 class="card-title" style="margin: 0;">Subir Archivo</h2>
        </div>
        <div class="card-body">
            
            <!-- Primary breadcrumb (home icon + path) -->
            <?php if ($currentFolderId): ?>
            <div style="margin-bottom: 1.5rem; padding: 0.9rem 1rem; background: var(--bg-secondary); border-radius: 0.5rem; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fas fa-home" style="color: var(--text-muted); font-size: 1.4rem;"></i>
                <a href="<?php echo BASE_URL; ?>/user/files.php" style="color: var(--text-main); text-decoration: none; font-weight: 700; font-size: 1.06rem;">
                    Inicio
                </a>
                <?php foreach ($breadcrumbs as $folder): ?>
                    <i class="fas fa-chevron-right" style="color: var(--text-muted); font-size: 0.9rem;"></i>
                    <a href="<?php echo BASE_URL; ?>/user/files.php?folder=<?php echo $folder['id']; ?>" style="color: var(--text-main); text-decoration: none; font-weight: 600; font-size: 1.02rem;">
                        <?php echo htmlspecialchars($folder['name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
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
                <input type="hidden" name="parent_folder_id" value="<?php echo $currentFolderId ?? ''; ?>">
                <input type="hidden" name="client_file_count" id="client_file_count" value="0">
                
                <div class="form-group">
                    <label>Archivos *</label>
                    <input type="file" name="files[]" class="form-control" multiple required>
                    <small class="form-text">Mantén presionado Ctrl (o Cmd en Mac) para seleccionar múltiples archivos</small>
                </div>

                <div class="form-group">
                    <label>Descripción (opcional)</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Añade una descripción para estos archivos..."></textarea>
                </div>

                <div style="display: flex; gap: 0.75rem; align-items:center;">
                    <label style="display:flex; align-items:center; gap:0.5rem; margin:0; font-weight:600;">
                        <input type="checkbox" name="allow_duplicates" value="1" style="width:16px; height:16px;"> Permitir guardar duplicados con nombre alternativo
                    </label>
                    <div style="margin-left:auto; display:flex; gap:0.75rem;">
                        <button type="submit" class="btn btn-primary">⬆️ Subir Archivos</button>
                        <a href="<?php echo BASE_URL; ?>/user/files.php<?php echo $currentFolderId ? '?folder=' . $currentFolderId : ''; ?>" class="btn btn-outline btn-outline--on-dark">Cancelar</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upload overlay -->
<div id="uploadOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:2000; align-items:center; justify-content:center;">
    <div style="background:var(--bg-main); padding:1.5rem; border-radius:0.75rem; display:flex; gap:1rem; align-items:center; box-shadow:0 8px 32px rgba(0,0,0,0.4);">
        <div class="spinner-border" role="status" style="width:3rem; height:3rem; border-width:0.35rem;"></div>
        <div>
            <div id="uploadOverlayText" style="font-weight:700;">Subiendo archivos...</div>
            <div style="font-size:0.9rem; color:var(--text-muted);">No cierres esta ventana hasta que termine la subida.</div>
            <div id="uploadProgressList" style="margin-top:0.75rem; max-height:260px; overflow:auto; width:480px;">
                <!-- per-file progress items will be injected here -->
            </div>
        </div>
    </div>
</div>

<script>
// Server-provided upload limits (available to the upload script)
var ALLOWED_EXTENSIONS = <?php echo json_encode(array_map('strtolower', $allowedExtsArr)); ?> || [];
var MAX_FILE_SIZE_BYTES = <?php echo (int)$maxFileBytes; ?> || 0;

document.addEventListener('DOMContentLoaded', function(){
    var form = document.querySelector('form[enctype="multipart/form-data"]');
    if (!form) return;

    // server-side reported max files (PHP ini); used as safe batch size
    var serverMax = <?php echo (int)ini_get('max_file_uploads'); ?> || 20;

    form.addEventListener('submit', function(e){
        // intercept default submit to perform AJAX batch upload
        e.preventDefault();
        var filesInput = form.querySelector('input[type=file][name="files[]"]');
        if (!filesInput || !filesInput.files || filesInput.files.length === 0) {
            form.submit();
            return;
        }
        var files = Array.prototype.slice.call(filesInput.files);
        // Pre-validate files client-side: extension and size
        var validatedFiles = [];
        var invalidResults = [];
        files.forEach(function(f){
            var name = f.name || '';
            var parts = name.split('.');
            var ext = parts.length > 1 ? parts.pop().toLowerCase() : '';
            if (!ext || ALLOWED_EXTENSIONS.length > 0 && ALLOWED_EXTENSIONS.indexOf(ext) === -1) {
                invalidResults.push({ name: name, status: 'error', reason: 'Extensión no permitida' });
                return;
            }
            if (typeof f.size === 'number' && f.size > MAX_FILE_SIZE_BYTES) {
                invalidResults.push({ name: name, status: 'error', reason: 'El archivo excede el tamaño máximo de ' + (MAX_FILE_SIZE_BYTES/1024/1024).toFixed(2) + ' MB' });
                return;
            }
            validatedFiles.push(f);
        });

        // If no valid files to upload, show inline results and abort
        if (validatedFiles.length === 0) {
            if (invalidResults.length) {
                renderResults(invalidResults);
            }
            return;
        }

        // Use the validated list for the rest of the upload flow so indices match
        files = validatedFiles;
        var total = files.length;
        var clientCountField = document.getElementById('client_file_count');
        // report how many will actually be uploaded (exclude invalid files)
        if (clientCountField) clientCountField.value = total;

        var overlay = document.getElementById('uploadOverlay');
        var text = document.getElementById('uploadOverlayText');
        overlay.style.display = 'flex';
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        // prepare per-file progress UI
        var resultsAccum = [];
        var progressList = document.getElementById('uploadProgressList');
        progressList.innerHTML = '';
        var progressItems = [];
        for (var i = 0; i < total; i++) {
            var item = document.createElement('div');
            item.style.padding = '0.4rem 0';
            item.style.borderBottom = '1px solid rgba(0,0,0,0.06)';
            var title = document.createElement('div');
            title.textContent = (i+1) + '. ' + (validatedFiles[i].name || ('file_' + i));
            title.style.fontSize = '0.95rem';
            title.style.marginBottom = '0.35rem';
            var barWrap = document.createElement('div');
            barWrap.style.background = '#e6e6e6';
            barWrap.style.borderRadius = '6px';
            barWrap.style.height = '10px';
            barWrap.style.overflow = 'hidden';
            var bar = document.createElement('div');
            bar.style.width = '0%';
            bar.style.height = '100%';
            bar.style.background = 'linear-gradient(90deg,#4a90e2,#50c878)';
            bar.style.transition = 'width 0.2s ease';
            barWrap.appendChild(bar);
            var status = document.createElement('div');
            status.style.fontSize = '0.85rem';
            status.style.marginTop = '0.35rem';
            status.textContent = 'Pendiente';
            item.appendChild(title);
            item.appendChild(barWrap);
            item.appendChild(status);
            progressList.appendChild(item);
            progressItems.push({ item: item, bar: bar, status: status });
        }

        function uploadNext(index) {
            if (index >= total) {
                    // done
                    overlay.style.display = 'none';
                    if (submitBtn) submitBtn.disabled = false;
                    // Build a user-friendly summary and redirect to the folder listing so the
                    // user lands in the folder they uploaded into and sees the session-backed results.
                    var successCount = resultsAccum.filter(function(r){ return r.status === 'ok'; }).length;
                    var failCount = resultsAccum.length - successCount;
                    var msg = successCount + ' archivos subidos correctamente';
                    if (failCount > 0) msg += ', ' + failCount + ' fallaron';
                    // Parent folder from hidden field
                    var parentFolder = form.querySelector('input[name="parent_folder_id"]').value || '';
                    var base = '<?php echo BASE_URL; ?>';
                    var url = base + '/user/files.php?success=' + encodeURIComponent(msg);
                    if (parentFolder) url += '&folder=' + encodeURIComponent(parentFolder);
                    // Redirect so the user lands in the folder view (which will read and clear session results)
                    window.location.href = url;
                    return;
                }
            var file = files[index];
            text.innerText = 'Subiendo ' + (index+1) + ' de ' + total + ' — ' + file.name;

            var fd = new FormData();
            fd.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
            fd.append('description', form.querySelector('textarea[name="description"]').value || '');
            fd.append('parent_folder_id', form.querySelector('input[name="parent_folder_id"]').value || '');
            fd.append('ajax', '1');
            fd.append('client_file_count', total);
            if (form.querySelector('input[name="allow_duplicates"]') && form.querySelector('input[name="allow_duplicates"]').checked) {
                fd.append('allow_duplicates', '1');
            }
            fd.append('files[]', file, file.name);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.withCredentials = true;
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    var pct = Math.round((e.loaded / e.total) * 100);
                    progressItems[index].bar.style.width = pct + '%';
                    progressItems[index].status.textContent = pct + '%';
                }
            };
            xhr.onload = function() {
                try {
                    var data = JSON.parse(xhr.responseText || '{}');
                } catch (ex) {
                    data = null;
                }
                if (data && data.results && data.results.length) {
                    resultsAccum = resultsAccum.concat(data.results);
                    var r = data.results[0];
                    progressItems[index].bar.style.width = '100%';
                    progressItems[index].status.textContent = (r.status === 'ok') ? 'OK' : (r.reason || 'Error');
                } else {
                    // fallback: if server didn't return structured JSON, mark as unknown
                    resultsAccum.push({ name: file.name, status: 'error', reason: 'Respuesta del servidor no válida' });
                    progressItems[index].status.textContent = 'Error';
                }
                // small delay to allow user to see 100%
                setTimeout(function(){ uploadNext(index+1); }, 200);
            };
            xhr.onerror = function() {
                resultsAccum.push({ name: file.name, status: 'error', reason: 'Error de red durante la subida' });
                progressItems[index].status.textContent = 'Error de red';
                setTimeout(function(){ uploadNext(index+1); }, 200);
            };
            xhr.send(fd);
        }

        function renderResults(results) {
            var resultsPanel = document.getElementById('uploadResultsTable');
            if (!resultsPanel) {
                window.location.reload();
                return;
            }
            var tbody = resultsPanel.tBodies[0];
            tbody.innerHTML = '';
            results.forEach(function(r){
                var tr = document.createElement('tr');
                var tdName = document.createElement('td'); tdName.textContent = r.name;
                var tdStatus = document.createElement('td'); tdStatus.innerHTML = (r.status === 'ok') ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">Error</span>';
                var tdReason = document.createElement('td'); tdReason.textContent = (r.status === 'ok') ? '-' : (r.reason || 'Error desconocido');
                tr.appendChild(tdName); tr.appendChild(tdStatus); tr.appendChild(tdReason);
                tbody.appendChild(tr);
            });
            var card = document.querySelector('.card');
            if (card) card.scrollIntoView({ behavior: 'smooth' });
            // Reinitialize interactive behaviors (sorting/filtering) after DOM update
            if (window.makeInteractiveTable) {
                window.makeInteractiveTable('uploadResultsTable','uploadResultsSearch','uploadResultsStatus');
            }
        }

        // If there were invalid files, seed resultsAccum so they appear in the final table
        if (invalidResults.length) resultsAccum = resultsAccum.concat(invalidResults);

        // start sequential per-file uploads
        uploadNext(0);
    });
    // end submit handler
});
</script>

<?php renderPageEnd(); ?>
