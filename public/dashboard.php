<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireLogin();

$userId = Auth::getUserId();
$auth = new Auth();
$fileManager = new FileManager();
$folderManager = new FolderManager();

$user = $auth->getUserById($userId);
$currentFolder = $_GET['folder'] ?? null;

// Get breadcrumb path
$breadcrumbs = [];
if ($currentFolder) {
    $db = Database::getInstance()->getConnection();
    $folderId = $currentFolder;
    while ($folderId) {
        $stmt = $db->prepare("SELECT id, name, parent_id FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folderId, $userId]);
        $folder = $stmt->fetch();
        if ($folder) {
            array_unshift($breadcrumbs, $folder);
            $folderId = $folder['parent_id'];
        } else {
            break;
        }
    }
}

// Get all folders for tree view
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT id, name, parent_id FROM folders WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([$userId]);
$allFolders = $stmt->fetchAll();

// Build folder tree
function buildFolderTree($folders, $parentId = null) {
    $branch = [];
    foreach ($folders as $folder) {
        if ($folder['parent_id'] == $parentId) {
            $children = buildFolderTree($folders, $folder['id']);
            if ($children) {
                $folder['children'] = $children;
            }
            $branch[] = $folder;
        }
    }
    return $branch;
}

$folderTree = buildFolderTree($allFolders);

// Get folders in current directory
$folders = $folderManager->getUserFolders($userId, $currentFolder);

// Get files
$files = $fileManager->getUserFiles($userId, $currentFolder);

// Handle file upload
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'upload' && isset($_FILES['files'])) {
            try {
                $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
                $folderId = !empty($_POST['folder_id']) ? $_POST['folder_id'] : null;
                $uploadedCount = 0;
                $errors = [];
                
                // Process multiple files
                $fileCount = count($_FILES['files']['name']);
                for ($i = 0; $i < $fileCount; $i++) {
                    if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
                        $file = [
                            'name' => $_FILES['files']['name'][$i],
                            'type' => $_FILES['files']['type'][$i],
                            'tmp_name' => $_FILES['files']['tmp_name'][$i],
                            'error' => $_FILES['files']['error'][$i],
                            'size' => $_FILES['files']['size'][$i]
                        ];
                        try {
                            $fileManager->upload($file, $userId, $folderId);
                            $uploadedCount++;
                        } catch (Exception $e) {
                            $errors[] = $_FILES['files']['name'][$i] . ': ' . $e->getMessage();
                        }
                    }
                }
                
                if ($uploadedCount > 0) {
                    $message = $uploadedCount . ' archivo(s) subido(s) exitosamente';
                    $messageType = 'success';
                    if (!empty($errors)) {
                        $message .= ' Errores: ' . implode(', ', $errors);
                    }
                } else {
                    $message = 'Error al subir: ' . implode(', ', $errors);
                    $messageType = 'error';
                }
                
                // Refresh data
                $files = $fileManager->getUserFiles($userId, $currentFolder);
                $user = $auth->getUserById($userId);
                if (!empty($isAjax)) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => $uploadedCount>0, 'uploaded' => $uploadedCount, 'errors' => $errors]);
                    exit;
                }
            } catch (Exception $e) {
                $message = 'Error al subir: ' . $e->getMessage();
                $messageType = 'error';
                if (!empty($isAjax)) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                    exit;
                }
            }
        } elseif ($_POST['action'] === 'create_folder') {
            try {
                $folderName = $_POST['folder_name'] ?? '';
                if (empty($folderName)) {
                    throw new Exception('El nombre de la carpeta es obligatorio');
                }
                $parentId = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;
                $folderManager->create($userId, $folderName, $parentId);
                $message = '¡Carpeta creada exitosamente!';
                $messageType = 'success';
                
                // Refresh all folders for tree view
                $stmt = $db->prepare("SELECT id, name, parent_id FROM folders WHERE user_id = ? ORDER BY name ASC");
                $stmt->execute([$userId]);
                $allFolders = $stmt->fetchAll();
                $folderTree = buildFolderTree($allFolders);
                
                // Refresh folders in current directory
                $folders = $folderManager->getUserFolders($userId, $currentFolder);
            } catch (Exception $e) {
                $message = 'Error al crear carpeta: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Unificado a layout tipo admin_dashboard.php
$siteName = SystemConfig::get('site_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="es">
<head>
</head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml($siteName); ?> - Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/admin.css">
        <link rel="stylesheet" href="/css/ui.css">
</head>
<body>
    <div id="menu">
        <div class="logo">Mimir</div>
        <div class="nav">
            <a href="dashboard.php" class="active">Mis Archivos</a>
            <a href="#" id="menu-shares-link"><i class="fas fa-share-alt"></i> Compartidos</a>
            <a href="logout.php">Salir</a>
        </div>
        <div class="user"><?php echo escapeHtml($user['username']); ?></div>
    </div>
    <div id="content">
        <div id="ajax-shares-container" class="hidden"></div>
        </script>
    <script>
    // AJAX upload handler with progress
    document.addEventListener('DOMContentLoaded', function(){
        var uploadForm = document.getElementById('uploadForm');
        var uploadModal = document.getElementById('uploadModal');
        var progress = document.getElementById('uploadProgress');
        var progressFill = document.getElementById('uploadProgressFill');

        if (uploadForm) {
            uploadForm.addEventListener('submit', function(e){
                e.preventDefault();
                var form = e.target;
                var fd = new FormData(form);

                var xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.pathname, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.upload.onprogress = function(ev){
                    if (ev.lengthComputable) {
                        var pct = Math.round((ev.loaded / ev.total) * 100);
                        progress.style.display = '';
                        progressFill.style.width = pct + '%';
                    }
                };

                xhr.onload = function(){
                    try {
                        var json = JSON.parse(xhr.responseText);
                        if (json && json.success) {
                            // simple behaviour: reload to refresh file list
                            location.reload();
                        } else {
                            alert('Error al subir: ' + (json.error || (json.errors && json.errors.join(', ')) || 'Error desconocido'));
                            progress.style.display = 'none';
                            progressFill.style.width = '0%';
                        }
                    } catch (err) {
                        alert('Error en la respuesta del servidor');
                        progress.style.display = 'none';
                        progressFill.style.width = '0%';
                    }
                };

                xhr.onerror = function(){
                    alert('Error de red durante la subida');
                    progress.style.display = 'none';
                    progressFill.style.width = '0%';
                };

                xhr.send(fd);
            });
        }

        // Show/hide modal using classes
        window.showUploadModal = function(){
            uploadModal.classList.remove('hidden');
            uploadModal.classList.add('active');
        };
        window.closeUploadModal = function(){
            uploadModal.classList.remove('active');
            uploadModal.classList.add('hidden');
        };
    });
    </script>
        <script>
        // AJAX para cargar compartidos en el dashboard
        document.addEventListener('DOMContentLoaded', function() {
            var sharesLink = document.getElementById('menu-shares-link');
            var contentDiv = document.getElementById('content');
            var sharesContainer = document.getElementById('ajax-shares-container');
            if (sharesLink) {
                sharesLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    fetch('shares.php?ajax=1')
                        .then(function(resp) { return resp.text(); })
                        .then(function(html) {
                            // Oculta el resto del contenido y muestra solo compartidos
                            Array.from(contentDiv.children).forEach(function(child) {
                                if (child !== sharesContainer) child.style.display = 'none';
                            });
                            sharesContainer.innerHTML = html;
                            sharesContainer.style.display = 'block';
                        });
                });
            }
        });
        </script>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo escapeHtml($message); ?>
            </div>
        <?php endif; ?>
        <div class="dashboard-main">
            <aside class="folder-sidebar">
                <div class="flex-row gap-1" style="margin-bottom:1.5rem;">
                    <h2 style="margin:0;font-size:1.2rem;"><i class="fas fa-folder-tree"></i> Carpetas</h2>
                    <button class="btn btn-secondary" onclick="showCreateFolderModal()" title="Nueva Carpeta"><i class="fas fa-folder-plus"></i></button>
                </div>
                <?php
                // Renderizar árbol de carpetas recursivo
                function renderFolderTree($tree, $currentFolder, $level = 0, $isLast = false) {
                    echo '<ul class="folder-tree" style="padding-left:' . ($level > 0 ? 18 : 0) . 'px;">';
                    $count = count($tree);
                    $i = 0;
                    foreach ($tree as $folder) {
                        $i++;
                        $isActive = $currentFolder == $folder['id'];
                        $isLastChild = ($i === $count);
                        echo '<li class="folder-item">';
                        // Líneas verticales y horizontales (keep inline for precise position)
                        if ($level > 0) {
                            echo '<span style="position:absolute;left:-13px;top:0;height:100%;width:13px;border-left:1.5px solid #cbd5e1;' . ($isLastChild ? 'height:1.2em;' : '') . '"></span>';
                            echo '<span style="position:absolute;left:-13px;top:1.1em;width:13px;border-bottom:1.5px solid #cbd5e1;"></span>';
                        }
                        echo '<a href="?folder=' . $folder['id'] . '" class="folder-link ' . ($isActive ? 'active' : '') . '"><i class="fas fa-folder folder-icon"></i> ' . escapeHtml($folder['name']) . '</a>';
                        if (!empty($folder['children'])) {
                            renderFolderTree($folder['children'], $currentFolder, $level + 1, $isLastChild);
                        }
                        echo '</li>';
                    }
                    echo '</ul>';
                }
                // Carpeta raíz como nodo principal
                $isRoot = empty($currentFolder);
                echo '<ul class="folder-tree">';
                echo '<li class="folder-item">';
                echo '<a href="dashboard.php" class="folder-link ' . ($isRoot ? 'active' : '') . '"><i class="fas fa-hdd folder-icon"></i> Raíz</a>';
                if (!empty($folderTree)) {
                    renderFolderTree($folderTree, $currentFolder, 1);
                }
                echo '</li>';
                echo '</ul>';
                ?>
            </aside>
            <main>
                <div class="dashboard-header">
                    <h1 style="margin:0;"><i class="fas fa-folder-open"></i> Mis Archivos</h1>
                    <div>
                        <button class="btn btn-primary" onclick="showUploadModal()"><i class="fas fa-upload"></i> Subir Archivo</button>
                    </div>
                    <span class="time-muted" style="font-weight:500;">
                        <i class="fas fa-hdd"></i> <?php echo formatBytes($user['storage_used']); ?> / <?php echo formatBytes($user['storage_quota']); ?> usados
                    </span>
                </div>
                <div class="content-card">
                    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                        <h2 style="margin:0;"><i class="fas fa-file"></i> Archivos</h2>
                        <form method="get" class="form-inline">
                            <input type="hidden" name="folder" value="<?php echo escapeHtml($currentFolder ?? ''); ?>">
                            <input type="text" name="file_filter" value="<?php echo isset($_GET['file_filter']) ? escapeHtml($_GET['file_filter']) : ''; ?>" placeholder="Filtrar por nombre..." class="form-control form-control-min">
                            <button type="submit" class="btn btn-secondary"><i class="fas fa-search"></i></button>
                        </form>
                    </div>
                    <?php
                        $fileFilter = isset($_GET['file_filter']) ? trim($_GET['file_filter']) : '';
                        if ($fileFilter !== '') {
                            $files = array_filter($files, function($f) use ($fileFilter) {
                                return stripos($f['original_filename'], $fileFilter) !== false;
                            });
                        }
                    ?>
                    <?php if (empty($files)): ?>
                <div class="empty-message">
                    <i class="fas fa-inbox"></i>
                    <p>Aún no hay archivos. ¡Sube tu primer archivo!</p>
                </div>
            <?php else: ?>
                <table class="file-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th class="w-90">Tamaño</th>
                            <th class="w-130">Descargas</th>
                            <th class="w-160">Estado</th>
                            <th class="w-120">Subido</th>
                            <th class="w-140">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $file): ?>
                        <tr>
                            <td>
                                <i class="fas fa-file-alt icon-muted"></i>
                                <?php echo escapeHtml($file['original_filename']); ?>
                            </td>
                            <td><?php echo formatBytes($file['file_size']); ?></td>
                            <td>
                                <div style="display:flex;flex-direction:column;gap:0.25rem;">
                                    <span class="badge-download badge download-count" data-file-id="<?php echo $file['id']; ?>">
                                        <i class="fas fa-user" style="font-size:0.7rem"></i> <?php echo (int)$file['download_count']; ?>
                                    </span>
                                    <?php if ($file['share_id']): ?>
                                        <span class="badge-share badge">
                                            <i class="fas fa-globe" style="font-size:0.7rem"></i> <?php echo (int)$file['share_download_count']; ?><?php if ($file['share_max_downloads']): ?>/<?php echo $file['share_max_downloads']; ?><?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($file['share_id']): ?>
                                    <div class="share-status">
                                        <span class="badge badge-success">
                                            <i class="fas fa-link"></i> Compartido
                                        </span>
                                        <div class="share-details">
                                            <?php 
                                            $expiresAt = $file['share_expires_at'] ? new DateTime($file['share_expires_at']) : null;
                                            $now = new DateTime();
                                            if ($expiresAt):
                                                $interval = $now->diff($expiresAt);
                                                if ($expiresAt < $now): ?>
                                                    <small class="text-danger"><i class="fas fa-clock"></i> Expirado</small>
                                                <?php else: ?>
                                                    <small><i class="fas fa-clock"></i> <?php echo $interval->days; ?>d restantes</small>
                                                <?php endif;
                                            else: ?>
                                                <small><i class="fas fa-infinity"></i> Sin vencimiento</small>
                                            <?php endif; ?>
                                            <?php if ($file['share_has_password']): ?>
                                                <small><i class="fas fa-lock"></i> Protegido</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="badge badge-secondary">
                                        <i class="fas fa-lock"></i> Privado
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="time-muted"><?php echo timeAgo($file['created_at']); ?></td>
                            <td>
                                <div class="btn-group">
                                    <a href="download.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-secondary" title="Descargar">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <?php if ($file['share_id']): ?>
                                        <a href="shares.php" class="btn btn-sm btn-primary" title="Ver compartido">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="share_file.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-secondary" title="Compartir archivo">
                                            <i class="fas fa-share"></i>
                                        </a>
                                    <?php endif; ?>
                                    <a href="delete_file.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar este archivo?')" title="Eliminar">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Botón de compartidos ahora está arriba -->
            <?php endif; ?>
        </div>
    </div>
    <!-- Upload Modal -->
    <div id="uploadModal" class="modal hidden">
        <div class="modal-content" style="max-width:640px;">
            <div class="modal-header">
                <h3><i class="fas fa-upload"></i> Subir Archivos</h3>
                <button class="modal-close" onclick="closeUploadModal()"><i class="fas fa-times"></i></button>
            </div>
            <div style="padding:1rem 0 0 0;">
                <form id="uploadForm" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="folder_id" value="<?php echo escapeHtml($currentFolder ?? ''); ?>">
                    <div class="form-group" style="margin-bottom:1rem;">
                        <label for="files">Selecciona archivos (puedes seleccionar varios)</label>
                        <input type="file" name="files[]" id="files" multiple required>
                    </div>
                    <div class="progress mb-1" id="uploadProgress" style="display:none;">
                        <div class="progress-fill" id="uploadProgressFill" style="width:0%"></div>
                    </div>
                    <div style="display:flex;gap:0.5rem;justify-content:flex-end;margin-top:0.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeUploadModal()">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Subir</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    
    
    <script>
        function showUploadModal() {
            document.getElementById('uploadModal').classList.add('active');
        }
        
        function closeUploadModal() {
            document.getElementById('uploadModal').classList.remove('active');
        }
        
        function showCreateFolderModal() {
            document.getElementById('createFolderModal').classList.add('active');
        }
        
        function closeCreateFolderModal() {
            document.getElementById('createFolderModal').classList.remove('active');
        }
        
        function toggleFolder(button) {
            button.classList.toggle('expanded');
            const childrenList = button.parentElement.parentElement.querySelector('.folder-tree-children');
            if (childrenList) {
                childrenList.style.display = childrenList.style.display === 'none' ? 'block' : 'none';
            }
        }
        
        // Close modals on outside click
        window.onclick = function(event) {
            const uploadModal = document.getElementById('uploadModal');
            const folderModal = document.getElementById('createFolderModal');
            if (event.target === uploadModal) {
                closeUploadModal();
            }
            if (event.target === folderModal) {
                closeCreateFolderModal();
            }
        }
    </script>
        <script>
            // Intercept download clicks, start download in hidden iframe and update counter via AJAX
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('a[href^="download.php?id="]').forEach(function(a) {
                    a.addEventListener('click', function(e) {
                        e.preventDefault();
                        var href = a.getAttribute('href');
                        var fileIdMatch = href.match(/id=(\d+)/);
                        var fileId = fileIdMatch ? fileIdMatch[1] : null;

                        // Start download via invisible iframe so browser download dialog appears
                        var iframe = document.createElement('iframe');
                        iframe.style.display = 'none';
                        iframe.src = href;
                        document.body.appendChild(iframe);

                        if (!fileId) return;

                        // Fetch updated stats and update counter
                        fetch('api/file_stats.php?id=' + encodeURIComponent(fileId), {credentials: 'same-origin'})
                            .then(function(resp) { return resp.json(); })
                            .then(function(json) {
                                if (json && typeof json.download_count !== 'undefined') {
                                    var el = document.querySelector('.download-count[data-file-id="' + fileId + '"]');
                                    if (el) {
                                        // Update number while preserving icon
                                        var icon = el.querySelector('i');
                                        el.textContent = '';
                                        if (icon) el.appendChild(icon);
                                        el.insertAdjacentText('beforeend', ' ' + json.download_count);
                                    }
                                }
                            })
                            .catch(function() { /* silent */ });
                    });
                });
            });
        </script>
</body>
</html>
