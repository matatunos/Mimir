<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../classes/Config.php';

header('Content-Type: application/json; charset=utf-8');

$config = new Config();
$db = Database::getInstance()->getConnection();

$uploadsPath = defined('UPLOADS_PATH') ? UPLOADS_PATH : (dirname(__DIR__,2) . '/storage/uploads');
$diskTotal = @disk_total_space($uploadsPath) ?: 0;
$diskFree = @disk_free_space($uploadsPath) ?: 0;
$diskUsed = max(0, $diskTotal - $diskFree);
$diskPercent = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0;
$diskTotalGB = round($diskTotal / 1024 / 1024 / 1024, 2);
$diskUsedGB = round($diskUsed / 1024 / 1024 / 1024, 2);
$diskCapacityGB = $config->get('disk_capacity_gb', 27.8);

$disk_from = isset($_GET['disk_from']) ? $_GET['disk_from'] : null;
$disk_to = isset($_GET['disk_to']) ? $_GET['disk_to'] : null;
$disk_period = isset($_GET['disk_period']) ? (int)$_GET['disk_period'] : null;

try {
    if ($disk_from && $disk_to) {
        $fromDt = $disk_from . ' 00:00:00';
        $toDt = $disk_to . ' 23:59:59';
        $stmt = $db->prepare("SELECT recorded_at, used_bytes, total_bytes FROM disk_usage_metrics WHERE recorded_at BETWEEN ? AND ? ORDER BY recorded_at ASC");
        $stmt->execute([$fromDt, $toDt]);
        $rows = $stmt->fetchAll();
    } else if ($disk_period && $disk_period > 0) {
        $from = date('Y-m-d', strtotime("-" . ($disk_period - 1) . " days"));
        $fromDt = $from . ' 00:00:00';
        $toDt = date('Y-m-d') . ' 23:59:59';
        $stmt = $db->prepare("SELECT recorded_at, used_bytes, total_bytes FROM disk_usage_metrics WHERE recorded_at BETWEEN ? AND ? ORDER BY recorded_at ASC");
        $stmt->execute([$fromDt, $toDt]);
        $rows = $stmt->fetchAll();
    } else {
        $rows = $db->query("SELECT recorded_at, used_bytes, total_bytes FROM disk_usage_metrics ORDER BY recorded_at ASC LIMIT 168")->fetchAll();
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'db_error']);
    exit;
}

$labels = [];
$used = [];
$total = [];
foreach ($rows as $r) {
    $labels[] = date('d/m H:i', strtotime($r['recorded_at']));
    $used[] = round($r['used_bytes'] / 1024 / 1024 / 1024, 2);
    $total[] = round($r['total_bytes'] / 1024 / 1024 / 1024, 2);
}

$out = [
    'labels' => $labels,
    'used' => $used,
    'total' => $total,
    'percent' => $diskPercent,
    'used_gb' => $diskUsedGB,
    'total_gb' => $diskTotalGB,
    'capacity_gb' => (float)$diskCapacityGB,
    '_params' => [ 'disk_from' => $disk_from, 'disk_to' => $disk_to, 'disk_period' => $disk_period ]
];

echo json_encode($out);
