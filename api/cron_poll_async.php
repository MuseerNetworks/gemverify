<?php
declare(strict_types=1);

/**
 * GemVerify — Async Ticket Poller (Cron Script)
 *
 * Polls TechHub for outstanding async ticket statuses.
 * Run this via Windows Task Scheduler or XAMPP cron every 5 minutes.
 *
 * Example Task Scheduler command:
 *   "c:\xampp\php\php.exe" "c:\xampp\htdocs\gemverify\api\cron_poll_async.php"
 *
 * Strategy:
 *   - Picks up to 20 api_transactions in 'processing' state
 *   - Only polls tickets not checked in the last 5 minutes (recent)
 *     or not checked in the last 30 minutes (older than 1 hour)
 *   - Updates status on terminal result (success/failed)
 *   - Never auto-refunds — marks 'failed' for admin review
 *   - All actions written to api/logs/cron_poll.log
 *
 * SAFE: Never re-submits. Only GETs status. Idempotent.
 */


// Only allow CLI execution — never via HTTP
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: This script must be run from command line only.\n";
    exit(1);
}

define('RUNNING_MIGRATION', true); // Suppresses any HTTP-only guards in config

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

// Register PSR-4 autoloader (normally done in index.php)
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/src/' . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$db             = db();
$techHubService = new \Services\TechHubService();
$logPath        = __DIR__ . '/logs/cron_poll.log';

// Ensure logs directory exists
if (!is_dir(dirname($logPath))) {
    mkdir(dirname($logPath), 0755, true);
}

$startTime = microtime(true);

function cronLog(string $logPath, string $level, string $msg): void
{
    $line = sprintf("[%s] [%-5s] %s\n", date('Y-m-d H:i:s'), $level, $msg);
    echo $line;
    file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

cronLog($logPath, 'INFO', '=== GemVerify Async Poller START ===');

// ── Query: Find tickets eligible for polling ───────────────────────────────────
//
// Eligibility rules:
//   - gv_status = 'processing'  (only active async tickets)
//   - result_type = 'ticket'    (not slip services)
//   - provider_ticket_id IS NOT NULL (must have something to poll)
//   - EITHER:
//       a) last_checked_at IS NULL (never polled before), OR
//       b) submitted < 1 hour ago AND last_checked_at < NOW() - 5 min (frequent early polls), OR
//       c) submitted >= 1 hour ago AND last_checked_at < NOW() - 30 min (less frequent later)
//
// Limit 20 per run to prevent hammering the provider.
//
try {
    $stmt = $db->prepare("
        SELECT
            at.id,
            at.gv_reference,
            at.provider_ticket_id,
            at.variant_key,
            at.gv_status,
            at.submitted_at,
            at.last_checked_at,
            at.refund_issued,
            s.slug AS service_slug,
            s.name AS service_name
        FROM api_transactions at
        JOIN services s ON s.id = at.service_id
        WHERE at.gv_status = 'processing'
          AND at.result_type = 'ticket'
          AND at.provider_ticket_id IS NOT NULL
          AND at.provider_ticket_id != ''
          AND (
            at.last_checked_at IS NULL
            OR (
              at.submitted_at >= NOW() - INTERVAL 1 HOUR
              AND at.last_checked_at < NOW() - INTERVAL 5 MINUTE
            )
            OR (
              at.submitted_at < NOW() - INTERVAL 1 HOUR
              AND at.last_checked_at < NOW() - INTERVAL 30 MINUTE
            )
          )
        ORDER BY at.submitted_at ASC
        LIMIT 20
    ");
    $stmt->execute();
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    cronLog($logPath, 'ERROR', 'DB query failed: ' . $e->getMessage());
    exit(1);
}

$count = count($tickets);
cronLog($logPath, 'INFO', "Found {$count} ticket(s) eligible for polling");

if ($count === 0) {
    cronLog($logPath, 'INFO', '=== GemVerify Async Poller END (nothing to do) ===');
    exit(0);
}

// ── Poll each ticket ───────────────────────────────────────────────────────────

$polled    = 0;
$completed = 0;
$failed    = 0;
$pending   = 0;
$errors    = 0;

foreach ($tickets as $tx) {
    $ref      = $tx['gv_reference'];
    $ticketId = $tx['provider_ticket_id'];
    $slug     = $tx['service_slug'];
    $variant  = $tx['variant_key'];

    cronLog($logPath, 'INFO', "Polling {$ref} | ticket={$ticketId} | service={$slug}");

    // Mark last_checked_at BEFORE calling provider (prevents duplicate polls if script hangs)
    try {
        $db->prepare("UPDATE api_transactions SET last_checked_at = NOW() WHERE id = ?")
           ->execute([$tx['id']]);
    } catch (Throwable $e) {
        cronLog($logPath, 'WARN', "  Could not update last_checked_at for {$ref}: " . $e->getMessage());
    }

    // Call TechHub
    try {
        $statusResult = $techHubService->checkAsyncStatus($slug, $variant, $ticketId);
    } catch (Throwable $e) {
        cronLog($logPath, 'ERROR', "  Exception polling {$ref}: " . $e->getMessage());
        $errors++;
        $polled++;
        continue;
    }

    // Provider poll itself failed (HTTP/cURL error)
    if (!$statusResult['success']) {
        cronLog($logPath, 'WARN', "  Poll FAILED for {$ref}: " . ($statusResult['error_message'] ?? 'Unknown'));
        $errors++;
        $polled++;
        continue;
    }

    $pStatus  = $statusResult['provider_status'] ?? 'pending';
    $note     = $statusResult['response_note']   ?? null;
    $complete = $statusResult['is_complete']      ?? false;
    $isFailed = $statusResult['is_failed']        ?? false;

    if ($complete && !$isFailed) {
        // ── TERMINAL SUCCESS ──────────────────────────────────────────────────
        $resultData = !empty($statusResult['result_data'])
            ? json_encode($statusResult['result_data'])
            : null;

        try {
            $db->prepare("
                UPDATE api_transactions
                SET gv_status       = 'completed',
                    provider_status = 'success',
                    result_data     = ?,
                    completed_at    = NOW()
                WHERE id = ?
            ")->execute([$resultData, $tx['id']]);

            cronLog($logPath, 'OK  ', "  {$ref} => COMPLETED. note={$note}");
            $completed++;
        } catch (Throwable $e) {
            cronLog($logPath, 'ERROR', "  DB update failed for {$ref} (completed): " . $e->getMessage());
            $errors++;
        }

    } elseif ($isFailed) {
        // ── TERMINAL FAILURE ──────────────────────────────────────────────────
        //
        // FINANCIAL SAFETY: We DO NOT auto-refund here.
        // TechHub documentation says failed requests are "auto-refunded" by the provider.
        // We mark gv_status = 'failed' and let admin review or manually refund.
        // This prevents double-refund risk.
        //
        try {
            $db->prepare("
                UPDATE api_transactions
                SET gv_status               = 'failed',
                    provider_status         = 'failed',
                    provider_financial_status = 'charged',
                    error_message           = ?,
                    reconciliation_notes    = 'Provider reported failure during automated poll. Admin review required before any refund.',
                    completed_at            = NOW()
                WHERE id = ?
            ")->execute([$note ?? 'Provider processing failed', $tx['id']]);

            cronLog($logPath, 'WARN', "  {$ref} => FAILED. note={$note}. Admin review required.");
            $failed++;
        } catch (Throwable $e) {
            cronLog($logPath, 'ERROR', "  DB update failed for {$ref} (failed): " . $e->getMessage());
            $errors++;
        }

    } else {
        // ── STILL PENDING ─────────────────────────────────────────────────────
        cronLog($logPath, 'INFO', "  {$ref} => still pending (provider_status={$pStatus})");
        $pending++;
    }

    $polled++;
}

// ── Summary ────────────────────────────────────────────────────────────────────

$elapsed = round(microtime(true) - $startTime, 2);
cronLog($logPath, 'INFO', "=== SUMMARY: polled={$polled} completed={$completed} failed={$failed} pending={$pending} errors={$errors} elapsed={$elapsed}s ===");
cronLog($logPath, 'INFO', '=== GemVerify Async Poller END ===');
