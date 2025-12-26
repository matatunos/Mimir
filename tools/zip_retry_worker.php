<?php
// ZIP retry worker for folder shares
// Usage: php tools/zip_retry_worker.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Share.php';

$logPath = defined('LOGS_PATH') ? rtrim(LOGS_PATH, '/') . '/zip_retry.log' : __DIR__ . '/../storage/logs/zip_retry.log';
@mkdir(dirname($logPath), 0755, true);
function logmsg($m) {
    global $logPath;
    $line = date('c') . ' ' . $m . "\n";
    @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

try {
    $db = Database::getInstance()->getConnection();
    // ensure queue table
    $db->exec("CREATE TABLE IF NOT EXISTS zip_retry_queue (
        share_id INT PRIMARY KEY,
        attempts TINYINT NOT NULL DEFAULT 0,
        last_attempt DATETIME DEFAULT NULL,
        next_attempt DATETIME DEFAULT NULL,
        status ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // find candidate shares: folder shares (is_folder=1) active and without public zip or marked previously failed/pending
    $sql = "SELECT s.id, s.share_token, s.file_id, s.created_by, f.original_name FROM shares s JOIN files f ON s.file_id=f.id WHERE f.is_folder=1 AND s.is_active=1";
    $stmt = $db->query($sql);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $shareClass = new Share();

    foreach ($candidates as $c) {
        $shareId = $c['id'];
        $token = $c['share_token'];
        $publicZip = rtrim(constant('BASE_PATH'), '/') . '/public/sfiles/' . $token . '.zip';
        if (file_exists($publicZip) && filesize($publicZip) > 0) {
            // If zip exists, mark done in queue and continue
            $ins = $db->prepare("INSERT INTO zip_retry_queue (share_id, attempts, last_attempt, next_attempt, status) VALUES (?, ?, NOW(), NOW(), 'done') ON DUPLICATE KEY UPDATE status='done', last_attempt=NOW(), attempts=0, next_attempt=NOW()");
            $ins->execute([$shareId, 0]);
            continue;
        }

        // Insert into queue if missing
        $q = $db->prepare("SELECT * FROM zip_retry_queue WHERE share_id = ? LIMIT 1");
        $q->execute([$shareId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $ins = $db->prepare("INSERT INTO zip_retry_queue (share_id, attempts, next_attempt, status) VALUES (?, 0, NOW(), 'pending')");
            $ins->execute([$shareId]);
            $row = ['attempts' => 0, 'next_attempt' => date('Y-m-d H:i:s')];
        }

        // check if it's time to attempt
        $now = new DateTime();
        $next = new DateTime($row['next_attempt'] ?? '1970-01-01');
        if ($row['status'] === 'done') continue;
        if ($row['status'] === 'failed' && intval($row['attempts']) >= 5) {
            logmsg("share={$shareId} skipped: marked failed with attempts={$row['attempts']}");
            continue;
        }
        if ($next > $now) {
            // not yet
            continue;
        }

        // Attempt create ZIP
        logmsg("Attempting ZIP creation for share={$shareId} token={$token}");
        try {
            $rm = new ReflectionMethod('Share', 'createZipFromFolder');
            $rm->setAccessible(true);
            $created = $rm->invoke($shareClass, $c['file_id'], $c['created_by'], $publicZip);
            if ($created && file_exists($publicZip)) {
                @chmod($publicZip, 0644);
                // mark done
                $up = $db->prepare("UPDATE zip_retry_queue SET attempts = attempts + 1, last_attempt = NOW(), next_attempt = NOW(), status = 'done' WHERE share_id = ?");
                $up->execute([$shareId]);
                logmsg("ZIP created for share={$shareId} path={$publicZip}");
                // resend notification if recipient_email present
                try {
                    $sent = $shareClass->resendNotification($shareId);
                    logmsg("ResendNotification for share={$shareId} returned: " . ($sent ? 'true' : 'false'));
                } catch (Throwable $e) {
                    logmsg("ResendNotification error for share={$shareId}: " . $e->getMessage());
                }
                continue;
            } else {
                throw new Exception('createZipFromFolder returned false');
            }
        } catch (Throwable $e) {
            // update attempts and schedule backoff
            $attempts = intval($row['attempts']) + 1;
            $backoffMinutes = min(60, pow(2, max(0, $attempts - 1)) * 5); // 5,10,20,40,60
            $nextAttempt = (new DateTime())->add(new DateInterval('PT' . $backoffMinutes . 'M'))->format('Y-m-d H:i:s');
            $up = $db->prepare("INSERT INTO zip_retry_queue (share_id, attempts, last_attempt, next_attempt, status) VALUES (?, ?, NOW(), ?, 'pending') ON DUPLICATE KEY UPDATE attempts = ?, last_attempt = NOW(), next_attempt = ?, status = 'pending'");
            $up->execute([$shareId, $attempts, $nextAttempt, $attempts, $nextAttempt]);
            logmsg("ZIP creation failed for share={$shareId} attempt={$attempts} next_attempt={$nextAttempt} error=" . $e->getMessage());
            if ($attempts >= 5) {
                $db->prepare("UPDATE zip_retry_queue SET status='failed' WHERE share_id = ?")->execute([$shareId]);
                logmsg("share={$shareId} marked failed after {$attempts} attempts");
            }
        }
    }

    logmsg('Worker run completed');
} catch (Throwable $e) {
    logmsg('Worker internal error: ' . $e->getMessage());
    exit(1);
}

exit(0);
