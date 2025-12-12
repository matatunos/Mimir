<?php
// Heuristic fixer: detect `file_path` records that normalize to missing files due to duplicated 'uploads/uploads/'
// and try transformations (remove duplicate 'uploads/') and apply updates when the transformed path exists.
// Usage: php tools/fix_duplicate_uploads.php [--apply] [--limit=N] [--verbose]

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Logger.php';

$apply = false;
$limit = null;
$verbose = false;
foreach ($argv as $a) {
    if ($a === '--apply') $apply = true;
    if (strpos($a, '--limit=') === 0) $limit = intval(substr($a, 8));
    if ($a === '--verbose') $verbose = true;
}

$db = Database::getInstance()->getConnection();
$logger = new Logger();

function normalize_db_path($path) {
    if (empty($path)) return '';
    if (strpos($path, '/') === 0) return $path;
    return rtrim(UPLOADS_PATH, '/') . '/' . ltrim($path, '/');
}

$sql = 'SELECT id, user_id, stored_name, original_name, file_path FROM files';
if (!empty($limit)) $sql .= ' LIMIT ' . intval($limit);
$stmt = $db->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$timestamp = date('Ymd_His');
$backup = __DIR__ . "/fixdup_backup_{$timestamp}.csv";
$backupFp = fopen($backup, 'w');
fputcsv($backupFp, ['file_id','old_file_path','new_file_path','applied_at']);

$changes = 0;

foreach ($rows as $r) {
    $id = $r['id'];
    $raw = $r['file_path'];
    $norm = normalize_db_path($raw);
    if (empty($raw)) {
        if ($verbose) echo "File ID {$id}: empty file_path, skipping\n";
        continue;
    }
    if (is_file($norm)) {
        if ($verbose) echo "File ID {$id}: exists -> ok\n";
        continue;
    }

    // Try heuristic transforms
    $candidates = [];

    // 1) If contains '/uploads/uploads/' try to collapse to single 'uploads/'
    if (strpos($raw, 'uploads/uploads') !== false) {
        $trial = preg_replace('#uploads/uploads#', 'uploads', $raw, 1);
        $trialNorm = normalize_db_path($trial);
        $candidates[] = $trialNorm;
    }

    // 2) Remove leading 'uploads/' if present
    if (strpos($raw, 'uploads/') === 0) {
        $trial2 = preg_replace('#^uploads/#', '', $raw);
        $trial2Norm = normalize_db_path($trial2);
        $candidates[] = $trial2Norm;
    }

    // 3) If there are multiple occurrences of 'uploads/', try removing one occurrence from the normalized path
    if (strpos($norm, '/uploads/uploads/') !== false) {
        $trial3 = str_replace('/uploads/uploads/', '/uploads/', $norm);
        $candidates[] = $trial3;
    }

    // 4) If stored_name available, try searching under UPLOADS_PATH/<user_id>/stored_name
    if (!empty($r['stored_name'])) {
        $userPath = rtrim(UPLOADS_PATH, '/') . '/' . intval($r['user_id']) . '/' . $r['stored_name'];
        $candidates[] = $userPath;
    }

    $applied = false;
    foreach ($candidates as $cand) {
        if (empty($cand)) continue;
        if (is_file($cand)) {
            echo "File ID {$id}: found candidate -> {$cand}\n";
            if ($apply) {
                try {
                    $u = $db->prepare('UPDATE files SET file_path = ? WHERE id = ?');
                    $u->execute([$cand, $id]);
                    fputcsv($backupFp, [$id, $raw, $cand, date('c')]);
                    $changes++;
                    $applied = true;
                    try { $logger->log(0, 'fixdup_applied', 'file', $id, "Updated file_path to {$cand}"); } catch (Exception $e) {}
                } catch (Exception $e) {
                    echo "Failed to update file {$id}: " . $e->getMessage() . "\n";
                }
            } else {
                echo "File ID {$id}: candidate (dry-run) -> {$cand}\n";
                $applied = true; // mark as found in dry-run
            }
            break;
        }
    }

    if (!$applied && $verbose) echo "File ID {$id}: no valid candidate found\n";
}

fclose($backupFp);

echo "Done. Changes applied: {$changes}\n";
echo "Backup file: {$backup}\n";

exit(0);
