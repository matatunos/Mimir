<?php
// Usage: php tools/reconcile_uploads.php [--dry-run] [--apply] [--limit=N] [--report=path] [--verbose]
// Scans `files` records and checks the stored `file_path` exists. If missing, searches UPLOADS_PATH for candidates
// and optionally updates the DB (when --apply is provided). Always creates a CSV backup when applying.

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../classes/Logger.php';

$opts = [
    'dry-run' => true,
    'apply' => false,
    'limit' => null,
    'report' => null,
    'verbose' => false,
];

// Parse CLI args
foreach ($argv as $a) {
    if ($a === '--apply') { $opts['apply'] = true; $opts['dry-run'] = false; }
    if ($a === '--dry-run') { $opts['dry-run'] = true; }
    if (strpos($a, '--limit=') === 0) { $opts['limit'] = intval(substr($a, 8)); }
    if (strpos($a, '--report=') === 0) { $opts['report'] = substr($a, 9); }
    if ($a === '--verbose') { $opts['verbose'] = true; }
}

if ($opts['apply']) {
    echo "Running in APPLY mode — changes WILL be made. Backup CSV will be created.\n";
} else {
    echo "Running in DRY-RUN mode (no DB changes). Use --apply to update DB.\n";
}

$db = Database::getInstance()->getConnection();
$cfg = new Config();
$logger = new Logger();

$limitSql = '';
$params = [];
if (!empty($opts['limit'])) {
    $limitSql = ' LIMIT ?';
    $params[] = $opts['limit'];
}

$sql = 'SELECT id, user_id, original_name, stored_name, file_path, file_size FROM files ORDER BY id ASC' . $limitSql;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "No file records found.\n";
    exit(0);
}

$reportRows = [];
$changes = [];

function normalize_db_path($path) {
    if (empty($path)) return '';
    if (strpos($path, '/') === 0) return $path; // absolute
    // treat as relative to UPLOADS_PATH
    return rtrim(UPLOADS_PATH, '/') . '/' . ltrim($path, '/');
}

function search_candidates($storedName, $originalName, $maxResults = 10) {
    $candidates = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(UPLOADS_PATH, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $fileinfo) {
        if (!$fileinfo->isFile()) continue;
        $basename = $fileinfo->getBasename();
        if ($basename === $storedName) {
            $candidates[] = $fileinfo->getPathname();
            if (count($candidates) >= $maxResults) break;
        }
    }
    // If none found by stored_name, attempt fuzzy search by original name
    if (empty($candidates) && !empty($originalName)) {
        foreach ($it as $fileinfo) {
            if (!$fileinfo->isFile()) continue;
            if (stripos($fileinfo->getBasename(), $originalName) !== false) {
                $candidates[] = $fileinfo->getPathname();
                if (count($candidates) >= $maxResults) break;
            }
        }
    }
    return $candidates;
}

$timestamp = date('Ymd_His');
$backupCsv = __DIR__ . "/reconcile_backup_{$timestamp}.csv";
$reportPath = $opts['report'] ?? (__DIR__ . "/reconcile_report_{$timestamp}.csv");

if ($opts['apply']) {
    $backupFp = fopen($backupCsv, 'w');
    fputcsv($backupFp, ['file_id','old_file_path','new_file_path','applied_at']);
}

$reportFp = fopen($reportPath, 'w');
fputcsv($reportFp, ['file_id','user_id','stored_name','original_name','db_file_path','exists','candidate_count','top_candidate','candidate_paths']);

foreach ($rows as $r) {
    $id = $r['id'];
    $userId = $r['user_id'];
    $storedName = $r['stored_name'];
    $origName = $r['original_name'];
    $dbPathRaw = $r['file_path'];
    $dbPath = normalize_db_path($dbPathRaw);

    $exists = is_file($dbPath);
    if ($exists) {
        if ($opts['verbose']) echo "File ID {$id}: exists at {$dbPath}\n";
        fputcsv($reportFp, [$id,$userId,$storedName,$origName,$dbPath,'1',0,'','']);
        continue;
    }

    if ($opts['verbose']) echo "File ID {$id}: missing at {$dbPath}\n";

    $candidates = search_candidates($storedName, $origName, 20);
    $top = '';
    if (!empty($candidates)) {
        // pick best candidate by exact basename+size match if possible
        $best = null;
        foreach ($candidates as $c) {
            if (basename($c) === $storedName) { $best = $c; break; }
        }
        if ($best === null) $best = $candidates[0];
        $top = $best;
    }

    fputcsv($reportFp, [$id,$userId,$storedName,$origName,$dbPath,'0',count($candidates),$top,implode('|',$candidates)]);

    $reportRows[] = [
        'id' => $id,
        'user_id' => $userId,
        'stored_name' => $storedName,
        'original_name' => $origName,
        'db_path' => $dbPath,
        'candidates' => $candidates,
        'top' => $top
    ];

    if ($opts['apply'] && !empty($top)) {
        // Update DB with new absolute path
        $newPath = $top;
        try {
            $uStmt = $db->prepare('UPDATE files SET file_path = ? WHERE id = ?');
            $uStmt->execute([$newPath, $id]);
            $changes[] = ['id' => $id, 'old' => $dbPath, 'new' => $newPath];
            fputcsv($backupFp, [$id, $dbPath, $newPath, date('c')]);
            echo "Applied update for file {$id}: {$newPath}\n";
            // Log via Logger
            try {
                $logger->log(0, 'reconcile_file_path_updated', 'file', $id, "Updated file_path from {$dbPath} to {$newPath}");
            } catch (Exception $le) {
                // ignore logging errors for CLI
            }
        } catch (Exception $e) {
            echo "Failed to update file {$id}: " . $e->getMessage() . "\n";
        }
    }
}

fclose($reportFp);
if ($opts['apply']) fclose($backupFp);

echo "Report written to: {$reportPath}\n";
if ($opts['apply']) echo "Backup CSV written to: {$backupCsv}\n";

// Summary
$total = count($rows);
$missing = count(array_filter($reportRows, function($r){ return !$r['top'] || empty($r['top']); }));
$fixed = count($changes);

echo "Total files scanned: {$total}\n";
echo "Candidates found for missing files: " . (count($reportRows) - $missing) . "\n";
if ($opts['apply']) echo "DB updates applied: {$fixed}\n";

exit(0);
