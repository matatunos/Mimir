<?php
// test_ldap.php - endpoint para testear conexión LDAP/AD y devolver SIEMPRE JSON
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ldap.php';

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => "PHP error: $errstr in $errfile:$errline"
    ]);
    exit;
});
set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Excepción: ' . $e->getMessage()
    ]);
    exit;
});

$auth = new Auth();
if (!$auth->isAdmin()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'No autorizado. Inicie sesión como admin.'
    ]);
    exit;
}

$type = $_REQUEST['type'] ?? 'ldap';
if (!in_array($type, ['ldap', 'ad'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Tipo inválido.'
    ]);
    exit;
}

$action = $_REQUEST['action'] ?? 'test';
if (!in_array($action, ['test', 'auth'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Acción inválida. Use "test" o "auth".'
    ]);
    exit;
}

try {
    $ldap = new LdapAuth($type);
    if ($action === 'test') {
        if (!method_exists($ldap, 'testConnection')) {
            throw new Exception('Método testConnection() no implementado en LdapAuth.');
        }
        $result = $ldap->testConnection();
        if (is_array($result) && isset($result['success'])) {
            echo json_encode($result);
        } else if ($result === true) {
            echo json_encode([
                'success' => true,
                'message' => 'Conexión exitosa.'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => is_string($result) ? $result : 'Fallo de conexión.'
            ]);
        }
    } else {
        // auth action - require POST and username/password
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Use POST para autenticar.']);
            exit;
        }
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        if (empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'username y password son requeridos en POST.']);
            exit;
        }

        // Do not echo or persist password. Call authenticate and return structured result.
        $authOk = false;
        try {
            $authOk = $ldap->authenticate($username, $password);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Excepción: ' . $e->getMessage()]);
            exit;
        }
        echo json_encode([
            'success' => (bool)$authOk,
            'action' => 'auth',
            'type' => $type,
            'username' => $username
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Excepción: ' . $e->getMessage()
    ]);
}
