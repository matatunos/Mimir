<?php
// Aggressive reconcile: search UPLOADS_PATH recursively for filenames matching file_path basename, stored_name or original_name
// and update files.file_path to the first found candidate. Creates backup CSV. USE WITH CAUTION.
// Usage: php tools/aggressive_reconcile_apply.php [--limit=N] [--verbose]

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Logger.php';

$limit = null;
$verbose = false;
foreach ($argv as $a) {
    if (strpos($a, '--limit=') === 0) $limit = intval(substr($a, 8));
    if ($a === '--verbose') $verbose = true;
}

$db = Database::getInstance()->getConnection();
$logger = new Logger();

$sql = 'SELECT id, user_id, original_name, stored_name, file_path FROM files ORDER BY id ASC';
if (!empty($limit)) $sql .= ' LIMIT ' . intval($limit);
$stmt = $db->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "No file records found.\n";
    exit(0);
}

$timestamp = date('Ymd_His');
$backupCsv = __DIR__ . "/aggressive_backup_{$timestamp}.csv";
$backupFp = fopen($backupCsv, 'w');
fputcsv($backupFp, ['file_id','old_file_path','candidate_found','candidate_path','applied_at']);

// Build index of files under UPLOADS_PATH by basename to speed up lookups
echo "Indexing files under UPLOADS_PATH (this may take a while)...\n";
$index = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(UPLOADS_PATH, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($it as $fileinfo) {
    if (!$fileinfo->isFile()) continue;
    $bn = $fileinfo->getBasename();
    if (!isset($index[$bn])) $index[$bn] = [];
    $index[$bn][] = $fileinfo->getPathname();
}

echo "Index complete. Indexed " . count($index) . " distinct basenames.\n";

$applied = 0;
$processed = 0;
foreach ($rows as $r) {
    $processed++;
    $id = $r['id'];
    $old = $r['file_path'];
    // Normalize path absolute for existence check
    $abs = $old;
    if ($abs !== '' && strpos($abs, '/') !== 0) {
        $abs = rtrim(UPLOADS_PATH, '/') . '/' . ltrim($old, '/');
    }

    if ($abs && is_file($abs)) {
        if ($verbose) echo "File ID {$id}: already exists at {$abs}\n";
        continue;
    }

    $candidates = [];
    // candidate keys: stored_name, basename of old, original_name
    $keys = [];
    if (!empty($r['stored_name'])) $keys[] = $r['stored_name'];
    if (!empty($old)) $keys[] = basename($old);
    if (!empty($r['original_name'])) $keys[] = basename($r['original_name']);

    foreach ($keys as $k) {
        if (empty($k)) continue;
        if (isset($index[$k])) {
            foreach ($index[$k] as $p) {
                $candidates[] = $p;
            }
        }
    }

    // Deduplicate
    $candidates = array_values(array_unique($candidates));

    if (empty($candidates)) {
        if ($verbose) echo "File ID {$id}: no candidates found (keys: " . implode(',', $keys) . ")\n";
        continue;
    }

    // Choose best candidate by preferring path that includes user_id folder if possible
    $chosen = null;
    foreach ($candidates as $c) {
        if (strpos($c, '/' . intval($r['user_id']) . '/') !== false) { $chosen = $c; break; }
    }
    if ($chosen === null) $chosen = $candidates[0];

    // Apply update
    try {
        $u = $db->prepare('UPDATE files SET file_path = ? WHERE id = ?');
        $u->execute([$chosen, $id]);
        fputcsv($backupFp, [$id, $old, '1', $chosen, date('c')]);
        $applied++;
        echo "Updated file {$id} -> {$chosen}\n";
        try { $logger->log(0, 'aggressive_reconcile_applied', 'file', $id, "Updated file_path to {$chosen}"); } catch (Exception $e) {}
    } catch (Exception $e) {
        fputcsv($backupFp, [$id, $old, '0', '', date('c')]);
        echo "Failed to update {$id}: " . $e->getMessage() . "\n";
    }
}

fclose($backupFp);

echo "Processed {$processed} rows. Applied updates: {$applied}. Backup: {$backupCsv}\n";

exit(0);
