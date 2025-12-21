<?php
header('Content-Type: application/json; charset=utf-8');
$out = [];
$out['time'] = date('c');
$out['remote_addr'] = $_SERVER['REMOTE_ADDR'] ?? null;
$out['remote_port'] = $_SERVER['REMOTE_PORT'] ?? null;
$out['server_addr'] = $_SERVER['SERVER_ADDR'] ?? null;
$out['server_name'] = $_SERVER['SERVER_NAME'] ?? null;
$out['http_host'] = $_SERVER['HTTP_HOST'] ?? null;
$out['headers'] = function_exists('getallheaders') ? getallheaders() : [];
$out['x_forwarded_for'] = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$out['x_forwarded_proto'] = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
$out['cookie'] = $_SERVER['HTTP_COOKIE'] ?? null;
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
?>
