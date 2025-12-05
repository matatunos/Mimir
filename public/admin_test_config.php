<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();
header('Content-Type: application/json');
$db = Database::getInstance()->getConnection();
$type = $_POST['type'] ?? '';
$response = ['success'=>false];
if ($type === 'mail') {
    // Prueba de correo
    $host = SystemConfig::get('smtp_host','');
    $port = SystemConfig::get('smtp_port','');
    $user = SystemConfig::get('smtp_username','');
    $pass = SystemConfig::get('smtp_password','');
    $from = SystemConfig::get('smtp_from_email','');
    $to = $from;
    $subject = 'Prueba de correo Mimir';
    $body = 'Este es un mensaje de prueba.';
    $headers = "From: $from\r\n";
    $ok = mail($to, $subject, $body, $headers);
    $response['success'] = $ok;
    $response['msg'] = $ok ? 'Correo enviado correctamente.' : 'Error al enviar correo.';
    echo json_encode($response); exit;
}
if ($type === 'ldap') {
    require_once __DIR__ . '/../includes/LdapAuth.php';
    $ldap = new LdapAuth();
    $ok = $ldap->isEnabled() && $ldap->testConnection();
    $response['success'] = $ok;
    $response['msg'] = $ok ? 'Conexión LDAP exitosa.' : 'Error de conexión LDAP.';
    echo json_encode($response); exit;
}
if ($type === 'duo') {
    // Simulación básica, aquí iría la llamada real a la API de DUO
    $enabled = SystemConfig::get('duo_enabled','false') === 'true';
    $response['success'] = $enabled;
    $response['msg'] = $enabled ? 'DUO está habilitado.' : 'DUO no está habilitado.';
    echo json_encode($response); exit;
}
$response['msg'] = 'Tipo de prueba no soportado.';
echo json_encode($response);