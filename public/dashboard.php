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
            } catch (Exception $e) {
                $message = 'Error al subir: ' . $e->getMessage();
                $messageType = 'error';
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
        <div id="ajax-shares-container" style="display:none;"></div>
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
        <div class="dashboard-main" style="display:flex;gap:2rem;align-items:flex-start;">
            <aside class="folder-sidebar" style="min-width:260px;max-width:320px;width:25%;background:#fff;border-radius:16px;padding:1.5rem 1rem 1.5rem 1.5rem;box-shadow:0 2px 8px rgba(100,116,139,0.06);">
                <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;">
                    <h2 style="margin:0;font-size:1.2rem;"><i class="fas fa-folder-tree"></i> Carpetas</h2>
                    <button class="btn btn-secondary" onclick="showCreateFolderModal()" title="Nueva Carpeta"><i class="fas fa-folder-plus"></i></button>
                </div>
                <?php
                // Renderizar árbol de carpetas recursivo
                function renderFolderTree($tree, $currentFolder, $level = 0, $isLast = false) {
                    echo '<ul class="folder-tree" style="list-style:none;margin:0;padding-left:' . ($level > 0 ? 18 : 0) . 'px;position:relative;">';
                    $count = count($tree);
                    $i = 0;
                    foreach ($tree as $folder) {
                        $i++;
                        $isActive = $currentFolder == $folder['id'];
                        $isLastChild = ($i === $count);
                        echo '<li style="margin-bottom:0.3em;position:relative;">';
                        // Líneas verticales y horizontales
                        if ($level > 0) {
                            echo '<span style="position:absolute;left:-13px;top:0;height:100%;width:13px;border-left:1.5px solid #cbd5e1;' . ($isLastChild ? 'height:1.2em;' : '') . '"></span>';
                            echo '<span style="position:absolute;left:-13px;top:1.1em;width:13px;border-bottom:1.5px solid #cbd5e1;"></span>';
                        }
                        echo '<a href="?folder=' . $folder['id'] . '" class="' . ($isActive ? 'active-folder' : '') . '" style="display:flex;align-items:center;gap:0.5em;padding:0.3em 0.5em;border-radius:6px;text-decoration:none;' . ($isActive ? 'background:#e0e7ff;font-weight:600;color:#4338ca;' : 'color:#475569;') . '"><i class="fas fa-folder" style="font-size:1.5em;color:#fbbf24;"></i> ' . escapeHtml($folder['name']) . '</a>';
                        if (!empty($folder['children'])) {
                            renderFolderTree($folder['children'], $currentFolder, $level + 1, $isLastChild);
                        }
                        echo '</li>';
                    }
                    echo '</ul>';
                }
                // Carpeta raíz como nodo principal
                $isRoot = empty($currentFolder);
                echo '<ul class="folder-tree" style="list-style:none;padding-left:0;">';
                echo '<li style="margin-bottom:0.3em;position:relative;">';
                echo '<a href="dashboard.php" class="' . ($isRoot ? 'active-folder' : '') . '" style="display:flex;align-items:center;gap:0.5em;padding:0.3em 0.5em;border-radius:6px;text-decoration:none;' . ($isRoot ? 'background:#e0e7ff;font-weight:600;color:#4338ca;' : 'color:#475569;') . '"><i class="fas fa-hdd" style="font-size:1.5em;color:#fbbf24;"></i> Raíz</a>';
                if (!empty($folderTree)) {
                    renderFolderTree($folderTree, $currentFolder, 1);
                }
                echo '</li>';
                echo '</ul>';
                ?>
            </aside>
            <main style="flex:1;min-width:0;">
                <div class="dashboard-header" style="display:flex;flex-wrap:wrap;align-items:center;gap:1rem;justify-content:space-between;margin-bottom:2rem;">
                    <h1 style="margin:0;"><i class="fas fa-folder-open"></i> Mis Archivos</h1>
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center;">
                        <button class="btn btn-primary" onclick="showUploadModal()"><i class="fas fa-upload"></i> Subir Archivo</button>
                    </div>
                    <span style="font-weight:500;">
                        <i class="fas fa-hdd"></i> <?php echo formatBytes($user['storage_used']); ?> / <?php echo formatBytes($user['storage_quota']); ?> usados
                    </span>
                </div>
                <div class="content-card">
                    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                        <h2 style="margin:0;"><i class="fas fa-file"></i> Archivos</h2>
                        <form method="get" style="display:flex;align-items:center;gap:0.5rem;">
                            <input type="hidden" name="folder" value="<?php echo escapeHtml($currentFolder ?? ''); ?>">
                            <input type="text" name="file_filter" value="<?php echo isset($_GET['file_filter']) ? escapeHtml($_GET['file_filter']) : ''; ?>" placeholder="Filtrar por nombre..." class="form-control" style="min-width:180px;">
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
                            <th style="width: 90px;">Tamaño</th>
                            <th style="width: 130px;">Descargas</th>
                            <th style="width: 160px;">Estado</th>
                            <th style="width: 120px;">Subido</th>
                            <th style="width: 140px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $file): ?>
                        <tr>
                            <td>
                                <i class="fas fa-file-alt" style="color: #94a3b8; margin-right: 0.5rem;"></i>
                                <?php echo escapeHtml($file['original_filename']); ?>
                            </td>
                            <td><?php echo formatBytes($file['file_size']); ?></td>
                            <td>
                                <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                    <span class="badge" style="background: #dbeafe; color: #1e40af; display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                                        <i class="fas fa-user" style="font-size: 0.7rem;"></i> <?php echo (int)$file['download_count']; ?>
                                    </span>
                                    <?php if ($file['share_id']): ?>
                                        <span class="badge" style="background: #d1fae5; color: #065f46; display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                                            <i class="fas fa-globe" style="font-size: 0.7rem;"></i> <?php echo (int)$file['share_download_count']; ?><?php if ($file['share_max_downloads']): ?>/<?php echo $file['share_max_downloads']; ?><?php endif; ?>
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
                            <td style="color: #64748b;"><?php echo timeAgo($file['created_at']); ?></td>
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
</body>
</html>
