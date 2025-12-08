<?php
// Start output buffering to prevent any unexpected output
ob_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Logger.php';

// Clean any output that may have been generated
ob_end_clean();

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireAdmin();
$adminUser = $auth->getUser();
$userClass = new User();
$logger = new Logger();

$response = ['success' => false, 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }
    
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Token de seguridad inválido');
    }
    
    $action = $_POST['action'] ?? '';
    $userId = intval($_POST['user_id'] ?? 0);
    
    if (!$userId) {
        throw new Exception('ID de usuario inválido');
    }
    
    // Prevent admin from deleting themselves
    if ($userId === $adminUser['id'] && in_array($action, ['delete', 'deactivate'])) {
        throw new Exception('No puedes realizar esta acción sobre tu propio usuario');
    }
    
    $targetUser = $userClass->getById($userId);
    if (!$targetUser) {
        throw new Exception('Usuario no encontrado');
    }
    
    switch ($action) {
        case 'delete':
            $result = $userClass->delete($userId);
            if ($result['success']) {
                $logger->log(
                    $adminUser['id'], 
                    'user_delete', 
                    'user', 
                    $userId, 
                    "Usuario '{$targetUser['username']}' eliminado. {$result['orphaned_files']} archivos huérfanos."
                );
                $response['success'] = true;
                $response['message'] = "Usuario eliminado correctamente";
                if ($result['orphaned_files'] > 0) {
                    $response['message'] .= ". {$result['orphaned_files']} archivos quedaron huérfanos.";
                }
            } else {
                throw new Exception($result['message'] ?? 'Error al eliminar usuario');
            }
            break;
            
        case 'activate':
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
            if ($stmt->execute([$userId])) {
                $logger->log($adminUser['id'], 'user_activate', 'user', $userId, "Usuario '{$targetUser['username']}' activado");
                $response['success'] = true;
                $response['message'] = 'Usuario activado correctamente';
            } else {
                throw new Exception('Error al activar usuario');
            }
            break;
            
        case 'deactivate':
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
            if ($stmt->execute([$userId])) {
                $logger->log($adminUser['id'], 'user_deactivate', 'user', $userId, "Usuario '{$targetUser['username']}' desactivado");
                $response['success'] = true;
                $response['message'] = 'Usuario desactivado correctamente';
            } else {
                throw new Exception('Error al desactivar usuario');
            }
            break;
            
        case 'reset_password':
            $newPassword = $_POST['new_password'] ?? '';
            
            // If no password provided, generate a random one
            if (empty($newPassword)) {
                $newPassword = bin2hex(random_bytes(8)); // 16 character random password
            }
            
            // Validate password length
            if (strlen($newPassword) < 6) {
                throw new Exception('La contraseña debe tener al menos 6 caracteres');
            }
            
            $db = Database::getInstance()->getConnection();
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            
            if ($stmt->execute([$hashedPassword, $userId])) {
                $logger->log(
                    $adminUser['id'], 
                    'user_password_reset', 
                    'user', 
                    $userId, 
                    "Contraseña reseteada para usuario '{$targetUser['username']}'"
                );
                $response['success'] = true;
                $response['message'] = 'Contraseña actualizada correctamente';
                $response['new_password'] = $newPassword;
            } else {
                throw new Exception('Error al actualizar la contraseña');
            }
            break;
            
        case 'setup_totp_ajax':
            require_once __DIR__ . '/../../classes/TwoFactor.php';
            $twoFactor = new TwoFactor();
            
            $secret = $twoFactor->generateSecret();
            $backupCodes = $twoFactor->generateBackupCodes(10);
            
            if ($twoFactor->enable($userId, 'totp', [
                'secret' => $secret,
                'backup_codes' => $backupCodes
            ])) {
                $qrCode = $twoFactor->generateQRCode($targetUser['username'], $secret);
                
                $logger->log(
                    $adminUser['id'], 
                    '2fa_admin_setup', 
                    'user', 
                    $userId, 
                    "Admin configuró 2FA TOTP para '{$targetUser['username']}'"
                );
                
                $response['success'] = true;
                $response['message'] = 'TOTP configurado correctamente';
                $response['username'] = $targetUser['username'];
                $response['qr_code'] = $qrCode;
                $response['backup_codes'] = $backupCodes;
            } else {
                throw new Exception('Error al configurar TOTP');
            }
            break;
            
        case 'setup_duo_ajax':
            require_once __DIR__ . '/../../classes/TwoFactor.php';
            require_once __DIR__ . '/../../classes/DuoAuth.php';
            $twoFactor = new TwoFactor();
            $duoAuth = new DuoAuth();
            
            // Check if Duo is configured
            $duoConfig = $duoAuth->getConfig();
            if (!$duoConfig['is_configured']) {
                throw new Exception('Duo no está configurado en el sistema');
            }
            
            if ($twoFactor->enable($userId, 'duo', [])) {
                $logger->log(
                    $adminUser['id'], 
                    '2fa_admin_setup', 
                    'user', 
                    $userId, 
                    "Admin configuró 2FA Duo para '{$targetUser['username']}'"
                );
                
                $response['success'] = true;
                $response['message'] = "Duo Security configurado para {$targetUser['username']}. Lo usará en su próximo login.";
            } else {
                throw new Exception('Error al configurar Duo');
            }
            break;
            
        case 'send_totp_email':
            require_once __DIR__ . '/../../classes/TwoFactor.php';
            require_once __DIR__ . '/../../classes/Email.php';
            $twoFactor = new TwoFactor();
            
            if (empty($targetUser['email'])) {
                throw new Exception('El usuario no tiene email configurado');
            }
            
            $config = $twoFactor->getUserConfig($userId);
            if (!$config || $config['method'] !== 'totp') {
                throw new Exception('El usuario no tiene TOTP configurado');
            }
            
            $qrCode = $twoFactor->generateQRCode($targetUser['username'], $config['totp_secret']);
            
            // Get backup codes
            $backupCodesHtml = '';
            if (!empty($config['backup_codes'])) {
                $backupCodesHtml = '<h3 style="color: #333; margin-top: 2rem;">Códigos de Respaldo</h3>';
                $backupCodesHtml .= '<p style="color: #666;">Guarda estos códigos en un lugar seguro. Cada uno puede usarse una sola vez si no tienes acceso a tu app autenticadora:</p>';
                $backupCodesHtml .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 20px 0;">';
                foreach ($config['backup_codes'] as $code) {
                    $backupCodesHtml .= '<div style="background: #f5f5f5; padding: 10px; font-family: monospace; border-radius: 4px; text-align: center; border: 1px solid #ddd;">' . htmlspecialchars($code) . '</div>';
                }
                $backupCodesHtml .= '</div>';
            }
            
            $emailBody = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <h2 style='color: #333;'>Configuración de Autenticación de Dos Factores (2FA)</h2>
                <p>Hola {$targetUser['full_name']},</p>
                <p>Tu cuenta ha sido configurada para usar autenticación de dos factores (TOTP). Sigue estos pasos:</p>
                
                <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                    <h3 style='color: #333; margin-top: 0;'>1. Escanea el código QR</h3>
                    <p style='color: #666;'>Usa tu aplicación autenticadora (Google Authenticator, Authy, Microsoft Authenticator, etc.) para escanear este código:</p>
                    <div style='text-align: center; margin: 20px 0;'>
                        <img src='{$qrCode}' alt='QR Code' style='max-width: 250px; border: 1px solid #ddd; padding: 10px; background: white; border-radius: 8px;'>
                    </div>
                </div>
                
                {$backupCodesHtml}
                
                <div style='background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 20px 0;'>
                    <p style='color: #856404; margin: 0;'><strong>⚠️ Importante:</strong> Guarda este email en un lugar seguro. Los códigos de respaldo solo se muestran una vez.</p>
                </div>
                
                <p style='color: #666; margin-top: 30px;'>Si tienes alguna pregunta, contacta con el administrador del sistema.</p>
            </div>
            ";
            
            $email = new Email();
            if ($email->send($targetUser['email'], 'Configuración de Autenticación de Dos Factores', $emailBody)) {
                $logger->log(
                    $adminUser['id'], 
                    '2fa_email_sent', 
                    'user', 
                    $userId, 
                    "Email con configuración TOTP enviado a {$targetUser['email']}"
                );
                $response['success'] = true;
                $response['message'] = "Email enviado correctamente a {$targetUser['email']}";
            } else {
                throw new Exception('Error al enviar el email');
            }
            break;
            
        default:
            throw new Exception('Acción no válida');
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
