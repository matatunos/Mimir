<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance()->getConnection();

// Get date range from request
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Build query
$where = ["download_started_at >= ? AND download_started_at <= ?"];
$params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

if (!empty($_GET['ip'])) {
    $where[] = "ip_address LIKE ?";
    $params[] = "%" . $_GET['ip'] . "%";
}

if (!empty($_GET['user_id'])) {
    $where[] = "user_id = ?";
    $params[] = intval($_GET['user_id']);
}

$whereClause = implode(' AND ', $where);

// Get download logs
$stmt = $db->prepare("
    SELECT 
        dl.*,
        f.original_name,
        f.mime_type,
        f.file_size,
        u.username,
        u.full_name,
        s.share_token
    FROM download_log dl
    LEFT JOIN files f ON dl.file_id = f.id
    LEFT JOIN users u ON dl.user_id = u.id
    LEFT JOIN shares s ON dl.share_id = s.id
    WHERE $whereClause
    ORDER BY dl.download_started_at DESC
    LIMIT 50000
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="forensic_log_' . $dateFrom . '_to_' . $dateTo . '.xls"');
header('Cache-Control: max-age=0');

// Output Excel content
echo "\xEF\xBB\xBF"; // UTF-8 BOM
echo "<html xmlns:x=\"urn:schemas-microsoft-com:office:excel\">\n";
echo "<head>\n";
echo "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\">\n";
echo "<xml>\n";
echo "<x:ExcelWorkbook>\n";
echo "<x:ExcelWorksheets>\n";
echo "<x:ExcelWorksheet>\n";
echo "<x:Name>Análisis Forense</x:Name>\n";
echo "<x:WorksheetOptions>\n";
echo "<x:Print><x:ValidPrinterInfo/></x:Print>\n";
echo "</x:WorksheetOptions>\n";
echo "</x:ExcelWorksheet>\n";
echo "</x:ExcelWorksheets>\n";
echo "</x:ExcelWorkbook>\n";
echo "</xml>\n";
echo "</head>\n";
echo "<body>\n";
echo "<table border='1'>\n";
echo "<thead>\n";
echo "<tr style='background-color: #2196F3; color: white; font-weight: bold;'>\n";
echo "<th>ID</th>\n";
echo "<th>Archivo</th>\n";
echo "<th>Usuario</th>\n";
echo "<th>Nombre Completo</th>\n";
echo "<th>Dirección IP</th>\n";
echo "<th>País</th>\n";
echo "<th>Ciudad</th>\n";
echo "<th>Navegador</th>\n";
echo "<th>SO</th>\n";
echo "<th>Dispositivo</th>\n";
echo "<th>Es Bot</th>\n";
echo "<th>Compartido</th>\n";
echo "<th>Token</th>\n";
echo "<th>Tamaño (bytes)</th>\n";
echo "<th>Descarga Completa</th>\n";
echo "<th>Bytes Transferidos</th>\n";
echo "<th>" . htmlspecialchars(t('download_start')) . "</th>\n";
echo "<th>Fin Descarga</th>\n";
echo "<th>Duración (segundos)</th>\n";
echo "<th>User Agent</th>\n";
echo "</tr>\n";
echo "</thead>\n";
echo "<tbody>\n";

foreach ($logs as $log) {
    $downloadComplete = $log['download_completed_at'] ? 'Sí' : 'No';
    $isBot = $log['is_bot'] ? 'Sí' : 'No';
    $isShared = $log['share_id'] ? 'Sí' : 'No';
    
    $duration = '';
    if ($log['download_started_at'] && $log['download_completed_at']) {
        $start = strtotime($log['download_started_at']);
        $end = strtotime($log['download_completed_at']);
        $duration = $end - $start;
    }
    
    echo "<tr>\n";
    echo "<td>" . htmlspecialchars($log['id'] ?? '') . "</td>\n";
    echo "<td>" . htmlspecialchars($log['original_name'] ?? '') . "</td>\n";
    echo "<td>" . htmlspecialchars($log['username'] ?? 'Anónimo') . "</td>\n";
    echo "<td>" . htmlspecialchars($log['full_name'] ?? '') . "</td>\n";
    echo "<td>" . htmlspecialchars($log['ip_address'] ?? '') . "</td>\n";
    echo "<td>" . htmlspecialchars($log['country'] ?? '') . "</td>\n";
    echo "<td>" . htmlspecialchars($log['city'] ?? '') . "</td>\n";
    echo "<td>" . htmlspecialchars($log['browser'] ?? '') . "</td>\n";
    echo "<td>" . htmlspecialchars($log['os'] ?? '') . "</td>\n";
    echo "<td>" . htmlspecialchars($log['device_type'] ?? '') . "</td>\n";
    echo "<td>" . $isBot . "</td>\n";
    echo "<td>" . $isShared . "</td>\n";
    echo "<td>" . htmlspecialchars($log['share_token'] ?? '') . "</td>\n";
    echo "<td>" . htmlspecialchars($log['file_size'] ?? '') . "</td>\n";
    echo "<td>" . $downloadComplete . "</td>\n";
    echo "<td>" . htmlspecialchars($log['bytes_transferred'] ?? '') . "</td>\n";
    echo "<td>" . htmlspecialchars($log['download_started_at'] ?? '') . "</td>\n";
    echo "<td>" . htmlspecialchars($log['download_completed_at'] ?? '') . "</td>\n";
    echo "<td>" . htmlspecialchars($duration) . "</td>\n";
    echo "<td>" . htmlspecialchars($log['user_agent'] ?? '') . "</td>\n";
    echo "</tr>\n";
}

echo "</tbody>\n";
echo "</table>\n";
echo "</body>\n";
echo "</html>\n";
exit;
