<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/File.php';
require_once __DIR__ . '/../../classes/Share.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/Config.php';

// Prevent aggressive caching of the admin dashboard during development
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Expires: 0');
header('Pragma: no-cache');

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();

$userClass = new User();
$fileClass = new File();
$shareClass = new Share();
$logger = new Logger();
$config = new Config();

// Get system statistics
$db = Database::getInstance()->getConnection();

$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
$totalFiles = $db->query("SELECT COUNT(*) FROM files")->fetchColumn();
$totalShares = $db->query("SELECT COUNT(*) FROM shares WHERE is_active = 1")->fetchColumn();
$totalStorage = $db->query("SELECT COALESCE(SUM(file_size), 0) FROM files")->fetchColumn();
$storageGB = $totalStorage / 1024 / 1024 / 1024;
// Disk usage for uploads path (shown in dashboard below 'Actividad de Subidas')
$uploadsPath = defined('UPLOADS_PATH') ? UPLOADS_PATH : (dirname(__DIR__,2) . '/storage/uploads');
$diskTotal = @disk_total_space($uploadsPath) ?: 0;
$diskFree = @disk_free_space($uploadsPath) ?: 0;
$diskUsed = max(0, $diskTotal - $diskFree);
$diskPercent = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0;
$diskTotalGB = round($diskTotal / 1024 / 1024 / 1024, 2);
$diskUsedGB = round($diskUsed / 1024 / 1024 / 1024, 2);
// Visible chart capacity in GB (override via config; default 27.8 GB)
$diskCapacityGB = $config->get('disk_capacity_gb', 27.8);

// Ensure metrics table exists and record a snapshot if last snapshot is older than 5 minutes
try {
    $db->exec("CREATE TABLE IF NOT EXISTS disk_usage_metrics (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        total_bytes BIGINT UNSIGNED NOT NULL,
        free_bytes BIGINT UNSIGNED NOT NULL,
        used_bytes BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        INDEX (recorded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $last = $db->query("SELECT recorded_at FROM disk_usage_metrics ORDER BY recorded_at DESC LIMIT 1")->fetchColumn();
    $needInsert = true;
    if ($last) {
        $ts = strtotime($last);
        if ($ts !== false && (time() - $ts) < 300) { // 5 minutes
            $needInsert = false;
        }
    }
    if ($needInsert) {
        $stmt = $db->prepare('INSERT INTO disk_usage_metrics (recorded_at, total_bytes, free_bytes, used_bytes) VALUES (NOW(), ?, ?, ?)');
        $stmt->execute([(int)$diskTotal, (int)$diskFree, (int)$diskUsed]);
    }

    // Disk usage range filter: default to last 7 days
    $disk_from = isset($_GET['disk_from']) ? $_GET['disk_from'] : null;
    $disk_to = isset($_GET['disk_to']) ? $_GET['disk_to'] : null;
    // Default period is 7 days, but only apply it when the user did not
    // provide explicit `disk_from`/`disk_to` values (so filters from the UI work).
    $disk_period = isset($_GET['disk_period']) ? (int)$_GET['disk_period'] : 7;
    if (empty($disk_from) && empty($disk_to) && $disk_period && $disk_period > 0) {
        // set from to disk_period days ago (inclusive)
        $disk_from = date('Y-m-d', strtotime("-" . ($disk_period - 1) . " days"));
        $disk_to = date('Y-m-d');
    }

    // mark if the current disk range corresponds to the 'last week' shortcut
    $diskIsLastWeek = ((isset($disk_from) && isset($disk_to) && $disk_from === date('Y-m-d', strtotime('-6 days')) && $disk_to === date('Y-m-d')) || (isset($disk_period) && $disk_period === 7));

    if ($disk_from && $disk_to) {
        // Normalize to full day range
        $fromDt = $disk_from . ' 00:00:00';
        $toDt = $disk_to . ' 23:59:59';
        $stmt = $db->prepare("SELECT recorded_at, used_bytes, total_bytes FROM disk_usage_metrics WHERE recorded_at BETWEEN ? AND ? ORDER BY recorded_at ASC");
        $stmt->execute([$fromDt, $toDt]);
        $rows = $stmt->fetchAll();
    } else {
        // Read recent points (limit to 168 entries)
        $rows = $db->query("SELECT recorded_at, used_bytes, total_bytes FROM disk_usage_metrics ORDER BY recorded_at ASC LIMIT 168")->fetchAll();
    }
} catch (Exception $e) {
    $rows = [];
}

// Prepare arrays for Chart.js (labels, usedGB, totalGB)
$diskLabels = [];
$diskUsedSeries = [];
$diskTotalSeries = [];
foreach ($rows as $r) {
    $diskLabels[] = date('d/m H:i', strtotime($r['recorded_at']));
    $diskUsedSeries[] = round($r['used_bytes'] / 1024 / 1024 / 1024, 2);
    $diskTotalSeries[] = round($r['total_bytes'] / 1024 / 1024 / 1024, 2);
}

// Invitations stats (last 48 hours)
$invitesSent48 = 0;
$invitesAccepted48 = 0;
try {
    $invitesSent48 = (int)$db->query("SELECT COUNT(*) FROM invitations WHERE created_at >= (NOW() - INTERVAL 48 HOUR)")->fetchColumn();
    $invitesAccepted48 = (int)$db->query("SELECT COUNT(*) FROM invitations WHERE used_at IS NOT NULL AND used_at >= (NOW() - INTERVAL 48 HOUR)")->fetchColumn();
} catch (Exception $e) {
    // invitations table may not exist yet on older installs
    $invitesSent48 = 0;
    $invitesAccepted48 = 0;
}

// Advanced statistics
// Get period from query parameter (default 7 days)
$period = isset($_GET['period']) ? (int)$_GET['period'] : 7;
$allowedPeriods = [7]; // only last week fixed shortcut
if (!in_array($period, $allowedPeriods)) {
    $period = 30;
}

$fromDate = $_GET['from'] ?? null;
$toDate = $_GET['to'] ?? null;

$customRange = false;
if ($fromDate && $toDate) {
    $d1 = DateTime::createFromFormat('Y-m-d', $fromDate);
    $d2 = DateTime::createFromFormat('Y-m-d', $toDate);
    if ($d1 && $d2 && $d1 <= $d2) {
        $customRange = true;
        $dailyUploads = $userClass->getSystemUploadsBetween($fromDate, $toDate);
        // compute effective period in days
        $period = (int)$d1->diff($d2)->days + 1;
    } else {
        $dailyUploads = $userClass->getSystemDailyUploads($period);
    }
} else {
    $dailyUploads = $userClass->getSystemDailyUploads($period);
}

// If no explicit from/to provided, default the calendar inputs to last week
if (empty($fromDate) && empty($toDate)) {
    $fromDate = date('Y-m-d', strtotime("-" . ($period - 1) . " days"));
    $toDate = date('Y-m-d');
}

// Determine if uploads range equals last week (for UI active state)
$lastWeekFrom = date('Y-m-d', strtotime('-' . ($period - 1) . ' days'));
$lastWeekTo = date('Y-m-d');
$uploadsIsLastWeek = ($fromDate === $lastWeekFrom && $toDate === $lastWeekTo);
$weeklyUploads = $userClass->getSystemWeeklyUploads(52); // 1 año de semanas
$activityByDayOfWeek = $userClass->getActivityByDayOfWeek($period);
$weekendVsWeekday = $userClass->getWeekendVsWeekdayStats($period);
$fileTypeDistribution = $userClass->getSystemFileTypeDistribution();
$topUsersByUploads = $userClass->getTopUsersByUploads(10);
$shareStats = $userClass->getSystemShareStats();
$mostSharedFiles = $userClass->getMostSharedFiles(10);
$storageUsageByUser = $userClass->getStorageUsageByUser();
$sortBy = $_GET['sort_by'] ?? 'last_login';
$sortDir = strtolower($_GET['sort_dir'] ?? 'desc');
$allowedSorts = ['last_login','days_inactive','username','file_count'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'last_login';
$sortDir = $sortDir === 'asc' ? 'asc' : 'desc';
$inactiveUsers = $userClass->getMostInactiveUsers(10, $sortBy, $sortDir);

// Recent activity
$recentActivity = $logger->getActivityLogs([], 10, 0);

// Operational metrics
$queuedNotifications = 0;
$failedNotifications = 0;
$processingNotifications = 0;
$smtpFailures24 = 0;
$failedLogins24 = 0;
$topFailedIps = [];
$orphanFilesCount = 0;
$workerCount = 0;
try {
    // Notification job counts (if table exists)
    $queuedNotifications = (int)$db->query("SELECT COUNT(*) FROM notification_jobs WHERE status = 'pending'")->fetchColumn();
    $failedNotifications = (int)$db->query("SELECT COUNT(*) FROM notification_jobs WHERE status = 'failed'")->fetchColumn();
    $processingNotifications = (int)$db->query("SELECT COUNT(*) FROM notification_jobs WHERE status = 'processing'")->fetchColumn();
} catch (Exception $e) {
    // Table may not exist on older installs
}

// Count SMTP failures in last 24 hours by parsing smtp_debug.log (best-effort)
$smtpLogPath = __DIR__ . '/../../storage/logs/smtp_debug.log';
if (file_exists($smtpLogPath) && is_readable($smtpLogPath)) {
    $fp = fopen($smtpLogPath, 'r');
    if ($fp) {
        while (($line = fgets($fp)) !== false) {
            // Look for '535' response within ISO timestamped lines
            if (strpos($line, '535') !== false) {
                // Try to extract ISO timestamp at start
                if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $line, $m)) {
                    $ts = strtotime($m[1]);
                    if ($ts !== false && $ts >= time() - 86400) {
                        $smtpFailures24++;
                    }
                } else {
                    // If no timestamp, count conservatively
                    $smtpFailures24++;
                }
            }
        }
        fclose($fp);
    }
}

try {
    // Failed login attempts in last 24h
    $failedLogins24 = (int)$db->query("SELECT COUNT(*) FROM security_events WHERE event_type = 'failed_login' AND created_at >= (NOW() - INTERVAL 24 HOUR)")->fetchColumn();

    // Top failed login IPs
    $stmt = $db->prepare("SELECT ip_address, COUNT(*) as attempts FROM security_events WHERE event_type = 'failed_login' AND created_at >= (NOW() - INTERVAL 24 HOUR) GROUP BY ip_address ORDER BY attempts DESC LIMIT 5");
    $stmt->execute();
    $topFailedIps = $stmt->fetchAll();
} catch (Exception $e) {
    // security_events may not exist
}

try {
    $orphanFilesCount = (int)$fileClass->countOrphans();
} catch (Exception $e) {
    $orphanFilesCount = 0;
}

// Count running notification_worker processes (best-effort via shell)
$workerCount = 0;
$proc = trim(shell_exec("pgrep -af notification_worker.php | wc -l 2>/dev/null"));
if (is_numeric($proc)) $workerCount = (int)$proc;


// Top users by storage
$topUsers = $db->query("
    SELECT u.username, u.full_name, 
           COALESCE(SUM(f.file_size), 0) as total_storage,
           COUNT(f.id) as file_count
    FROM users u
    LEFT JOIN files f ON u.id = f.user_id
    WHERE u.role = 'user'
    GROUP BY u.id
    ORDER BY total_storage DESC
    LIMIT 5
")->fetchAll();

// Prepare data for Chart.js
$dailyLabels = [];
$dailyCounts = [];
$dailySizes = [];
$dailyUsers = [];

// If custom date range selected, build labels between dates, otherwise use period
if ($customRange) {
    $dateFormat = 'd/m';
    if ($period > 90) $dateFormat = 'M y';

    $start = new DateTime($fromDate);
    $end = new DateTime($toDate);
    $interval = new DateInterval('P1D');
    $periodIter = new DatePeriod($start, $interval, $end->modify('+1 day'));
    foreach ($periodIter as $dt) {
        $d = $dt->format('Y-m-d');
        $dailyLabels[] = $dt->format($dateFormat);
        $dailyCounts[$d] = 0;
        $dailySizes[$d] = 0;
        $dailyUsers[$d] = 0;
    }

    foreach ($dailyUploads as $upload) {
        $dailyCounts[$upload['date']] = (int)$upload['count'];
        $dailySizes[$upload['date']] = (float)$upload['total_size'];
        $dailyUsers[$upload['date']] = (int)$upload['unique_users'];
    }
} else {
    // Date format depends on period
    $dateFormat = 'd/m';
    if ($period > 90) {
        $dateFormat = 'M y'; // Short month + year for long periods
    }

    for ($i = $period - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dailyLabels[] = date($dateFormat, strtotime($date));
        $dailyCounts[$date] = 0;
        $dailySizes[$date] = 0;
        $dailyUsers[$date] = 0;
    }

    foreach ($dailyUploads as $upload) {
        $dailyCounts[$upload['date']] = (int)$upload['count'];
        $dailySizes[$upload['date']] = (float)$upload['total_size'];
        $dailyUsers[$upload['date']] = (int)$upload['unique_users'];
    }
}
$dailyCounts = [];
$dailySizes = [];
$dailyUsers = [];

// Totals for the last week (7 days)
$lastWeekUploadsRaw = $userClass->getSystemDailyUploads(7);
$lastWeekCount = 0;
$lastWeekSize = 0;
$lastWeekUsers = 0;
foreach ($lastWeekUploadsRaw as $up) {
    $lastWeekCount += (int)$up['count'];
    $lastWeekSize += (float)$up['total_size'];
    $lastWeekUsers += (int)$up['unique_users'];
}

// Date format depends on period
$dateFormat = 'd/m';
if ($period > 90) {
    $dateFormat = 'M y'; // Short month + year for long periods
}

for ($i = $period - 1; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dailyLabels[] = date($dateFormat, strtotime($date));
    $dailyCounts[$date] = 0;
    $dailySizes[$date] = 0;
    $dailyUsers[$date] = 0;
}

foreach ($dailyUploads as $upload) {
    $dailyCounts[$upload['date']] = (int)$upload['count'];
    $dailySizes[$upload['date']] = (float)$upload['total_size'];
    $dailyUsers[$upload['date']] = (int)$upload['unique_users'];
}

$dailyCountsValues = array_values($dailyCounts);
$dailySizesValues = array_map(function($size) {
    return round($size / 1024 / 1024, 2); // MB
}, array_values($dailySizes));
$dailyUsersValues = array_values($dailyUsers);

// precompute maxima for initial chart scaling (avoid zero max)
$chartMaxCount = max($dailyCountsValues) ?: 1;
$chartMaxSize = max($dailySizesValues) ?: 1;
$chartMaxUsers = max($dailyUsersValues) ?: 1;

// Current period totals for uploads chart (used in right-side summary)
$currentUploadsTotal = array_sum($dailyCountsValues);
$currentVolumeMBTotal = array_sum($dailySizesValues);
$currentActiveUsersMax = count($dailyUsersValues) ? max($dailyUsersValues) : 0;

// Weekly data
$weeklyLabels = [];
$weeklyCounts = [];
$weeklySizes = [];
foreach ($weeklyUploads as $week) {
    $weeklyLabels[] = date('d/m', strtotime($week['week_start']));
    $weeklyCounts[] = (int)$week['count'];
    $weeklySizes[] = round($week['total_size'] / 1024 / 1024, 2);
}

// Day of week data
$dayOfWeekLabels = [];
$dayOfWeekCounts = [];
$dayOfWeekColors = [];
foreach ($activityByDayOfWeek as $day) {
    $dayOfWeekLabels[] = $day['day_name'];
    $dayOfWeekCounts[] = (int)$day['total_files'];
    // Weekend colors
    $isWeekend = in_array($day['day_of_week'], [5, 6]); // Sábado, Domingo
    $dayOfWeekColors[] = $isWeekend ? '#E24A90' : '#4A90E2';
}

// File type data
$typeLabels = [];
$typeCounts = [];
$typeColors = [
    'Imágenes' => '#4A90E2',
    'Vídeos' => '#E24A90',
    'Audio' => '#90E24A',
    'PDF' => '#E2904A',
    'Comprimidos' => '#904AE2',
    'Texto' => '#4AE290',
    'Otros' => '#999999'
];
$chartColors = [];

foreach ($fileTypeDistribution as $type) {
    $typeLabels[] = $type['type'];
    $typeCounts[] = (int)$type['count'];
    $chartColors[] = $typeColors[$type['type']] ?? '#999999';
}

renderPageStart('Dashboard Admin', 'dashboard', true);
renderHeader('Panel de Administración', $user, $auth);
?>
<?php
// Brand colors for admin dashboard cards
$brandPrimary = $config->get('brand_primary_color', '#667eea');
$brandSecondary = $config->get('brand_secondary_color', '#764ba2');
$brandAccent = $config->get('brand_accent_color', '#667eea');
?>

<div class="admin-dashboard">

<style>
    .admin-stat-card {
    background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    padding: 0.6rem 0.6rem;
    position: relative;
    overflow: hidden;
    transition: all 0.24s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 1px 6px rgba(0,0,0,0.06);
}
/* Brandized card headers for admin dashboard */
.admin-dashboard .card-header {
    background: linear-gradient(135deg, <?php echo htmlspecialchars($brandPrimary); ?> 0%, <?php echo htmlspecialchars($brandSecondary); ?> 100%);
    color: white;
}
    /* Shared chart header/body styles to avoid inline differences */
    .chart-card-header { display:flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; padding: 0.6rem 1rem; min-height:48px; }
    .chart-card-body { display:flex; gap:1rem; align-items:center; flex-wrap:nowrap; width:100%; padding: 1rem 1rem 1.25rem 1rem; min-height:220px; }

    /* Shared controls and form styles for chart headers */
    .period-selector { display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; }
    .period-form { display:inline-flex; gap:0.4rem; align-items:center; margin-left:0.5rem; }
    .period-label { font-size:0.85rem; color:var(--text-muted); }
    .period-input { padding:0.25rem; border-radius:4px; border:1px solid var(--border-color); }

    /* Header control grouping */
    .header-controls { display:flex; gap:0.75rem; align-items:center; }
    .header-path { color:var(--text-muted); font-size:0.95rem; margin-left:0.5rem; }

    /* Chart left/right columns (85/15) */
    .chart-left { flex: 1 1 85%; min-width:0; width:85%; }
    .chart-right { flex: 1 1 15%; min-width:0; width:15%; }
    .chart-right .big { font-size:1.25rem; font-weight:700; margin-bottom:0.25rem; }
    .chart-right .muted { color:var(--text-muted); margin-bottom:0.5rem; }
.admin-stat-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 16px 32px rgba(0,0,0,0.12);
}
.admin-stat-card::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #4a90e2, #50c878, #ffa500, #9b59b6);
    background-size: 200% 100%;
    animation: shimmer 3s infinite;
}
/* Range badge used in chart headers */
.card-header .range-badge {
    display: inline-block;
    margin-left: 0.75rem;
    padding: 0.25rem 0.5rem;
    background: rgba(0,0,0,0.06);
    color: var(--text-main);
    font-size: 0.8rem;
    border-radius: 0.375rem;
    font-weight: 600;
}
@keyframes shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
.admin-stat-icon {
    font-size: 2.4rem;
    position: absolute;
    right: 0.6rem;
    top: 50%;
    transform: translateY(-50%);
    opacity: 0.08;
    transition: all 0.3s;
}
.admin-stat-card:hover .admin-stat-icon {
    opacity: 0.15;
    transform: translateY(-50%) scale(1.15) rotate(5deg);
}
.admin-stat-value {
    font-size: 1.3rem;
    font-weight: 700;
    /* softer, less saturated gradient for stat numbers */
    background: linear-gradient(135deg, rgba(74,144,226,0.9) 0%, rgba(80,200,120,0.85) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    line-height: 1;
    margin-bottom: 0.15rem;
    letter-spacing: -0.02em;
}
.admin-stat-label {
    font-size: 0.78rem;
    color: var(--text-main);
    font-weight: 600;
    margin-bottom: 0.25rem;
}
.admin-stat-sublabel {
    font-size: 0.68rem;
    color: var(--text-muted);
    font-weight: 500;
}
/* Period buttons moved to global CSS for consistent contrast */
</style>

<div class="content">
    <style>
    /* Responsive admin stats grid: prefer 4 columns on wide screens, down to 1 on small */
    .admin-stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.25rem; grid-auto-flow: dense; }
    @media (max-width: 1200px) { .admin-stats-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 900px) { .admin-stats-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 600px) { .admin-stats-grid { grid-template-columns: 1fr; } }

    /* Operational overview grid */
    .admin-operational-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem; grid-auto-flow: dense; }
    @media (max-width: 900px) { .admin-operational-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 600px) { .admin-operational-grid { grid-template-columns: 1fr; } }

    /* Charts grid: make uploads chart span full width */
    .charts-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem; margin-bottom: 2rem; }
    @media (max-width: 900px) { .charts-grid { grid-template-columns: 1fr; } }
    .charts-grid .uploads-chart-card { grid-column: 1 / -1; }
    .charts-grid .disk-usage-card { grid-column: 1 / -1; }
    /* unify card visuals for uploads and disk charts */
    .uploads-chart-card .card-header, .disk-usage-card .card-header { padding: 0.6rem 1rem; }
    .uploads-chart-card .card-body, .disk-usage-card .card-body { padding: 1rem 1rem 1.25rem 1rem; display:flex; gap:1rem; align-items:center; }
    .uploads-chart-card canvas, .disk-usage-card canvas { width: 100%; height: 280px !important; display:block; }
    /* Other charts: slightly smaller and responsive */
    .filetypes-chart-card canvas, #weeklyUploadsChart, #dayOfWeekChart { width: 100%; height: 260px !important; display:block; }
    @media (max-width: 1000px) {
        .uploads-chart-card canvas, .disk-usage-card canvas { height: 220px !important; }
        .filetypes-chart-card canvas, #weeklyUploadsChart, #dayOfWeekChart { height: 200px !important; }
    }
    /* Chart cards styling */
    .uploads-chart-card, .disk-usage-card { border-radius: 6px; }
    .charts-grid .filetypes-chart-card { grid-column: 1 / -1; }
    /* Layout for filetypes chart with side legend/table */
    .filetypes-chart-card .filetypes-chart-inner { display: grid; grid-template-columns: 1fr minmax(220px, 300px); gap: 1rem; align-items: start; }
    @media (max-width: 1000px) { .filetypes-chart-card .filetypes-chart-inner { grid-template-columns: 1fr; } }
    .filetypes-chart-card table { width: 100%; table-layout: fixed; }
    .filetypes-chart-card td, .filetypes-chart-card th { word-break: break-word; }
    </style>

    <div class="admin-stats-grid">
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Usuarios Totales</div>
                <div class="admin-stat-value"><?php echo $totalUsers; ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-check"></i> <?php echo $activeUsers; ?> activos</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-users"></i></div>
        </div>
        
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Archivos Totales</div>
                <div class="admin-stat-value"><?php echo $totalFiles; ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-save"></i> <?php echo number_format($storageGB, 2); ?> GB</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-folder"></i></div>
        </div>
        
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Comparticiones</div>
                <div class="admin-stat-value"><?php echo $totalShares; ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-circle" style="color:#16a34a; font-size:0.85rem; vertical-align:middle; margin-right:0.4rem;"></i> Activas</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-link"></i></div>
        </div>
        
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Subidas (Última semana)</div>
                <div class="admin-stat-value"><?php echo number_format($lastWeekCount); ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-database"></i> <?php echo number_format(round($lastWeekSize / 1024 / 1024, 2)); ?> MB</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-cloud-upload-alt"></i></div>
        </div>
        
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Almacenamiento</div>
                <div class="admin-stat-value"><?php echo number_format($storageGB, 1); ?></div>
                <div class="admin-stat-sublabel">GB utilizados</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-save"></i></div>
        </div>
        
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Total Descargas</div>
                <div class="admin-stat-value"><?php echo $shareStats['total_downloads'] ?? 0; ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-chart-line"></i> <?php echo round($shareStats['avg_downloads_per_share'] ?? 0, 1); ?> promedio</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-download"></i></div>
        </div>
        
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Usuarios Compartiendo</div>
                <div class="admin-stat-value"><?php echo $shareStats['users_sharing'] ?? 0; ?></div>
                <div class="admin-stat-sublabel">de <?php echo $activeUsers; ?> activos</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-user-friends"></i></div>
        </div>

        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Invitaciones (48h)</div>
                <div class="admin-stat-value"><?php echo (int)$invitesSent48; ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-envelope-open-text"></i> <?php echo (int)$invitesAccepted48; ?> aceptadas</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-user-plus"></i></div>
        </div>
    </div>
    
    <!-- Operational Overview -->
    <div class="admin-operational-grid">
        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Cola de Notificaciones</div>
                <div class="admin-stat-value"><?php echo number_format($queuedNotifications); ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-hourglass-start"></i> En cola</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-bell"></i></div>
        </div>

        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Notificaciones Fallidas (24h)</div>
                <div class="admin-stat-value"><?php echo number_format($failedNotifications); ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-exclamation-triangle"></i> Intentos fallidos</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-bell-slash"></i></div>
        </div>

        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Errores SMTP (24h)</div>
                <div class="admin-stat-value"><?php echo number_format($smtpFailures24); ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-envelope-open-text"></i> Autenticación</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-envelope"></i></div>
        </div>

        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Intentos Login Fallidos (24h)</div>
                <div class="admin-stat-value"><?php echo number_format($failedLogins24); ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-user-lock"></i> Seguridad</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-user-shield"></i></div>
        </div>

        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Archivos Huérfanos</div>
                <div class="admin-stat-value"><?php echo number_format($orphanFilesCount); ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-question-circle"></i> Revisar</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-file-alt"></i></div>
        </div>

        <div class="admin-stat-card">
            <div style="position: relative; z-index: 1;">
                <div class="admin-stat-label">Workers</div>
                <div class="admin-stat-value"><?php echo number_format($workerCount); ?></div>
                <div class="admin-stat-sublabel"><i class="fas fa-play-circle"></i> notification_worker</div>
            </div>
            <div class="admin-stat-icon"><i class="fas fa-server"></i></div>
        </div>
    </div>

    <!-- Gráficos -->
    <div class="charts-grid">
        <div class="card uploads-chart-card">
            <div class="card-header chart-card-header">
                <h3 class="card-title"><i class="fas fa-chart-area"></i> Actividad de Subidas</h3>
                <?php if (!empty($uploadsIsLastWeek)): ?>
                    <span class="range-badge" id="uploads-range-badge">Mostrando: Últ. semana</span>
                <?php else: ?>
                    <span class="range-badge" id="uploads-range-badge" style="display:none;">Mostrando: Últ. semana</span>
                <?php endif; ?>
                <div class="period-selector">
                    <button type="button" class="period-btn <?php echo ($uploadsIsLastWeek || $period === 7) ? 'active' : ''; ?>" data-period="7">Últ. semana</button>
                    <form method="GET" action="" class="period-form">
                        <label class="period-label">Desde</label>
                        <input type="date" name="from" value="<?php echo htmlspecialchars($fromDate ?? ''); ?>" class="period-input">
                        <label class="period-label">Hasta</label>
                        <input type="date" name="to" value="<?php echo htmlspecialchars($toDate ?? ''); ?>" class="period-input">
                        <button type="submit" class="btn btn-outline" style="padding:0.25rem 0.5rem;">Aplicar</button>
                    </form>
                </div>
            </div>
            <div class="card-body chart-card-body">
                <div class="chart-left">
                    <div style="width:100%; height:100%;">
                        <canvas id="systemUploadsChart" height="360"></canvas>
                    </div>
                </div>
                <div class="chart-right">
                    <div id="uploads-total-files" class="big">
                        <?php echo number_format($currentUploadsTotal); ?> archivos
                    </div>
                    <div id="uploads-total-size" class="muted">
                        <?php echo round($currentVolumeMBTotal,2); ?> MB
                    </div>
                    <div id="uploads-active-users" class="muted">Usuarios activos (máx): <?php echo intval($currentActiveUsersMax); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Uso de Disco: formato similar a 'Actividad de Subidas' -->
        <div class="card disk-usage-card" style="width:100%;">
            <div class="card-header chart-card-header">
                <h3 class="card-title"><i class="fas fa-hdd"></i> Uso de Disco</h3>
                <?php if (!empty($diskIsLastWeek)): ?>
                    <span class="range-badge" id="disk-range-badge">Mostrando: Últ. semana</span>
                <?php else: ?>
                    <span class="range-badge" id="disk-range-badge" style="display:none;">Mostrando: Últ. semana</span>
                <?php endif; ?>
                <div class="header-controls">
                    <div class="period-selector">
                        <button type="button" class="disk-period-btn <?php echo ((isset($disk_from) && isset($disk_to) && $disk_from === date('Y-m-d', strtotime('-6 days')) && $disk_to === date('Y-m-d')) || (isset($disk_period) && $disk_period === 7)) ? 'active' : ''; ?>" data-disk-period="7">Últ. semana</button>
                        <form method="GET" action="" class="period-form">
                            <label class="period-label">Desde</label>
                            <input type="date" name="disk_from" value="<?php echo htmlspecialchars($disk_from ?? ''); ?>" class="period-input">
                            <label class="period-label">Hasta</label>
                            <input type="date" name="disk_to" value="<?php echo htmlspecialchars($disk_to ?? ''); ?>" class="period-input">
                            <button type="submit" class="btn btn-outline" style="padding:0.25rem 0.5rem;">Aplicar</button>
                        </form>
                    </div>
                    <div class="header-path">Ruta: <?php echo htmlspecialchars($uploadsPath); ?></div>
                </div>
            </div>
            <div class="card-body chart-card-body">
                <div class="chart-left">
                    <div style="width:100%; height:100%;">
                        <canvas id="diskUsageChart" height="360"></canvas>
                    </div>
                </div>
                <div class="chart-right">
                        <div id="disk-percent" class="big"><?php echo $diskPercent; ?>% ocupado</div>
                        <div id="disk-used-total" class="muted"><?php echo $diskUsedGB; ?> GB ocupados de <?php echo $diskTotalGB; ?> GB</div>
                    <div style="margin-top:0.5rem;">Leyenda: <span style="display:inline-block;width:12px;height:12px;background:#4a90e2;margin-right:6px;border-radius:2px;"></span> Usado &nbsp; <span style="display:inline-block;width:12px;height:12px;background:#e6e6e6;margin:0 6px;border-radius:2px;"></span> Libre</div>
                </div>
            </div>
        </div>
        
        <div class="card filetypes-chart-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-pie"></i> Distribución de Archivos</h3>
            </div>
            <div class="card-body">
                <div class="filetypes-chart-inner">
                    <div style="min-height:300px;">
                        <canvas id="fileTypesChart" height="300" style="max-height:420px; width:100%;"></canvas>
                    </div>
                    <?php
                        // Detailed breakdown table for file types (acts as legend)
                        $typeTotal = array_sum($typeCounts);
                    ?>
                    <div style="max-height:420px; overflow:auto;">
                        <table class="table table-sm" style="width:100%; border-collapse: collapse; margin:0;">
                            <thead>
                                <tr>
                                    <th style="text-align:left; padding:0.5rem;">Tipo</th>
                                    <th style="text-align:right; padding:0.5rem;">Cantidad</th>
                                    <th style="text-align:right; padding:0.5rem;">%</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php for ($i = 0; $i < count($typeLabels); $i++):
                                $label = $typeLabels[$i];
                                $count = $typeCounts[$i] ?? 0;
                                $percent = $typeTotal ? round(($count / $typeTotal) * 100, 1) : 0;
                                $color = $chartColors[$i] ?? '#999999';
                            ?>
                                <tr>
                                    <td style="padding:0.5rem;">
                                        <span style="display:inline-block; width:12px; height:12px; background:<?php echo htmlspecialchars($color); ?>; margin-right:0.5rem; vertical-align:middle; border-radius:2px;"></span>
                                        <?php echo htmlspecialchars($label); ?>
                                    </td>
                                    <td style="padding:0.5rem; text-align:right;"><?php echo number_format($count); ?></td>
                                    <td style="padding:0.5rem; text-align:right;"><?php echo $percent; ?>%</td>
                                </tr>
                            <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Nuevas gráficas de análisis temporal -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-calendar-week"></i> Actividad por Semana (Último Año)</h3>
            </div>
            <div class="card-body">
                <canvas id="weeklyUploadsChart" height="300"></canvas>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-calendar-day"></i> Actividad por Día de la Semana</h3>
            </div>
            <div class="card-body">
                <canvas id="dayOfWeekChart" height="300"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Comparativa fin de semana vs entre semana -->
    <div class="card" style="margin-bottom: 2rem;">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-chart-bar"></i> Comparativa: Fin de Semana vs Entre Semana</h3>
        </div>
        <div class="card-body">
            <?php if (!empty($weekendVsWeekday)): ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
                    <?php foreach ($weekendVsWeekday as $stat): ?>
                    <div style="text-align: center; padding: 2rem; background: var(--bg-secondary); border-radius: 1rem; border: 2px solid <?php echo $stat['period_type'] === 'Fin de Semana' ? '#E24A90' : '#4A90E2'; ?>;">
                        <h3 style="font-size: 1.5rem; color: <?php echo $stat['period_type'] === 'Fin de Semana' ? '#E24A90' : '#4A90E2'; ?>; margin-bottom: 1rem;">
                            <?php if ($stat['period_type'] === 'Fin de Semana'): ?>
                                🎉 <?php echo $stat['period_type']; ?>
                            <?php else: ?>
                                💼 <?php echo $stat['period_type']; ?>
                            <?php endif; ?>
                        </h3>
                        <div style="display: grid; gap: 1rem; text-align: left;">
                            <div style="display: flex; justify-content: space-between; padding: 0.75rem; background: var(--bg-main); border-radius: 0.5rem;">
                                <span style="font-weight: 600;">Total Archivos:</span>
                                <span style="color: var(--primary); font-weight: 700; font-size: 1.25rem;"><?php echo number_format($stat['total_files']); ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 0.75rem; background: var(--bg-main); border-radius: 0.5rem;">
                                <span style="font-weight: 600;">Promedio por Día:</span>
                                <span style="color: var(--success); font-weight: 700; font-size: 1.25rem;"><?php echo round($stat['avg_files_per_day'], 1); ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 0.75rem; background: var(--bg-main); border-radius: 0.5rem;">
                                <span style="font-weight: 600;">Volumen Total:</span>
                                <span style="color: var(--warning); font-weight: 700;"><?php echo number_format($stat['total_size'] / 1024 / 1024 / 1024, 2); ?> GB</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 0.75rem; background: var(--bg-main); border-radius: 0.5rem;">
                                <span style="font-weight: 600;">Usuarios Únicos:</span>
                                <span style="font-weight: 700;"><?php echo $stat['unique_users']; ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <?php 
                // Calculate comparison
                $weekend = null;
                $weekday = null;
                foreach ($weekendVsWeekday as $stat) {
                    if ($stat['period_type'] === 'Fin de Semana') {
                        $weekend = $stat;
                    } else {
                        $weekday = $stat;
                    }
                }
                if ($weekend && $weekday):
                    $weekendAvg = $weekend['avg_files_per_day'];
                    $weekdayAvg = $weekday['avg_files_per_day'];
                    $difference = (($weekendAvg - $weekdayAvg) / $weekdayAvg) * 100;
                ?>
                <div style="margin-top: 2rem; padding: 1.5rem; background: linear-gradient(135deg, rgba(74, 144, 226, 0.1), rgba(226, 74, 144, 0.1)); border-radius: 1rem; text-align: center;">
                    <p style="font-size: 1.125rem; font-weight: 600; margin-bottom: 0.5rem;">
                        <?php if ($difference > 0): ?>
                            📈 Los fines de semana tienen un <span style="color: #E24A90; font-size: 1.5rem;"><?php echo abs(round($difference, 1)); ?>%</span> MÁS actividad que entre semana
                        <?php elseif ($difference < 0): ?>
                            📉 Los fines de semana tienen un <span style="color: #4A90E2; font-size: 1.5rem;"><?php echo abs(round($difference, 1)); ?>%</span> MENOS actividad que entre semana
                        <?php else: ?>
                            ⚖️ La actividad es similar entre semana y fin de semana
                        <?php endif; ?>
                    </p>
                    <p style="color: var(--text-muted); font-size: 0.9375rem;">
                        Promedio diario: <?php echo round($weekendAvg, 1); ?> archivos (fin de semana) vs <?php echo round($weekdayAvg, 1); ?> archivos (entre semana)
                    </p>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <p style="color: var(--text-muted); text-align: center; padding: 2rem;">Sin datos suficientes</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Tablas de estadísticas -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-trophy"></i> Top Usuarios por Subidas</h3>
            </div>
            <div class="card-body">
                <?php if (empty($topUsersByUploads)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">Sin datos</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>Archivos</th>
                                    <th>Tamaño</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topUsersByUploads as $u): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?></td>
                                    <td><span class="badge badge-primary"><?php echo $u['file_count']; ?></span></td>
                                    <td><?php echo number_format($u['total_size'] / 1024 / 1024 / 1024, 2); ?> GB</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fire"></i> Archivos Más Compartidos</h3>
            </div>
            <div class="card-body">
                <?php if (empty($mostSharedFiles)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">Sin datos</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Archivo</th>
                                    <th>Enlaces</th>
                                    <th>Descargas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mostSharedFiles as $file): ?>
                                <tr>
                                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($file['original_name']); ?>">
                                        <?php echo htmlspecialchars($file['original_name']); ?>
                                        <small style="display: block; color: var(--text-muted);"><?php echo htmlspecialchars($file['username']); ?></small>
                                    </td>
                                    <td><span class="badge badge-success"><?php echo $file['share_count']; ?></span></td>
                                    <td><strong><?php echo $file['total_downloads']; ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-database"></i> Uso de Cuota de Almacenamiento</h2>
            </div>
            <div class="card-body">
                <?php if (empty($storageUsageByUser)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">Sin datos</p>
                <?php else: ?>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php foreach ($storageUsageByUser as $u): ?>
                        <?php
                            // Normalize usage_percent to avoid nulls causing deprecated round() warnings
                            $usagePercent = isset($u['usage_percent']) ? (float)$u['usage_percent'] : 0.0;
                        ?>
                        <div style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?></div>
                                <div style="font-size: 0.875rem; color: var(--text-muted);">
                                    <?php echo number_format($u['used_storage'] / 1024 / 1024 / 1024, 2); ?> / <?php echo number_format($u['storage_quota'] / 1024 / 1024 / 1024, 1); ?> GB
                                </div>
                            </div>
                            <div style="background: var(--bg-secondary); height: 0.5rem; border-radius: 0.25rem; overflow: hidden;">
                                <div style="width: <?php echo min(100, $usagePercent); ?>%; height: 100%; background: <?php echo $usagePercent > 90 ? 'var(--danger)' : ($usagePercent > 70 ? 'var(--warning)' : 'var(--success)'); ?>; transition: width 0.3s;"></div>
                            </div>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; text-align: right;">
                                <?php echo round($usagePercent, 1); ?>%
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Ranking de Usuarios Inactivos -->
        <?php if (!empty($inactiveUsers)): ?>
        <div class="card" style="margin-bottom: 2rem;">
            <div class="card-header card-header--padded">
                <h2 class="card-title" style="margin: 0;"><i class="fas fa-user-clock"></i> Usuarios Más Inactivos</h2>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <?php
                                // Helper to build sort links and toggle direction
                                function sortLink($col, $label, $currentSort, $currentDir) {
                                    $newDir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';
                                    $qs = array_merge($_GET, ['sort_by' => $col, 'sort_dir' => $newDir]);
                                    $url = basename($_SERVER['PHP_SELF']) . '?' . http_build_query($qs);
                                    $arrow = '';
                                    if ($currentSort === $col) $arrow = $currentDir === 'asc' ? ' ▲' : ' ▼';
                                    return "<a href=\"$url\">" . htmlspecialchars($label) . htmlspecialchars($arrow) . "</a>";
                                }
                                ?>
                                <th><?php echo sortLink('username', 'Usuario', $sortBy, $sortDir); ?></th>
                                <th><?php echo sortLink('last_login', 'Último Acceso', $sortBy, $sortDir); ?></th>
                                <th><?php echo sortLink('days_inactive', 'Días Inactivo', $sortBy, $sortDir); ?></th>
                                <th><?php echo sortLink('file_count', 'Archivos', $sortBy, $sortDir); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rank = 1;
                            foreach ($inactiveUsers as $inactiveUser): 
                            ?>
                            <tr>
                                <td>
                                    <?php if ($rank <= 3): ?>
                                        <span style="font-size: 1.25rem; font-weight: bold;">
                                            <?php if ($rank === 1): ?>🥇
                                            <?php elseif ($rank === 2): ?>🥈
                                            <?php elseif ($rank === 3): ?>🥉
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);"><?php echo $rank; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($inactiveUser['full_name'] ?? $inactiveUser['username']); ?></strong>
                                    <br>
                                    <small style="color: var(--text-muted);">@<?php echo htmlspecialchars($inactiveUser['username']); ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($inactiveUser['last_login'])): ?>
                                        <?php echo date('d/m/Y H:i', strtotime($inactiveUser['last_login'])); ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">Nunca</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $days = isset($inactiveUser['days_inactive']) ? $inactiveUser['days_inactive'] : null;
                                        if ($days === null) {
                                            // Never logged in
                                            $badgeBg = 'var(--bg-secondary)';
                                            $badgeText = 'Nunca';
                                        } else {
                                            if ($days > 180) $badgeBg = 'var(--danger-color)';
                                            elseif ($days > 90) $badgeBg = '#ff8c00';
                                            elseif ($days > 30) $badgeBg = '#ffa500';
                                            else $badgeBg = 'var(--warning-color)';
                                            $badgeText = $days . ' días';
                                        }

                                        // Resolve CSS variable names to hex when possible
                                        $varMap = [
                                            'var(--brand-primary)' => $config->get('brand_primary_color', '#1e40af'),
                                            'var(--brand-secondary)' => $config->get('brand_secondary_color', '#475569'),
                                            'var(--brand-accent)' => $config->get('brand_accent_color', '#0ea5e9'),
                                            'var(--danger-color)' => '#dc2626',
                                            'var(--warning-color)' => '#d97706',
                                            'var(--bg-secondary)' => '#f1f5f9'
                                        ];

                                        // Helper functions
                                        $hexToRgbArray = function($hex) {
                                            $h = ltrim($hex, '#');
                                            return [
                                                'r' => hexdec(substr($h, 0, 2)),
                                                'g' => hexdec(substr($h, 2, 2)),
                                                'b' => hexdec(substr($h, 4, 2))
                                            ];
                                        };
                                        $readableTextColor = function($hex) use ($hexToRgbArray) {
                                            $rgb = $hexToRgbArray($hex);
                                            $brightness = ($rgb['r'] * 299 + $rgb['g'] * 587 + $rgb['b'] * 114) / 1000;
                                            return ($brightness < 140) ? '#ffffff' : '#000000';
                                        };

                                        $resolvedBg = $badgeBg;
                                        if (strpos($badgeBg, 'var(') === 0) {
                                            $resolvedBg = $varMap[$badgeBg] ?? null;
                                        }

                                        if ($resolvedBg && strpos($resolvedBg, '#') === 0) {
                                            $badgeTextColor = $readableTextColor($resolvedBg);
                                        } else {
                                            // Fallback to white for unknowns
                                            $badgeTextColor = '#ffffff';
                                        }
                                    ?>
                                    <span class="badge" style="background: <?php echo $badgeBg; ?>; color: <?php echo htmlspecialchars($badgeTextColor); ?>; font-size: 0.875rem; padding: 0.375rem 0.75rem;">
                                        <?php echo htmlspecialchars($badgeText); ?>
                                    </span>
                                </td>
                                <td><?php echo $inactiveUser['file_count']; ?></td>
                            </tr>
                            <?php 
                            $rank++;
                            endforeach; 
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-history"></i> Actividad Reciente</h2>
            </div>
            <div class="card-body">
                <?php if (empty($recentActivity)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">Sin actividad</p>
                <?php else: ?>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php foreach ($recentActivity as $activity): ?>
                        <div style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                <div style="flex: 1;">
                                    <div style="font-weight: 500; margin-bottom: 0.25rem;">
                                        <?php echo htmlspecialchars($activity['username'] ?? 'Sistema'); ?>
                                    </div>
                                    <div style="font-size: 0.875rem; color: var(--text-muted);">
                                        <?php echo htmlspecialchars($activity['description']); ?>
                                    </div>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); white-space: nowrap; margin-left: 1rem;">
                                    <?php 
                                        $time = strtotime($activity['created_at']);
                                        $diff = time() - $time;
                                        if ($diff < 60) echo 'Ahora';
                                        elseif ($diff < 3600) echo ceil($diff / 60) . ' min';
                                        elseif ($diff < 86400) echo ceil($diff / 3600) . ' h';
                                        else echo date('d/m H:i', $time);
                                    ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <style>
    .admin-quick-action {
        background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-main) 100%);
        border: 2px solid var(--border-color);
        border-radius: 1rem;
        padding: 2rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 1rem;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        text-decoration: none;
        color: var(--text-main);
        position: relative;
        overflow: hidden;
    }
    .admin-quick-action::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(74, 144, 226, 0.1), transparent);
        transition: left 0.5s;
    }
    .admin-quick-action:hover::before {
        left: 100%;
    }
    .admin-quick-action:hover {
        transform: translateY(-8px);
        border-color: var(--primary);
        box-shadow: 0 12px 24px rgba(74, 144, 226, 0.2);
        background: var(--primary);
        color: white;
    }
    .admin-action-icon {
        font-size: 3rem;
        transition: transform 0.3s;
    }
    .admin-quick-action:hover .admin-action-icon {
        transform: scale(1.2) rotate(5deg);
    }
    .admin-action-text {
        font-weight: 700;
        font-size: 1.0625rem;
        text-align: center;
    }
    </style>
    
    <div class="card mt-3" style="border-radius: 1rem; overflow: hidden; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
        <div class="card-header card-header--padded">
            <h2 class="card-title" style="font-weight: 700; font-size: 1.5rem;"><i class="fas fa-bolt"></i> Accesos Rápidos</h2>
        </div>
        <div class="card-body" style="padding: 2rem;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                <a href="<?php echo BASE_URL; ?>/admin/users.php" class="admin-quick-action">
                    <div class="admin-action-icon"><i class="fas fa-users"></i></div>
                    <div class="admin-action-text">Gestionar Usuarios</div>
                </a>
                <a href="<?php echo BASE_URL; ?>/admin/files.php" class="admin-quick-action">
                    <div class="admin-action-icon"><i class="fas fa-folder"></i></div>
                    <div class="admin-action-text">Gestionar Archivos</div>
                </a>
                <a href="<?php echo BASE_URL; ?>/admin/shares.php" class="admin-quick-action">
                    <div class="admin-action-icon"><i class="fas fa-link"></i></div>
                    <div class="admin-action-text">Ver Comparticiones</div>
                </a>
                <a href="<?php echo BASE_URL; ?>/admin/config.php" class="admin-quick-action">
                    <div class="admin-action-icon"><i class="fas fa-cog"></i></div>
                    <div class="admin-action-text">Configuración</div>
                </a>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>/assets/vendor/chartjs/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Delegated click handler for period selector buttons to avoid accidental form submits
    document.addEventListener('click', function(ev){
        const btn = ev.target.closest && ev.target.closest('.period-btn');
        if (!btn) return;
        ev.preventDefault();
        ev.stopPropagation();
        const period = btn.getAttribute('data-period') || 7;
        try {
            if (typeof fetchUploadMetrics === 'function') {
                fetchUploadMetrics({ period: period });
                return;
            }
        } catch(e) {}
        // fallback to full reload if AJAX not available
        window.location.href = '<?php echo BASE_URL; ?>/admin/index.php?period=' + period;
    }, true);

    // Disk period shortcut buttons — handled via AJAX later in the script

    // Gráfico de actividad del sistema
    const systemCtx = document.getElementById('systemUploadsChart');
    if (systemCtx) {
        // keep reference on window so we can update it via AJAX
        window.systemChart = new Chart(systemCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dailyLabels); ?>,
                datasets: [{
                    label: 'Archivos Subidos',
                    data: <?php echo json_encode($dailyCountsValues); ?>,
                    borderColor: 'rgb(74, 144, 226)',
                    backgroundColor: 'rgba(74, 144, 226, 0.1)',
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y'
                }, {
                    label: 'Volumen (MB)',
                    data: <?php echo json_encode($dailySizesValues); ?>,
                    borderColor: 'rgb(80, 200, 120)',
                    backgroundColor: 'rgba(80, 200, 120, 0.1)',
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y1'
                }, {
                    label: 'Usuarios Activos',
                    data: <?php echo json_encode($dailyUsersValues); ?>,
                    borderColor: 'rgb(255, 165, 0)',
                    backgroundColor: 'rgba(255, 165, 0, 0.1)',
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y2'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Archivos'
                        },
                        ticks: { beginAtZero: true },
                        suggestedMax: <?php echo json_encode(ceil($chartMaxCount * 1.2)); ?>
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'MB'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                        ticks: { beginAtZero: true },
                        suggestedMax: <?php echo json_encode(ceil($chartMaxSize * 1.2)); ?>
                    },
                    y2: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: { display: true, text: 'Usuarios' },
                        grid: { drawOnChartArea: false },
                        ticks: { beginAtZero: true },
                        suggestedMax: <?php echo json_encode(ceil($chartMaxUsers * 1.2)); ?>
                    },
                    x: {
                        // ensure labels are plotted left->right chronological
                        reverse: false,
                        offset: false,
                        ticks: {
                            maxRotation: 0,
                            autoSkip: true
                        }
                    }
                }
            }
        });

        // helper to update uploads chart data from JSON
        function applyUploadData(data) {
            if (!window.systemChart) return;
            window.systemChart.data.labels = data.labels || [];
            if (window.systemChart.data.datasets[0]) {
                window.systemChart.data.datasets[0].data = data.counts || [];
            }
            if (window.systemChart.data.datasets[1]) {
                window.systemChart.data.datasets[1].data = data.sizes || [];
            }
            if (window.systemChart.data.datasets[2]) {
                window.systemChart.data.datasets[2].data = data.users || [];
            }
            // Update axis maxima based on incoming data so each series uses an appropriate scale
            try {
                var counts = (data.counts && data.counts.length) ? data.counts.map(Number) : [0];
                var sizes = (data.sizes && data.sizes.length) ? data.sizes.map(Number) : [0];
                var users = (data.users && data.users.length) ? data.users.map(Number) : [0];
                var maxCount = Math.max.apply(null, counts.concat([1]));
                var maxSize = Math.max.apply(null, sizes.concat([1]));
                var maxUsers = Math.max.apply(null, users.concat([1]));
                if (window.systemChart.options && window.systemChart.options.scales) {
                    if (window.systemChart.options.scales.y) window.systemChart.options.scales.y.suggestedMax = Math.ceil(maxCount * 1.2);
                    if (window.systemChart.options.scales.y1) window.systemChart.options.scales.y1.suggestedMax = Math.ceil(maxSize * 1.2);
                    if (window.systemChart.options.scales.y2) window.systemChart.options.scales.y2.suggestedMax = Math.ceil(maxUsers * 1.2);
                }
            } catch(e) {}
            window.systemChart.update();
        }

        // fetch helper for uploads metrics
        async function fetchUploadMetrics(params) {
            const qs = new URLSearchParams(params || {}).toString();
            const resp = await fetch('upload_metrics.php?' + qs, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!resp.ok) return;
            const json = await resp.json();
            applyUploadData(json);

            // update URL without reloading (keep upload params)
            const url = new URL(window.location);
            url.searchParams.delete('from');
            url.searchParams.delete('to');
            url.searchParams.delete('period');
            if (json._params) {
                if (json._params.from) url.searchParams.set('from', json._params.from);
                if (json._params.to) url.searchParams.set('to', json._params.to);
                if (json._params.period) url.searchParams.set('period', json._params.period);
            }
            history.replaceState({}, '', url);
            // Update uploads period button active state
            try {
                const lastFrom = new Date(); lastFrom.setDate(lastFrom.getDate() - ( (json._params && json._params.period) ? (json._params.period -1) : 6 ));
                const lastFromStr = lastFrom.toISOString().slice(0,10);
                const lastToStr = new Date().toISOString().slice(0,10);
                const isLastWeek = (json._params && ((json._params.period && parseInt(json._params.period) === 7) || (json._params.from === lastFromStr && json._params.to === lastToStr)));
                document.querySelectorAll('.period-btn').forEach(b=> b.classList.toggle('active', isLastWeek));
                const uploadsBadge = document.getElementById('uploads-range-badge');
                if (uploadsBadge) uploadsBadge.style.display = isLastWeek ? 'inline-block' : 'none';
            } catch(e) {}
        }

        // wire up the uploads date form to submit via AJAX
        const uploadsForm = document.querySelector('.uploads-chart-card .period-selector form');
        if (uploadsForm) {
            uploadsForm.addEventListener('submit', function(ev) {
                ev.preventDefault();
                const fd = new FormData(uploadsForm);
                const from = fd.get('from');
                const to = fd.get('to');
                fetchUploadMetrics({ from: from, to: to });
            });
        }
    }

    // Gráfico de distribución de tipos
    const typesCtx = document.getElementById('fileTypesChart');
    if (typesCtx) {
        new Chart(typesCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($typeLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($typeCounts); ?>,
                    backgroundColor: <?php echo json_encode($chartColors); ?>,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: false
                    }
                }
            }
        });
    }
    
    // Gráfico de uso de disco: serie temporal (GB vs tiempo)
    const diskCtx = document.getElementById('diskUsageChart');
    if (diskCtx) {
        // keep a reference so we can update it via AJAX without reloading
        window.diskChart = new Chart(diskCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($diskLabels ?: [$diskLabels ? $diskLabels[0] : date('d/m H:i')]); ?>,
                datasets: [{
                    label: 'Usado (GB)',
                    data: <?php echo json_encode($diskUsedSeries ?: [$diskUsedGB]); ?>,
                    borderColor: 'rgb(74, 144, 226)',
                    backgroundColor: 'rgba(74, 144, 226, 0.15)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3,
                    yAxisID: 'y'
                }, {
                    label: 'Capacidad Total (GB)',
                    data: <?php echo json_encode($diskTotalSeries ?: [$diskTotalGB]); ?>,
                    borderColor: 'rgb(120,120,120)',
                    borderDash: [6,4],
                    backgroundColor: 'rgba(120,120,120,0.05)',
                    fill: false,
                    tension: 0.1,
                    pointRadius: 0,
                    yAxisID: 'y'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                layout: { padding: { left: 0, right: 0, top: 8, bottom: 8 } },
                plugins: { legend: { position: 'top' } },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'GB' },
                        // enforce maximum capacity for the chart
                        max: <?php echo json_encode((float)$diskCapacityGB); ?>,
                        ticks: {
                            // sensible step size
                            stepSize: Math.max(1, Math.round(<?php echo json_encode((float)$diskCapacityGB); ?> / 10))
                        }
                    },
                    x: {
                        title: { display: true, text: 'Tiempo' }
                    }
                }
            }
        });

        // helper to update chart data from JSON response
        function applyDiskData(data) {
            if (!window.diskChart) return;
            window.diskChart.data.labels = data.labels || [];
            if (window.diskChart.data.datasets[0]) {
                window.diskChart.data.datasets[0].data = data.used || [];
            }
            if (window.diskChart.data.datasets[1]) {
                window.diskChart.data.datasets[1].data = data.total || [];
            }
            // update y max if provided
            if (data.capacity_gb) {
                const cap = parseFloat(data.capacity_gb);
                if (window.diskChart.options && window.diskChart.options.scales && window.diskChart.options.scales.y) {
                    window.diskChart.options.scales.y.max = cap;
                    window.diskChart.options.scales.y.ticks.stepSize = Math.max(1, Math.round(cap/10));
                }
            }
            window.diskChart.update();

            // update small stats on the right
            if (data.percent !== undefined) {
                const el = document.getElementById('disk-percent');
                if (el) el.textContent = data.percent + '% ocupado';
            }
            if (data.used_gb !== undefined && data.total_gb !== undefined) {
                const el2 = document.getElementById('disk-used-total');
                if (el2) el2.textContent = data.used_gb + ' GB ocupados de ' + data.total_gb + ' GB';
            }
        }

        // fetch helper
        async function fetchDiskMetrics(params) {
            const qs = new URLSearchParams(params || {}).toString();
            const resp = await fetch('disk_metrics.php?' + qs, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!resp.ok) return;
            const json = await resp.json();
            applyDiskData(json);
            // update URL without reloading
            const url = new URL(window.location);
            // keep only disk-related params
            url.searchParams.delete('disk_from');
            url.searchParams.delete('disk_to');
            url.searchParams.delete('disk_period');
            if (json._params) {
                if (json._params.disk_from) url.searchParams.set('disk_from', json._params.disk_from);
                if (json._params.disk_to) url.searchParams.set('disk_to', json._params.disk_to);
                if (json._params.disk_period) url.searchParams.set('disk_period', json._params.disk_period);
            }
            history.replaceState({}, '', url);
            // Update disk period button active state
            try {
                const lastFrom = new Date(); lastFrom.setDate(lastFrom.getDate() - ((json._params && json._params.disk_period) ? (parseInt(json._params.disk_period) -1) : 6));
                const lastFromStr = lastFrom.toISOString().slice(0,10);
                const lastToStr = new Date().toISOString().slice(0,10);
                const isLastWeekDisk = (json._params && ((json._params.disk_period && parseInt(json._params.disk_period) === 7) || (json._params.disk_from === lastFromStr && json._params.disk_to === lastToStr)));
                document.querySelectorAll('.disk-period-btn').forEach(b=> b.classList.toggle('active', isLastWeekDisk));
                const diskBadge = document.getElementById('disk-range-badge');
                if (diskBadge) diskBadge.style.display = isLastWeekDisk ? 'inline-block' : 'none';
            } catch(e) {}
        }

        // wire up the period buttons and the inline form
        const diskForm = document.querySelector('.disk-usage-card .period-selector form');
        if (diskForm) {
            diskForm.addEventListener('submit', function(ev) {
                ev.preventDefault();
                const fd = new FormData(diskForm);
                const disk_from = fd.get('disk_from');
                const disk_to = fd.get('disk_to');
                fetchDiskMetrics({ disk_from: disk_from, disk_to: disk_to });
            });
        }

        document.querySelectorAll('.disk-period-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                const p = btn.getAttribute('data-disk-period') || 7;
                fetchDiskMetrics({ disk_period: p });
            });
        });
    }
    
    // Gráfico semanal
    const weeklyCtx = document.getElementById('weeklyUploadsChart');
    if (weeklyCtx) {
        new Chart(weeklyCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($weeklyLabels); ?>,
                datasets: [{
                    label: 'Archivos por Semana',
                    data: <?php echo json_encode($weeklyCounts); ?>,
                    backgroundColor: 'rgba(74, 144, 226, 0.7)',
                    borderColor: 'rgb(74, 144, 226)',
                    borderWidth: 2,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            afterLabel: function(context) {
                                const size = <?php echo json_encode($weeklySizes); ?>[context.dataIndex];
                                return 'Volumen: ' + size + ' MB';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Archivos'
                        }
                    }
                }
            }
        });
    }
    
    // Gráfico por día de la semana
    const dayOfWeekCtx = document.getElementById('dayOfWeekChart');
    if (dayOfWeekCtx) {
        new Chart(dayOfWeekCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($dayOfWeekLabels); ?>,
                datasets: [{
                    label: 'Archivos Subidos',
                    data: <?php echo json_encode($dayOfWeekCounts); ?>,
                    backgroundColor: <?php echo json_encode($dayOfWeekColors); ?>,
                    borderColor: <?php echo json_encode($dayOfWeekColors); ?>,
                    borderWidth: 2,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            afterLabel: function(context) {
                                const dayIndex = context.dataIndex;
                                return dayIndex >= 5 ? '🎉 Fin de Semana' : '💼 Entre Semana';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Total de Archivos'
                        }
                    }
                }
            }
        });
    }
});
</script>

<?php renderPageEnd(); ?>

</div>
