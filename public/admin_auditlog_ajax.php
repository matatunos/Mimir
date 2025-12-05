<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();
header('Content-Type: application/json');
$page = isset($_GET['page']) ? max(0, intval($_GET['page'])) : 0;
$filter = $_GET['filter'] ?? '';
$limit = 20;
$offset = $page * $limit;
$filters = [];
if ($filter) {
    $filters['search'] = $filter;
}
$db = Database::getInstance()->getConnection();
$where = [];
$params = [];
if (!empty($filters['search'])) {
    $where[] = "(a.action LIKE ? OR a.entity_type LIKE ? OR a.details LIKE ? OR u.username LIKE ? OR a.ip_address LIKE ?)";
    for ($i=0;$i<5;$i++) $params[] = "%{$filters['search']}%";
}
$whereClause = $where ? 'WHERE '.implode(' AND ', $where) : '';
$sql = "SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id $whereClause ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();
$sqlCount = "SELECT COUNT(*) as total FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id $whereClause";
$stmtCount = $db->prepare($sqlCount);
$stmtCount->execute(array_slice($params,0,count($params)-2));
$total = $stmtCount->fetch()['total'] ?? 0;
$pages = ceil($total/$limit);
// Formatear fechas
foreach($logs as &$log){
    $log['created_at'] = date('d/m/Y H:i', strtotime($log['created_at']));
}
echo json_encode([
    'logs'=>$logs,
    'page'=>$page,
    'pages'=>$pages
]);
