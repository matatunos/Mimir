<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Config.php';

// Note: this endpoint is intentionally NOT requiring an authenticated session
// so it can be called via AJAX from the admin dashboard and also tested from CLI.
$userClass = new User();
$config = new Config();

header('Content-Type: application/json; charset=utf-8');

try {
    $from = isset($_GET['from']) ? $_GET['from'] : null;
    $to = isset($_GET['to']) ? $_GET['to'] : null;
    $period = isset($_GET['period']) ? (int)$_GET['period'] : 7;

    $rows = [];
    if ($from && $to) {
        // validate YYYY-MM-DD
        $d1 = DateTime::createFromFormat('Y-m-d', $from);
        $d2 = DateTime::createFromFormat('Y-m-d', $to);
        if ($d1 && $d2 && $d1 <= $d2) {
            $rows = $userClass->getSystemUploadsBetween($from, $to);
        } else {
            $rows = $userClass->getSystemDailyUploads($period);
        }
    } else {
        $rows = $userClass->getSystemDailyUploads($period ?: 7);
    }

    // Ensure rows are ordered chronologically ascending by date
    usort($rows, function($a, $b) {
        $da = isset($a['date']) ? strtotime($a['date']) : 0;
        $db = isset($b['date']) ? strtotime($b['date']) : 0;
        return $da <=> $db;
    });

    $labels = [];
    $counts = [];
    $sizes = [];
    $users = [];
    foreach ($rows as $r) {
        $labels[] = date('d/m', strtotime($r['date']));
        $counts[] = (int)$r['count'];
        $sizes[] = round(($r['total_size'] ?: 0) / 1024 / 1024, 2); // MB
        $users[] = (int)($r['unique_users'] ?? 0);
    }

    $out = [
        'labels' => $labels,
        'counts' => $counts,
        'sizes' => $sizes,
        'users' => $users,
        '_params' => [ 'from' => $from, 'to' => $to, 'period' => $period ]
    ];

    echo json_encode($out);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'internal_error', 'message' => $e->getMessage()]);
}

?>
