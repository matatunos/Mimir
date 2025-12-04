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

$siteName = SystemConfig::get('site_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml($siteName); ?> - Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #1e293b;
        }
        
        .navbar {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1rem 0;
        }
        
        .navbar-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .navbar-brand {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .navbar-menu {
            display: flex;
            gap: 1rem;
        }
        
        .navbar-menu a {
            color: #64748b;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .navbar-menu a:hover,
        .navbar-menu a.active {
            background: #f1f5f9;
            color: #667eea;
        }
        
        .main-layout {
            display: flex;
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
            gap: 2rem;
            align-items: flex-start;
        }
        
        .sidebar {
            width: 280px;
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            position: sticky;
            top: 2rem;
            max-height: calc(100vh - 4rem);
            overflow-y: auto;
        }
        
        .sidebar h3 {
            font-size: 1.125rem;
            color: #1e293b;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .folder-tree {
            list-style: none;
        }
        
        .folder-tree-item {
            margin: 0.25rem 0;
        }
        
        .folder-tree-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: 8px;
            text-decoration: none;
            color: #475569;
            transition: all 0.2s;
            font-size: 0.9rem;
        }
        
        .folder-tree-link:hover {
            background: #f1f5f9;
            color: #667eea;
        }
        
        .folder-tree-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 600;
        }
        
        .folder-tree-link.active .folder-tree-icon {
            color: white;
        }
        
        .folder-tree-icon {
            font-size: 1rem;
            color: #94a3b8;
            transition: color 0.2s;
        }
        
        .folder-tree-toggle {
            background: none;
            border: none;
            cursor: pointer;
            color: #94a3b8;
            padding: 0;
            margin-right: 0.25rem;
            font-size: 0.75rem;
            transition: transform 0.2s;
        }
        
        .folder-tree-toggle.expanded {
            transform: rotate(90deg);
        }
        
        .folder-tree-children {
            list-style: none;
            margin-left: 1.25rem;
            border-left: 1px solid #e2e8f0;
            padding-left: 0.5rem;
            margin-top: 0.25rem;
        }
        
        .container {
            flex: 1;
            min-width: 0;
        }
        
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .dashboard-header {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .header-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .header-title h1 {
            font-size: 2rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .btn-back {
            background: #f1f5f9;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            color: #64748b;
            text-decoration: none;
        }
        
        .btn-back:hover {
            background: #e2e8f0;
            color: #475569;
        }
        
        .dashboard-actions {
            display: flex;
            gap: 1rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            font-size: 0.95rem;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        
        .btn-danger {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .btn-danger:hover {
            background: #fecaca;
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .breadcrumb a {
            color: #64748b;
            text-decoration: none;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .breadcrumb a:hover {
            background: #f1f5f9;
            color: #667eea;
        }
        
        .breadcrumb-current {
            color: #667eea !important;
            font-weight: 600;
        }
        
        .breadcrumb-separator {
            color: #cbd5e1;
        }
        
        .storage-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .storage-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .storage-header h3 {
            font-size: 1.125rem;
            color: #475569;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .storage-label {
            font-weight: 600;
            color: #667eea;
        }
        
        .storage-bar {
            height: 12px;
            background: #f1f5f9;
            border-radius: 999px;
            overflow: hidden;
        }
        
        .storage-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transition: width 0.3s;
        }
        
        .content-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .card-header {
            margin-bottom: 1.5rem;
        }
        
        .card-header h2 {
            font-size: 1.5rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .folder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1rem;
        }
        
        .folder-item a {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1.5rem 1rem;
            background: #f8fafc;
            border-radius: 12px;
            text-decoration: none;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .folder-item a:hover {
            background: #ede9fe;
            border-color: #a78bfa;
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(167, 139, 250, 0.2);
        }
        
        .folder-icon {
            font-size: 3rem;
            margin-bottom: 0.5rem;
        }
        
        .folder-name {
            color: #475569;
            font-weight: 500;
            text-align: center;
        }
        
        .file-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .file-table thead {
            background: #f8fafc;
        }
        
        .file-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .file-table td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .file-table tbody tr {
            transition: background 0.2s;
        }
        
        .file-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .empty-message {
            text-align: center;
            padding: 4rem 2rem;
            color: #94a3b8;
        }
        
        .empty-message i {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
            display: block;
        }
        
        .btn-group {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
        }
        
        .badge {
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-secondary {
            background: #f1f5f9;
            color: #64748b;
        }
        
        .share-status {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .share-details {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 0.25rem;
        }
        
        .share-details small {
            color: #64748b;
            font-size: 0.75rem;
        }
        
        .text-danger {
            color: #dc2626;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .modal-content h2 {
            margin-bottom: 1.5rem;
            color: #1e293b;
        }
        
        .close {
            float: right;
            font-size: 2rem;
            font-weight: 300;
            color: #94a3b8;
            cursor: pointer;
            line-height: 1;
            transition: color 0.2s;
        }
        
        .close:hover {
            color: #475569;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #475569;
            font-weight: 500;
        }
        
        .form-group input[type="text"],
        .form-group input[type="file"] {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-group small {
            color: #64748b;
            font-size: 0.875rem;
            margin-top: 0.25rem;
            display: block;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-cloud"></i>
                <?php echo escapeHtml($siteName); ?>
            </div>
            <div class="navbar-menu">
                <a href="dashboard.php" class="active">
                    <i class="fas fa-folder"></i> Mis Archivos
                </a>
                <a href="shares.php">
                    <i class="fas fa-share-alt"></i> Compartidos
                </a>
                <?php if (Auth::isAdmin()): ?>
                <a href="admin_dashboard.php">
                    <i class="fas fa-shield-alt"></i> Administración
                </a>
                <?php endif; ?>
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                </a>
            </div>
        </div>
    </div>
    
    <div class="main-layout">
        <!-- Folder Tree Sidebar -->
        <aside class="sidebar">
            <h3>
                <i class="fas fa-folder-tree"></i>
                Carpetas
            </h3>
            <ul class="folder-tree">
                <li class="folder-tree-item">
                    <a href="dashboard.php" class="folder-tree-link <?php echo !$currentFolder ? 'active' : ''; ?>">
                        <i class="fas fa-home folder-tree-icon"></i>
                        <span>Raíz</span>
                    </a>
                </li>
                <?php
                function renderFolderTree($folders, $currentFolderId, $level = 0) {
                    foreach ($folders as $folder) {
                        $hasChildren = !empty($folder['children']);
                        $isActive = $currentFolderId == $folder['id'];
                        $isExpanded = false;
                        
                        // Check if current folder is in this branch
                        if ($currentFolderId) {
                            global $breadcrumbs;
                            foreach ($breadcrumbs as $crumb) {
                                if ($crumb['id'] == $folder['id']) {
                                    $isExpanded = true;
                                    break;
                                }
                            }
                            if ($isActive) $isExpanded = true;
                        }
                        
                        echo '<li class="folder-tree-item">';
                        echo '<div style="display: flex; align-items: center;">';
                        
                        if ($hasChildren) {
                            echo '<button class="folder-tree-toggle ' . ($isExpanded ? 'expanded' : '') . '" onclick="toggleFolder(this)">';
                            echo '<i class="fas fa-chevron-right"></i>';
                            echo '</button>';
                        } else {
                            echo '<span style="width: 16px;"></span>';
                        }
                        
                        echo '<a href="dashboard.php?folder=' . $folder['id'] . '" class="folder-tree-link ' . ($isActive ? 'active' : '') . '" style="flex: 1;">';
                        echo '<i class="fas fa-folder folder-tree-icon"></i>';
                        echo '<span>' . escapeHtml($folder['name']) . '</span>';
                        echo '</a>';
                        echo '</div>';
                        
                        if ($hasChildren) {
                            echo '<ul class="folder-tree-children" style="display: ' . ($isExpanded ? 'block' : 'none') . ';">';
                            renderFolderTree($folder['children'], $currentFolderId, $level + 1);
                            echo '</ul>';
                        }
                        
                        echo '</li>';
                    }
                }
                renderFolderTree($folderTree, $currentFolder);
                ?>
            </ul>
        </aside>
        
        <div class="container">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo escapeHtml($message); ?>
                </div>
            <?php endif; ?>
        
        <div class="dashboard-header">
            <div class="header-top">
                <div class="header-title">
                    <?php if ($currentFolder): ?>
                        <?php 
                            $parentId = null;
                            if (!empty($breadcrumbs)) {
                                $currentBreadcrumb = end($breadcrumbs);
                                $parentId = $currentBreadcrumb['parent_id'];
                            }
                            $backUrl = $parentId ? "dashboard.php?folder={$parentId}" : "dashboard.php";
                        ?>
                        <a href="<?php echo $backUrl; ?>" class="btn-back" title="Volver">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                    <?php endif; ?>
                    <h1>
                        <i class="fas fa-folder-open"></i>
                        Mis Archivos
                    </h1>
                </div>
                <div class="dashboard-actions">
                    <button class="btn btn-primary" onclick="showUploadModal()">
                        <i class="fas fa-upload"></i> Subir Archivo
                    </button>
                    <button class="btn btn-secondary" onclick="showCreateFolderModal()">
                        <i class="fas fa-folder-plus"></i> Nueva Carpeta
                    </button>
                </div>
            </div>
            
            <nav class="breadcrumb">
                <a href="dashboard.php" <?php echo !$currentFolder ? 'class="breadcrumb-current"' : ''; ?>>
                    <i class="fas fa-home"></i> Inicio
                </a>
                <?php foreach ($breadcrumbs as $index => $crumb): ?>
                    <span class="breadcrumb-separator"><i class="fas fa-chevron-right"></i></span>
                    <a href="dashboard.php?folder=<?php echo $crumb['id']; ?>" 
                       <?php echo ($index === count($breadcrumbs) - 1) ? 'class="breadcrumb-current"' : ''; ?>>
                        <i class="fas fa-folder"></i> <?php echo escapeHtml($crumb['name']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        
        <div class="storage-card">
            <div class="storage-header">
                <h3>
                    <i class="fas fa-hdd"></i>
                    Uso de Almacenamiento
                </h3>
                <span class="storage-label">
                    <?php echo formatBytes($user['storage_used']); ?> / <?php echo formatBytes($user['storage_quota']); ?>
                </span>
            </div>
            <div class="storage-bar">
                <div class="storage-bar-fill" style="width: <?php echo min(100, ($user['storage_used'] / $user['storage_quota']) * 100); ?>%"></div>
            </div>
        </div>
        
        <?php if (!empty($folders)): ?>
        <div class="content-card">
            <div class="card-header">
                <h2>
                    <i class="fas fa-folder"></i>
                    Carpetas
                </h2>
            </div>
            <div class="folder-grid">
                <?php foreach ($folders as $folder): ?>
                    <div class="folder-item">
                        <a href="?folder=<?php echo $folder['id']; ?>">
                            <span class="folder-icon">📁</span>
                            <span class="folder-name"><?php echo escapeHtml($folder['name']); ?></span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="content-card">
            <div class="card-header">
                <h2>
                    <i class="fas fa-file"></i>
                    Archivos
                </h2>
            </div>
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
                            <th style="width: 100px;">Tamaño</th>
                            <th style="width: 200px;">Estado</th>
                            <th style="width: 140px;">Subido</th>
                            <th style="width: 250px;">Acciones</th>
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
                                            
                                            <?php if ($file['share_max_downloads']): ?>
                                                <small><i class="fas fa-download"></i> <?php echo $file['share_download_count']; ?>/<?php echo $file['share_max_downloads']; ?></small>
                                            <?php else: ?>
                                                <small><i class="fas fa-download"></i> <?php echo $file['share_download_count']; ?> descargas</small>
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
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Upload Modal -->
    <div id="uploadModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeUploadModal()">&times;</span>
            <h2>Subir Archivo</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="folder_id" value="<?php echo escapeHtml($currentFolder ?? ''); ?>">
                
                <div class="form-group">
                    <label for="files">Seleccionar Archivos</label>
                    <input type="file" id="files" name="files[]" multiple required>
                    <small>Mantén presionado Ctrl/Cmd para seleccionar múltiples archivos</small>
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-upload"></i> Subir
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeUploadModal()" style="flex: 1;">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Create Folder Modal -->
    <div id="createFolderModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeCreateFolderModal()">&times;</span>
            <h2>Crear Carpeta</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_folder">
                <input type="hidden" name="parent_id" value="<?php echo escapeHtml($currentFolder ?? ''); ?>">
                
                <div class="form-group">
                    <label for="folder_name">Nombre de la Carpeta</label>
                    <input type="text" id="folder_name" name="folder_name" required>
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-folder-plus"></i> Crear
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeCreateFolderModal()" style="flex: 1;">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
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
</body>
</html>
