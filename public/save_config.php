<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_dashboard.php');
    exit;
}

$existing = SystemConfig::getAll();
$existingMap = [];
foreach ($existing as $e) {
    $existingMap[$e['config_key']] = $e['config_type'];
}

foreach ($existingMap as $key => $type) {
    if ($type === 'boolean') {
        // Form uses select with 'true'/'false' or may omit the key
        if (isset($_POST[$key])) {
            $v = $_POST[$key];
            $val = ($v === 'true' || $v === '1' || $v === 'on') ? true : false;
        } else {
            $val = false;
        }
        SystemConfig::set($key, $val, 'boolean');
    } elseif ($type === 'integer') {
        if (isset($_POST[$key])) {
            SystemConfig::set($key, intval($_POST[$key]), 'integer');
        }
    } elseif ($type === 'json') {
        if (isset($_POST[$key])) {
            $decoded = json_decode($_POST[$key], true);
            if ($decoded !== null) {
                SystemConfig::set($key, $decoded, 'json');
            } else {
                SystemConfig::set($key, $_POST[$key], 'string');
            }
        }
    } else {
        if (isset($_POST[$key])) {
            SystemConfig::set($key, $_POST[$key], 'string');
        }
    }
}

// Also accept unknown posted keys (best-effort)
foreach ($_POST as $k => $v) {
    if (array_key_exists($k, $existingMap)) continue;
    // skip form control keys
    if (in_array($k, ['action', 'submit'])) continue;
    // best-effort infer type
    if ($v === 'true' || $v === 'false') {
        SystemConfig::set($k, $v === 'true', 'boolean');
    } elseif (is_numeric($v)) {
        SystemConfig::set($k, intval($v), 'integer');
    } else {
        SystemConfig::set($k, $v, 'string');
    }
}

SystemConfig::clearCache();

header('Location: admin_dashboard.php?msg=config_saved');
exit;
