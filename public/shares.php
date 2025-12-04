<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireLogin();

$userId = Auth::getUserId();
$shareManager = new ShareManager();

// Handle share deletion
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_share') {
        $shareId = $_POST['share_id'] ?? null;
        if ($shareId) {
            try {
                $shareManager->deleteShare($shareId, $userId);
                $message = 'Share deleted successfully';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Failed to delete share: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($_POST['action'] === 'deactivate_share') {
        $shareId = $_POST['share_id'] ?? null;
        if ($shareId) {
            try {
                $shareManager->deactivateShare($shareId, $userId);
                $message = 'Share deactivated successfully';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Failed to deactivate share: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

$shares = $shareManager->getUserShares($userId);
$siteName = SystemConfig::get('site_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml($siteName); ?> - Compartidos</title>
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
        
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
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
        
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            font-size: 2rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .content-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
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
        
        .empty-message a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .empty-message a:hover {
            text-decoration: underline;
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
        
        .share-link-input {
            width: 100%;
            max-width: 400px;
            padding: 0.5rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.875rem;
            font-family: monospace;
            background: #f8fafc;
            color: #475569;
        }
        
        .share-link-input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            font-size: 0.875rem;
        }
        
        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.8125rem;
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
        
        .btn-group {
            display: flex;
            gap: 0.5rem;
        }
        
        .text-muted {
            color: #94a3b8;
            font-style: italic;
        }
        
        .share-type {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .share-type-label {
            font-weight: 600;
            color: #1e293b;
            font-size: 0.875rem;
        }
        
        .share-type-details {
            color: #64748b;
            font-size: 0.8125rem;
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
                <a href="dashboard.php">
                    <i class="fas fa-folder"></i> Mis Archivos
                </a>
                <a href="shares.php" class="active">
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
    
    <div class="container">
        <div class="page-header">
            <h1>
                <i class="fas fa-share-nodes"></i>
                Mis Enlaces Compartidos
            </h1>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo escapeHtml($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="content-card">
            <?php if (empty($shares)): ?>
                <div class="empty-message">
                    <i class="fas fa-share-nodes"></i>
                    <p>Aún no tienes enlaces compartidos.</p>
                    <p><a href="dashboard.php">Sube y comparte archivos</a></p>
                </div>
            <?php else: ?>
                <table class="file-table">
                    <thead>
                        <tr>
                            <th>Archivo</th>
                            <th>Tipo de Enlace</th>
                            <th>Estado</th>
                            <th>Creado</th>
                            <th>Enlace</th>
                            <th style="width: 180px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shares as $share): ?>
                        <tr>
                            <td>
                                <i class="fas fa-file-alt" style="color: #94a3b8; margin-right: 0.5rem;"></i>
                                <?php echo escapeHtml($share['original_filename']); ?>
                            </td>
                            <td>
                                <div class="share-type">
                                    <?php if ($share['share_type'] === 'time'): ?>
                                        <span class="share-type-label">
                                            <i class="fas fa-clock"></i> Temporal
                                        </span>
                                        <span class="share-type-details">
                                            Expira: <?php echo date('d M Y', strtotime($share['expires_at'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="share-type-label">
                                            <i class="fas fa-download"></i> Por Descargas
                                        </span>
                                        <span class="share-type-details">
                                            <?php echo $share['current_downloads']; ?>/<?php echo $share['max_downloads']; ?> descargas
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($share['is_active']): ?>
                                    <span class="badge badge-success">
                                        <i class="fas fa-check"></i> Activo
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">
                                        <i class="fas fa-ban"></i> Inactivo
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="color: #64748b;"><?php echo timeAgo($share['created_at']); ?></td>
                            <td>
                                <?php if ($share['is_active']): ?>
                                    <input type="text" 
                                           class="share-link-input" 
                                           value="<?php echo BASE_URL . '/share.php?token=' . escapeHtml($share['share_token']); ?>" 
                                           readonly 
                                           onclick="this.select(); navigator.clipboard.writeText(this.value);"
                                           title="Click para copiar">
                                <?php else: ?>
                                    <span class="text-muted">Enlace inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <?php if ($share['is_active']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="deactivate_share">
                                            <input type="hidden" name="share_id" value="<?php echo $share['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('¿Desactivar este enlace?')" title="Desactivar">
                                                <i class="fas fa-pause"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_share">
                                        <input type="hidden" name="share_id" value="<?php echo $share['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar este enlace?')" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
