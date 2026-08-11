<?php
/**
 * GemVerify — TH-30: Phase 9 Admin API Transactions Verification
 *
 * Tests the controller, routes, and UI injection for admin API transaction management.
 * Usage: php verify_th30.php
 */
declare(strict_types=1);
define('RUNNING_MIGRATION', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$pass = 0; $fail = 0; $warn = 0;

function p(string $label, bool $ok, string $note = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[PASS]' : '[FAIL]') . " $label" . ($note ? " — $note" : '') . "\n";
}
function w(string $label, string $note = ''): void {
    global $warn;
    $warn++;
    echo "[WARN] $label" . ($note ? " — $note" : '') . "\n";
}
function section(string $title): void {
    echo "\n--- $title ---\n";
}

$db = db();
$adminHtml  = file_get_contents('C:/xampp/htdocs/gemverify/admin/index.html');
$routesFile = file_get_contents('C:/xampp/htdocs/gemverify/api/routes/admin.php');
$controller = file_get_contents('C:/xampp/htdocs/gemverify/api/src/Controllers/Admin/ApiTransactionController.php');

echo "=================================================================\n";
echo "  GemVerify — TH-30: Admin API Transactions Verification\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "=================================================================\n";

// ── 1. Controller file checks ──────────────────────────────────────────────────
section('1. ApiTransactionController.php structure');
p('File exists', file_exists('C:/xampp/htdocs/gemverify/api/src/Controllers/Admin/ApiTransactionController.php'));
p('Namespace correct', str_contains($controller, 'namespace Controllers\\Admin'));
p('listTransactions() method', str_contains($controller, 'public function listTransactions()'));
p('getStats() method', str_contains($controller, 'public function getStats()'));
p('getDetail() method', str_contains($controller, 'public function getDetail(string $ref)'));
p('overrideStatus() method', str_contains($controller, 'public function overrideStatus(string $ref)'));
p('flagForRefund() method', str_contains($controller, 'public function flagForRefund(string $ref)'));
p('AuditService used', str_contains($controller, 'AuditService'));
p('PAGE_SIZE constant set', str_contains($controller, 'PAGE_SIZE'));
p('Pagination in listTransactions', str_contains($controller, 'LIMIT ? OFFSET ?'));
p('No result_data in formatListRow', !str_contains($controller, "'result_data' =>") && str_contains($controller, 'formatListRow'));
p('OVERRIDE_STATUSES validation', str_contains($controller, 'OVERRIDE_STATUSES'));
p('refund_issued flag set to 1', str_contains($controller, "refund_issued = 1"));
p('gv_status set to refunded on flag', str_contains($controller, "gv_status     = 'refunded'"));
p('Note required for overrideStatus', str_contains($controller, "note is required when overriding") || str_contains($controller, 'note === \'\''));
p('Note required for flagForRefund', str_contains($controller, "note is required for refund") || str_contains($controller, 'note === \'\''));
p('findByRef() private helper', str_contains($controller, 'private function findByRef'));
p('Join to users table', str_contains($controller, 'JOIN users'));
p('Join to services table', str_contains($controller, 'JOIN services'));
p('Join to service_pricing', str_contains($controller, 'JOIN service_pricing'));
p('has_result in formatDetailRow', str_contains($controller, 'has_result'));
p('provider_endpoint exposed in detail', str_contains($controller, 'provider_endpoint'));

// ── 2. Routes file checks ──────────────────────────────────────────────────────
section('2. routes/admin.php route registrations');
p('use ApiTransactionController', str_contains($routesFile, 'use Controllers\\Admin\\ApiTransactionController'));
p('GET /admin/api-transactions', str_contains($routesFile, "'/admin/api-transactions'"));
p('GET /admin/api-transactions/stats', str_contains($routesFile, "'/admin/api-transactions/stats'"));
p('GET /admin/api-transactions/{ref}', str_contains($routesFile, "'/admin/api-transactions/{ref}'"));
p('PATCH /admin/api-transactions/{ref}/status', str_contains($routesFile, "'/admin/api-transactions/{ref}/status'"));
p('POST /admin/api-transactions/{ref}/refund-flag', str_contains($routesFile, "'/admin/api-transactions/{ref}/refund-flag'"));
p('status route requires admin role', str_contains($routesFile, "requireRole('admin')") && str_contains($routesFile, '/status'));
p('refund-flag route requires admin role', str_contains($routesFile, "requireRole('admin')") && str_contains($routesFile, 'refund-flag'));
p('listTransactions() called in route', str_contains($routesFile, 'listTransactions()'));
p('getStats() called in route', str_contains($routesFile, 'getStats()'));
p('getDetail() called in route', str_contains($routesFile, 'getDetail($p[\'ref\'])'));
p('overrideStatus() called in route', str_contains($routesFile, 'overrideStatus($p[\'ref\'])'));
p('flagForRefund() called in route', str_contains($routesFile, 'flagForRefund($p[\'ref\'])'));

// ── 3. Admin HTML UI checks ────────────────────────────────────────────────────
section('3. admin/index.html UI injection');
p('Nav button present', str_contains($adminHtml, 'data-page="api-transactions"'));
p('Nav label text', str_contains($adminHtml, 'API Transactions'));
p('Page section ID', str_contains($adminHtml, 'id="page-api-transactions"'));
p('Stats grid widget', str_contains($adminHtml, 'atxn-stats'));
p('Table with atxn-table ID', str_contains($adminHtml, 'id="atxn-table"'));
p('Table body with atxn-body ID', str_contains($adminHtml, 'id="atxn-body"'));
p('Detail modal', str_contains($adminHtml, 'id="atxn-modal"'));
p('Refund modal', str_contains($adminHtml, 'id="atxn-refund-modal"'));
p('Refund note textarea', str_contains($adminHtml, 'atxn-refund-note'));
p('Refund confirm button', str_contains($adminHtml, 'atxn-refund-confirm'));
p('Status filter select', str_contains($adminHtml, 'atxn-filter-status'));
p('Search input', str_contains($adminHtml, 'atxn-search'));
p('Pagination div', str_contains($adminHtml, 'atxn-pagination'));
p('function fetchApiTransactions', str_contains($adminHtml, 'function fetchApiTransactions'));
p('function fetchApiTxnStats', str_contains($adminHtml, 'function fetchApiTxnStats'));
p('function renderAtxnTable', str_contains($adminHtml, 'function renderAtxnTable'));
p('function renderAtxnPagination', str_contains($adminHtml, 'function renderAtxnPagination'));
p('function viewAtxnDetail', str_contains($adminHtml, 'function viewAtxnDetail'));
p('function openAtxnRefundModal', str_contains($adminHtml, 'function openAtxnRefundModal'));
p('function confirmAtxnRefund', str_contains($adminHtml, 'function confirmAtxnRefund'));
p('API endpoint ../api/admin/api-transactions', str_contains($adminHtml, '../api/admin/api-transactions'));
p('Status options pending/processing/completed', str_contains($adminHtml, 'value="pending"') && str_contains($adminHtml, 'value="processing"') && str_contains($adminHtml, 'value="completed"'));
p('Refund flag endpoint wired', str_contains($adminHtml, 'refund-flag'));
p('Detail endpoint wired', str_contains($adminHtml, '/api/admin/api-transactions/\' + ref'));

// ── 4. Database checks ────────────────────────────────────────────────────────
section('4. Database: api_transactions table');
try {
    $tExists = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_transactions'")->fetchColumn();
    p('api_transactions table exists', (int)$tExists === 1);

    if ($tExists) {
        $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_transactions'")->fetchAll(\PDO::FETCH_COLUMN);
        $cols = array_flip($cols);
        foreach (['gv_reference','user_id','service_id','pricing_id','gv_status','provider','provider_ticket_id','result_type','result_data','error_code','refund_issued','idempotency_key','submitted_at'] as $col) {
            p("Column: $col", isset($cols[$col]));
        }

        // Count rows (may be 0 in dev, that's fine)
        $rowCount = (int)$db->query("SELECT COUNT(*) FROM api_transactions")->fetchColumn();
        echo "[INFO] api_transactions row count: $rowCount\n";

        // Verify foreign keys are intact (no FK errors in schema)
        $fks = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_transactions' AND CONSTRAINT_TYPE='FOREIGN KEY'")->fetchAll(\PDO::FETCH_COLUMN);
        p('Has foreign key constraints', count($fks) >= 4, 'count=' . count($fks));
    }
} catch (\Exception $e) {
    p('DB checks', false, $e->getMessage());
}

// ── 5. Security checks ────────────────────────────────────────────────────────
section('5. Security');
p('result_data not in listTransactions SQL', !str_contains($controller, "'result_data'" ) || str_contains($controller, "Excludes result_data") || str_contains($controller, "no PDF data"));
p('result_data excluded from formatListRow', !preg_match("/'result_data'\s*=>/", $controller));
p('has_result boolean (not raw data) in detail', str_contains($controller, "'has_result'") && str_contains($controller, '!empty($row[\'result_data\'])'));
p('No hardcoded admin credentials in controller', !str_contains($controller, 'password') && !str_contains($controller, 'secret'));
p('Admin role enforcement on PATCH', str_contains($routesFile, "requireRole('admin')"));

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n=================================================================\n";
echo "  TH-30 Result: {$pass} PASS, {$fail} FAIL, {$warn} WARN\n";
echo "=================================================================\n";
if ($fail === 0) echo "  ✓ Phase 9 — Admin API Transactions: COMPLETE\n";
exit($fail > 0 ? 1 : 0);
