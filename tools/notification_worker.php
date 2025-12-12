<?php
// Simple CLI worker to process notification jobs from `notification_jobs` table.
// Usage: php tools/notification_worker.php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Email.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/ForensicLogger.php';

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from CLI.\n";
    exit(1);
}

$db = Database::getInstance()->getConnection();
$logger = new Logger();
$forensic = new ForensicLogger();
$email = new Email();

echo "Notification worker started.\n";

while (true) {
    try {
        // Claim one pending job
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT * FROM notification_jobs WHERE status = 'pending' AND next_run_at <= NOW() ORDER BY next_run_at ASC LIMIT 1 FOR UPDATE");
        $stmt->execute();
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            $db->commit();
            // No jobs, sleep a bit
            sleep(5);
            continue;
        }

        // mark processing
        $upd = $db->prepare("UPDATE notification_jobs SET status = 'processing', updated_at = NOW() WHERE id = ?");
        $upd->execute([$job['id']]);
        $db->commit();

        $recipient = $job['recipient'];
        $subject = $job['subject'];
        $body = $job['body'];
        $options = $job['options'] ? json_decode($job['options'], true) : [];
        $attempts = (int)$job['attempts'];
        $maxAttempts = max(1, (int)$job['max_attempts']);
        $actorId = $job['actor_id'] ?: null;
        $targetId = $job['target_id'] ?: null;

        $sent = false;
        $lastError = null;
        try {
            $sent = $email->send($recipient, $subject, $body, $options);
        } catch (Exception $e) {
            $lastError = $e->getMessage();
        }

        if ($sent) {
            $stmtDone = $db->prepare("UPDATE notification_jobs SET status = 'done', attempts = attempts + 1, updated_at = NOW() WHERE id = ?");
            $stmtDone->execute([$job['id']]);
            $logger->log($actorId, 'notif_user_created_sent', 'notification', $targetId, "Worker sent notification to {$recipient}");
            echo "Sent to {$recipient}\n";
            continue;
        }

        // Failed: increment attempts and schedule retry or mark failed
        $attempts++;
        if ($attempts >= $maxAttempts) {
            $stmtFail = $db->prepare("UPDATE notification_jobs SET status = 'failed', attempts = ?, last_error = ?, updated_at = NOW() WHERE id = ?");
            $stmtFail->execute([$attempts, $lastError, $job['id']]);
            $logger->log($actorId, 'notif_user_created_failed', 'notification', $targetId, "Worker failed to send to {$recipient} after {$attempts} attempts");
            try { $forensic->logSecurityEvent('notification_failed_exhausted', 'high', 'Worker notification retries exhausted', ['recipient' => $recipient, 'attempts' => $attempts, 'last_error' => $lastError], $targetId); } catch (Exception $e) { error_log('Forensic log error: ' . $e->getMessage()); }
            echo "Failed to send to {$recipient} after {$attempts} attempts. Marked failed.\n";
        } else {
            // Exponential backoff: base delay from config or default 2s
            $cfgStmt = $db->prepare("SELECT config_value FROM config WHERE config_key = 'notify_user_creation_retry_delay_seconds' LIMIT 1");
            $cfgStmt->execute();
            $base = intval($cfgStmt->fetchColumn() ?: 2);
            $delay = $base * (2 ** ($attempts - 1));
            $nextRun = date('Y-m-d H:i:s', time() + $delay);
            $stmtRetry = $db->prepare("UPDATE notification_jobs SET attempts = ?, next_run_at = ?, last_error = ?, status = 'pending', updated_at = NOW() WHERE id = ?");
            $stmtRetry->execute([$attempts, $nextRun, $lastError, $job['id']]);
            $logger->log($actorId, 'notif_user_created_attempt_failed', 'notification', $targetId, "Worker attempt {$attempts} failed for {$recipient}; next_run={$nextRun}");
            echo "Attempt {$attempts} failed for {$recipient}; retry at {$nextRun}\n";
        }

    } catch (Exception $e) {
        error_log('Worker exception: ' . $e->getMessage());
        // sleep briefly to avoid tight exception loop
        sleep(3);
    }
}

?>