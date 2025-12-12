<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/Config.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/ForensicLogger.php';

header('Content-Type: application/json');

$auth = new Auth();

// Must be admin
if (!$auth->isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$user = $auth->getUser();

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !$auth->validateCsrfToken($_POST['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}


// Determine action type: default is maintenance, but can accept other toggles (e.g., config_protection)
$type = isset($_POST['type']) ? trim($_POST['type']) : 'maintenance';
$enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';

try {
    $configClass = new Config();
    $logger = new Logger();
    
    if ($type === 'config_protection') {
        // Toggle global config protection
        $configClass->set('enable_config_protection', $enabled ? '1' : '0', 'boolean');
        $action = $enabled ? 'Protección de configuración activada' : 'Protección de configuración desactivada';
        $logger->log($user['id'], 'config_protection_toggle', 'system', null, $action);
        try {
            $forensic = new ForensicLogger();
            $forensic->logSecurityEvent('config_protection_toggle', 'high', $action, ['enabled' => $enabled ? 1 : 0], $user['id']);
        } catch (Exception $e) {
            // Non-fatal: if forensic logging fails, continue but note in PHP log
            error_log('ForensicLogger error: ' . $e->getMessage());
        }
        echo json_encode(['success' => true, 'enabled' => $enabled, 'message' => $action]);
    } else {
        // Update maintenance mode
        $configClass->set('maintenance_mode', $enabled ? '1' : '0', 'boolean');
        // Log the action
        $action = $enabled ? 'Modo mantenimiento activado' : 'Modo mantenimiento desactivado';
        $logger->log($user['id'], 'maintenance_toggle', 'system', null, $action);
        echo json_encode(['success' => true, 'enabled' => $enabled, 'message' => $action]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
