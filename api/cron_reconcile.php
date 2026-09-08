<?php
declare(strict_types=1);

/**
 * GemVerify — Automated Reconciliation Worker (Cron Script)
 *
 * Automatically reconciles transactions in 'reconciliation_required',
 * 'failed' (unrefunded), or stalled 'processing' states.
 * Enforces the 15-Minute SLA Safeguard for sync slip timeouts.
 *
 * Run this via Windows Task Scheduler or crontab every 2 to 5 minutes:
 *   "c:\xampp\php\php.exe" "c:\xampp\htdocs\gemverify\api\cron_reconcile.php"
 *
 * Optional CLI arguments:
 *   --limit=50      Max transactions to process per sweep (default: 50)
 *   --sla=15        SLA window in minutes before auto-refund (default: 15)
 *   --dry-run       Audit candidates without making changes
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: This script must be run from command line only.\n";
    exit(1);
}

define('RUNNING_MIGRATION', true);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/src/' . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

$logPath = __DIR__ . '/logs/cron_reconcile.log';
if (!is_dir(dirname($logPath))) {
    mkdir(dirname($logPath), 0755, true);
}

function reconcileLog(string $logPath, string $level, string $msg): void
{
    $line = sprintf("[%s] [%-5s] %s\n", date('Y-m-d H:i:s'), $level, $msg);
    echo $line;
    file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

// ── Parse CLI Arguments ───────────────────────────────────────────────────────
$options = [
    'limit'              => 50,
    'sla_minutes'        => 15,
    'actor'              => 'system_cron',
    'include_processing' => true,
    'dry_run'            => false,
];

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $options['limit'] = max(1, (int)substr($arg, 8));
    } elseif (str_starts_with($arg, '--sla=')) {
        $options['sla_minutes'] = max(1, (int)substr($arg, 6));
    } elseif ($arg === '--dry-run') {
        $options['dry_run'] = true;
    }
}

$startTime = microtime(true);
reconcileLog($logPath, 'INFO', "=== GemVerify Reconciliation Worker START (limit={$options['limit']}, sla={$options['sla_minutes']}m, dry_run=" . ($options['dry_run'] ? 'yes' : 'no') . ") ===");

try {
    $db = db();
    $service = new \Services\ReconciliationService($db);
    $report = $service->runBatch($options);

    reconcileLog($logPath, 'INFO', "Found {$report['total_found']} candidate(s) for reconciliation.");

    foreach ($report['items'] as $item) {
        $ref    = $item['gv_reference'];
        $action = $item['action'] ?? ($item['dry_run'] ? 'dry_run' : 'none');
        $msg    = $item['message'] ?? ($item['error'] ?? '');

        if (str_starts_with($action, 'refunded')) {
            $amt = isset($item['refund_amount']) ? '₦' . number_format($item['refund_amount'], 2) : '';
            reconcileLog($logPath, 'OK   ', "  {$ref} => REFUNDED {$amt}. {$msg}");
        } elseif ($action === 'completed') {
            reconcileLog($logPath, 'OK   ', "  {$ref} => COMPLETED. {$msg}");
        } elseif ($action === 'still_pending' || $action === 'pending_sla_window') {
            reconcileLog($logPath, 'INFO ', "  {$ref} => PENDING. {$msg}");
        } elseif ($action === 'error') {
            reconcileLog($logPath, 'ERROR', "  {$ref} => ERROR: {$msg}");
        } else {
            reconcileLog($logPath, 'INFO ', "  {$ref} => {$action}. {$msg}");
        }
    }

    $elapsed = round(microtime(true) - $startTime, 2);
    reconcileLog(
        $logPath,
        'INFO',
        "=== SUMMARY: found={$report['total_found']} refunded={$report['refunded']} completed={$report['completed']} pending={$report['still_pending']} errors={$report['errors']} elapsed={$elapsed}s ==="
    );
    reconcileLog($logPath, 'INFO', "=== GemVerify Reconciliation Worker END ===");

} catch (Throwable $e) {
    reconcileLog($logPath, 'ERROR', "Fatal exception in reconciliation worker: " . $e->getMessage());
    exit(1);
}
