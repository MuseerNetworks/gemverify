<?php
/**
 * Phase 1 Foundation Verification
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

spl_autoload_register(function ($class) {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) require_once $file;
});

$pass = 0; $fail = 0;
function ok(string $label): void  { global $pass; echo "[PASS] {$label}\n"; $pass++; }
function err(string $label): void { global $fail; echo "[FAIL] {$label}\n"; $fail++; }
function chk(bool $cond, string $label): void { $cond ? ok($label) : err($label); }

echo "=== Phase 1 Foundation Verification ===\n\n";

// ── 1. Constants ────────────────────────────────────────────────────────────
echo "--- TechHub Constants ---\n";
chk(defined('TECHHUB_BASE_URL') && TECHHUB_BASE_URL !== '',
    'TECHHUB_BASE_URL defined: ' . (defined('TECHHUB_BASE_URL') ? TECHHUB_BASE_URL : 'NOT SET'));
chk(defined('TECHHUB_API_KEY') && strlen(TECHHUB_API_KEY) > 20,
    'TECHHUB_API_KEY set: ' . (defined('TECHHUB_API_KEY') ? substr(TECHHUB_API_KEY, 0, 8) . '....' . substr(TECHHUB_API_KEY, -6) : 'NOT SET'));
chk(defined('TECHHUB_TIMEOUT') && TECHHUB_TIMEOUT > 0,
    'TECHHUB_TIMEOUT defined: ' . (defined('TECHHUB_TIMEOUT') ? TECHHUB_TIMEOUT . 's' : 'NOT SET'));

// ── 2. Classes ─────────────────────────────────────────────────────────────
echo "\n--- Class Autoloading ---\n";
chk(class_exists('Providers\\TechHubClient'),  'TechHubClient class exists');
chk(class_exists('Services\\TechHubService'),  'TechHubService class exists');

// ── 3. TechHubClient config guard ──────────────────────────────────────────
echo "\n--- TechHubClient Config Guard ---\n";
$client = new Providers\TechHubClient();
chk($client->isConfigured(), 'TechHubClient::isConfigured() returns true');

// ── 4. TechHubService endpoint mapping ─────────────────────────────────────
echo "\n--- TechHubService Endpoint Map ---\n";
$svc   = new Services\TechHubService();
$tests = [
    ['nin-verification',      'premium',         'by_nin',   true],
    ['nin-verification',      'premium',         'by_phone', true],
    ['nin-verification',      'standard',        'by_demo',  true],
    ['nin-verification',      'regular',         'by_nin',   true],
    ['nin-verification',      'vnin',            'by_phone', true],
    ['bvn-verification',      'premium',         null,       true],
    ['bvn-verification',      'full',            null,       true],
    ['self-service',          'Delinking Email', null,       true],
    ['personalization',       null,              null,       true],
    ['bvn-retrieval',         null,              null,       true],
    ['ipe-clearance-single',  null,              null,       true],
    // Excluded — must FAIL mapping
    ['nin-verification',      'basic',           'by_nin',   false],
    ['self-service',          'Retrieval NIN Details', null, false],
    ['nin-validation',        null,              null,       false],
];
foreach ($tests as [$slug, $variant, $method, $expectValid]) {
    $r = $svc->validateMapping($slug, $variant, $method);
    $label = "{$slug}/{$variant}/{$method} → expect " . ($expectValid ? 'VALID' : 'INVALID');
    chk($r['valid'] === $expectValid, $label);
}

// ── 5. Result type classification ──────────────────────────────────────────
echo "\n--- Result Type Classification ---\n";
chk($svc->getResultType('nin-verification')     === 'pdf_base64', 'nin-verification → pdf_base64');
chk($svc->getResultType('bvn-verification')     === 'pdf_base64', 'bvn-verification → pdf_base64');
chk($svc->getResultType('self-service')         === 'ticket',     'self-service → ticket');
chk($svc->getResultType('personalization')      === 'ticket',     'personalization → ticket');
chk($svc->getResultType('bvn-retrieval')        === 'ticket',     'bvn-retrieval → ticket');
chk($svc->getResultType('ipe-clearance-single') === 'ticket',     'ipe-clearance-single → ticket');

// ── 6. Database state ──────────────────────────────────────────────────────
echo "\n--- DB State ---\n";
$db = db();

$tbl = $db->query("SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_transactions'")->fetchColumn();
chk((int)$tbl === 1, 'api_transactions table exists');

$col = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'provider_name'")->fetchColumn();
chk((int)$col === 1, 'provider_name column exists on services');

$cnt = (int)$db->query("SELECT COUNT(*) FROM services WHERE provider_name = 'techhub'")->fetchColumn();
chk($cnt === 6, "TechHub services seeded: {$cnt} (expect 6)");

$basicActive = $db->query("SELECT is_active FROM service_pricing WHERE id = 178")->fetchColumn();
chk((int)$basicActive === 0, "basic NIN variant (id=178) is disabled: is_active={$basicActive}");

$ninValManual = $db->query("SELECT is_manual FROM services WHERE slug = 'nin-validation'")->fetchColumn();
chk((int)$ninValManual === 1, "nin-validation remains manual: is_manual={$ninValManual}");

$selfSvcProvider = $db->query("SELECT provider_name FROM services WHERE slug = 'self-service'")->fetchColumn();
chk($selfSvcProvider === 'techhub', "self-service provider_name: {$selfSvcProvider}");

// Verify api_transactions columns
$cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_transactions'
    ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
$required = ['id','gv_reference','user_id','service_id','pricing_id','variant_key',
    'transaction_id','input_method','provider','provider_endpoint',
    'provider_ticket_id','gv_status','result_type','result_data',
    'idempotency_key','submitted_at','refund_issued'];
$missing = array_diff($required, $cols);
chk(empty($missing), 'api_transactions has all required columns' . (empty($missing) ? '' : ': MISSING ' . implode(', ', $missing)));

// ── Summary ────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "  Phase 1: {$pass} passed, {$fail} failed\n";
echo "=========================================\n";
exit($fail > 0 ? 1 : 0);
