<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireAdmin();

$logger = new Logger();

$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$action = $_GET['action'] ?? '';

$filters = [
    'date_from' => $dateFrom . ' 00:00:00',
    'date_to' => $dateTo . ' 23:59:59'
];
if ($action !== '') $filters['action'] = $action;

// Request a large limit; if huge exports are required the caller should page them.
$limit = 100000;
$offset = 0;

$logs = $logger->getActivityLogs($filters, $limit, $offset);

// Stream CSV
$filename = sprintf('activity_%s_%s.xlsx', preg_replace('/[^0-9A-Za-z_-]/','', $dateFrom), preg_replace('/[^0-9A-Za-z_-]/','', $dateTo));
$csvFilename = str_replace('.xlsx', '.csv', $filename);

// Helper: escape XML text
function xmlEscape($s) {
    return htmlspecialchars((string)$s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

// Prepare rows: header + data
$rows = [];
$header = ['created_at','username','action','entity_type','entity_id','description','ip_address','user_agent'];
$rows[] = $header;
foreach ($logs as $row) {
    $rows[] = [
        $row['created_at'] ?? '',
        $row['username'] ?? '',
        $row['action'] ?? '',
        $row['entity_type'] ?? '',
        $row['entity_id'] ?? '',
        $row['description'] ?? '',
        $row['ip_address'] ?? '',
        $row['user_agent'] ?? ''
    ];
}

// Stream CSV directly
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $csvFilename . '"');
$out = fopen('php://output', 'w');
// UTF-8 BOM for Excel compatibility
fwrite($out, "\xEF\xBB\xBF");
foreach ($rows as $r) {
    fputcsv($out, $r);
}
fclose($out);
exit;
