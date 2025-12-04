<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireLogin();

$userId = Auth::getUserId();
$auth = new Auth();
$fileManager = new FileManager();
$folderManager = new FolderManager();

$user = $auth->getUserById($userId);
$currentFolder = $_GET['folder'] ?? null;

// Get folders
$folders = $folderManager->getUserFolders($userId, $currentFolder);

// Get files
$files = $fileManager->getUserFiles($userId, $currentFolder);

// Handle file upload
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'upload' && isset($_FILES['file'])) {
            try {
                $folderId = !empty($_POST['folder_id']) ? $_POST['folder_id'] : null;
                $fileId = $fileManager->upload($_FILES['file'], $userId, $folderId);
                $message = 'File uploaded successfully!';
                $messageType = 'success';
                
                // Refresh data
                $files = $fileManager->getUserFiles($userId, $currentFolder);
                $user = $auth->getUserById($userId);
            } catch (Exception $e) {
                $message = 'Upload failed: ' . $e->getMessage();
                $messageType = 'error';
            }
        } elseif ($_POST['action'] === 'create_folder') {
            try {
                $folderName = $_POST['folder_name'] ?? '';
                if (empty($folderName)) {
                    throw new Exception('Folder name is required');
                }
                $parentId = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;
                $folderManager->create($userId, $folderName, $parentId);
                $message = 'Folder created successfully!';
                $messageType = 'success';
                
                // Refresh folders
                $folders = $folderManager->getUserFolders($userId, $currentFolder);
            } catch (Exception $e) {
                $message = 'Failed to create folder: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

$siteName = SystemConfig::get('site_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml($siteName); ?> - Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="navbar">
        <div class="navbar-brand"><?php echo escapeHtml($siteName); ?></div>
        <div class="navbar-menu">
            <a href="dashboard.php" class="active">My Files</a>
            <a href="shares.php">Shares</a>
            <?php if (Auth::isAdmin()): ?>
            <a href="admin.php">Admin</a>
            <?php endif; ?>
            <a href="logout.php">Logout (<?php echo escapeHtml($user['username']); ?>)</a>
        </div>
    </div>
    
    <div class="container">
        <div class="main-content">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>"><?php echo escapeHtml($message); ?></div>
            <?php endif; ?>
            
            <div class="storage-info">
                <div class="storage-text">
                    Storage: <?php echo formatBytes($user['storage_used']); ?> / <?php echo formatBytes($user['storage_quota']); ?>
                </div>
                <div class="storage-bar">
                    <div class="storage-bar-fill" style="width: <?php echo min(100, ($user['storage_used'] / $user['storage_quota']) * 100); ?>%"></div>
                </div>
            </div>
            
            <div class="actions">
                <button class="btn btn-primary" onclick="showUploadModal()">Upload File</button>
                <button class="btn btn-secondary" onclick="showCreateFolderModal()">New Folder</button>
            </div>
            
            <div class="file-list">
                <h2>Folders</h2>
                <?php if (empty($folders)): ?>
                    <p class="empty-message">No folders yet</p>
                <?php else: ?>
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
                <?php endif; ?>
                
                <h2>Files</h2>
                <?php if (empty($files)): ?>
                    <p class="empty-message">No files yet. Upload your first file!</p>
                <?php else: ?>
                    <table class="file-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Size</th>
                                <th>Uploaded</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($files as $file): ?>
                            <tr>
                                <td><?php echo escapeHtml($file['original_filename']); ?></td>
                                <td><?php echo formatBytes($file['file_size']); ?></td>
                                <td><?php echo timeAgo($file['created_at']); ?></td>
                                <td>
                                    <a href="download.php?id=<?php echo $file['id']; ?>" class="btn btn-sm">Download</a>
                                    <a href="share_file.php?id=<?php echo $file['id']; ?>" class="btn btn-sm">Share</a>
                                    <a href="delete_file.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this file?')">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Upload Modal -->
    <div id="uploadModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeUploadModal()">&times;</span>
            <h2>Upload File</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="folder_id" value="<?php echo escapeHtml($currentFolder ?? ''); ?>">
                
                <div class="form-group">
                    <label for="file">Select File</label>
                    <input type="file" id="file" name="file" required>
                </div>
                
                <button type="submit" class="btn btn-primary">Upload</button>
                <button type="button" class="btn btn-secondary" onclick="closeUploadModal()">Cancel</button>
            </form>
        </div>
    </div>
    
    <!-- Create Folder Modal -->
    <div id="createFolderModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeCreateFolderModal()">&times;</span>
            <h2>Create Folder</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_folder">
                <input type="hidden" name="parent_id" value="<?php echo escapeHtml($currentFolder ?? ''); ?>">
                
                <div class="form-group">
                    <label for="folder_name">Folder Name</label>
                    <input type="text" id="folder_name" name="folder_name" required>
                </div>
                
                <button type="submit" class="btn btn-primary">Create</button>
                <button type="button" class="btn btn-secondary" onclick="closeCreateFolderModal()">Cancel</button>
            </form>
        </div>
    </div>
    
    <script src="js/script.js"></script>
</body>
</html>
