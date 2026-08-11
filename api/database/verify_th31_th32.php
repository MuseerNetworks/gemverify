<?php
/**
 * TH-30 (static only) + TH-31/TH-32 combined:
 * - Static controller/routes/HTML checks (no DB required)
 * - TH-31: Grep for hardcoded TechHub credentials, API keys, base URLs
 * - TH-32: Security audit — no credentials in frontend files
 */
declare(strict_types=1);

$pass = 0; $fail = 0; $warn = 0;

function p(string $label, bool $ok, string $note = ''): void { global $pass,$fail; $ok?$pass++:$fail++; echo ($ok?'[PASS]':'[FAIL]')." $label".($note?" — $note":'')."\n"; }
function w(string $label, string $note = ''): void { global $warn; $warn++; echo "[WARN] $label".($note?" — $note":'')."\n"; }
function section(string $t): void { echo "\n--- $t ---\n"; }

$adminHtml  = file_get_contents('C:/xampp/htdocs/gemverify/admin/index.html');
$userHtml   = file_get_contents('C:/xampp/htdocs/gemverify/user/index.html');
$routesFile = file_get_contents('C:/xampp/htdocs/gemverify/api/routes/admin.php');
$controller = file_get_contents('C:/xampp/htdocs/gemverify/api/src/Controllers/Admin/ApiTransactionController.php');
$env        = file_get_contents('C:/xampp/htdocs/gemverify/api/.env') ?: '';
$envExample = file_get_contents('C:/xampp/htdocs/gemverify/api/.env.example') ?: '';

echo "=================================================================\n";
echo "  GemVerify — TH-30 (static) + TH-31/32 Security Audit\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "=================================================================\n";

// ── TH-30 Static checks ────────────────────────────────────────────────────────
section('TH-30: Admin UI (static)');
p('Nav button present', str_contains($adminHtml, 'data-page="api-transactions"'));
p('Page section ID', str_contains($adminHtml, 'id="page-api-transactions"'));
p('Stats widget', str_contains($adminHtml, 'atxn-stats'));
p('Table', str_contains($adminHtml, 'id="atxn-table"'));
p('Detail modal', str_contains($adminHtml, 'id="atxn-modal"'));
p('Refund modal', str_contains($adminHtml, 'id="atxn-refund-modal"'));
p('function fetchApiTransactions', str_contains($adminHtml, 'function fetchApiTransactions'));
p('function fetchApiTxnStats', str_contains($adminHtml, 'function fetchApiTxnStats'));
p('function renderAtxnTable', str_contains($adminHtml, 'function renderAtxnTable'));
p('function renderAtxnPagination', str_contains($adminHtml, 'function renderAtxnPagination'));
p('function viewAtxnDetail', str_contains($adminHtml, 'function viewAtxnDetail'));
p('function openAtxnRefundModal', str_contains($adminHtml, 'function openAtxnRefundModal'));
p('function confirmAtxnRefund', str_contains($adminHtml, 'function confirmAtxnRefund'));
p('API endpoint wired', str_contains($adminHtml, '../api/admin/api-transactions'));
p('refund-flag endpoint wired', str_contains($adminHtml, 'refund-flag'));

section('TH-30: Routes');
p('use ApiTransactionController', str_contains($routesFile, 'use Controllers\\Admin\\ApiTransactionController'));
p('GET /admin/api-transactions', str_contains($routesFile, "'/admin/api-transactions'"));
p('GET /admin/api-transactions/stats', str_contains($routesFile, "'/admin/api-transactions/stats'"));
p('GET /admin/api-transactions/{ref}', str_contains($routesFile, "'/admin/api-transactions/{ref}'"));
p('PATCH status route', str_contains($routesFile, "'/admin/api-transactions/{ref}/status'"));
p('POST refund-flag route', str_contains($routesFile, "'/admin/api-transactions/{ref}/refund-flag'"));

section('TH-30: Controller');
p('Namespace', str_contains($controller, 'namespace Controllers\\Admin'));
p('listTransactions()', str_contains($controller, 'public function listTransactions()'));
p('getStats()', str_contains($controller, 'public function getStats()'));
p('getDetail()', str_contains($controller, 'public function getDetail(string $ref)'));
p('overrideStatus()', str_contains($controller, 'public function overrideStatus(string $ref)'));
p('flagForRefund()', str_contains($controller, 'public function flagForRefund(string $ref)'));
p('AuditService logging', str_contains($controller, 'AuditService'));
p('PDF stripped from list', !preg_match("/'result_data'\s*=>/", $controller));
p('has_result boolean in detail', str_contains($controller, "'has_result'"));

// ── TH-31: Grep for hardcoded credentials ────────────────────────────────────
section('TH-31: Grep for hardcoded credentials/API data');

// Scan PHP backend files only (not node_modules, not .env)
$backendDir = 'C:/xampp/htdocs/gemverify/api/src';

// Patterns that should NOT appear in PHP source
$dangerous = [
    'HARDCODED TECHHUB API KEY' => '/api[_-]key\s*=\s*["\'][A-Za-z0-9]{20,}["\']/',
    'HARDCODED TECHHUB URL'     => '/https?:\/\/api\.techhub[a-z.]*\//i',
    'HARDCODED BEARER TOKEN'    => '/Bearer\s+[A-Za-z0-9\-_.]{30,}/',
];

$allPhpFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($backendDir, FilesystemIterator::SKIP_DOTS)
);

$credFindings = [];
foreach ($allPhpFiles as $file) {
    if ($file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getRealPath());
    foreach ($dangerous as $label => $pattern) {
        if (preg_match($pattern, $content, $match)) {
            $credFindings[] = "$label in " . basename($file->getRealPath()) . ": " . substr($match[0], 0, 60);
        }
    }
}

if (empty($credFindings)) {
    p('No hardcoded API credentials in src/', true);
} else {
    foreach ($credFindings as $f) p('Credential leak: ' . $f, false);
}

// ── TH-32: Frontend security audit ───────────────────────────────────────────
section('TH-32: Frontend security — no credentials in HTML');

$frontendDanger = [
    'TECHHUB_API_KEY in user portal'  => 'TECHHUB_API_KEY',
    'TECHHUB_API_KEY in admin portal' => 'TECHHUB_API_KEY',
    'Bearer hardcoded in user portal' => 'Bearer eyJ',
    'Bearer hardcoded in admin portal'=> 'Bearer eyJ',
    'Raw API key in user portal'      => 'api_key=',
    'Raw API key in admin portal'     => 'api_key=',
];

$frontendChecks = [
    'TECHHUB_API_KEY in user portal'   => [$userHtml,  'TECHHUB_API_KEY'],
    'TECHHUB_API_KEY in admin portal'  => [$adminHtml, 'TECHHUB_API_KEY'],
    'Bearer hardcoded in user portal'  => [$userHtml,  'Bearer eyJ'],
    'Bearer hardcoded in admin portal' => [$adminHtml, 'Bearer eyJ'],
    'Raw api_key= in user portal'      => [$userHtml,  'api_key='],
    'Raw api_key= in admin portal'     => [$adminHtml, 'api_key='],
    '.env content in user portal'      => [$userHtml,  'TECHHUB_BASE_URL'],
    '.env content in admin portal'     => [$adminHtml, 'TECHHUB_BASE_URL'],
];

foreach ($frontendChecks as $label => [$content, $needle]) {
    p($label, !str_contains($content, $needle), str_contains($content, $needle) ? 'EXPOSED!' : 'clean');
}

// TechHub base URL should only be in .env, not frontend
p('TechHub URL not in user portal', !preg_match('/techhub.*\.com/i', $userHtml));
p('TechHub URL not in admin portal', !preg_match('/techhub.*\.com/i', $adminHtml));

// .env must not be publicly accessible
section('TH-32: .env file security');
p('.env exists', file_exists('C:/xampp/htdocs/gemverify/api/.env'));
p('.env.example exists', file_exists('C:/xampp/htdocs/gemverify/api/.env.example'));
p('.env has TECHHUB_API_KEY placeholder or value', str_contains($env, 'TECHHUB_API_KEY'));
p('.env.example has TECHHUB_API_KEY', str_contains($envExample, 'TECHHUB_API_KEY'));

// Check .htaccess blocks .env
$htaccess = file_exists('C:/xampp/htdocs/gemverify/api/.htaccess')
    ? file_get_contents('C:/xampp/htdocs/gemverify/api/.htaccess')
    : (file_exists('C:/xampp/htdocs/gemverify/.htaccess') ? file_get_contents('C:/xampp/htdocs/gemverify/.htaccess') : '');
p('.htaccess exists', $htaccess !== '');
p('.htaccess blocks .env', str_contains($htaccess, '.env') || str_contains($htaccess, 'FilesMatch'));

// JWT secret not hardcoded in frontend
p('JWT_SECRET not in user portal', !str_contains($userHtml, 'gv_jwt_secret'));
p('JWT_SECRET not in admin portal', !str_contains($adminHtml, 'gv_jwt_secret'));

// Check for any direct TechHub endpoint paths in frontend (should only be in backend)
$techHubPaths = ['/nin/verification', '/bvn/verification', '/personalization', '/delinking'];
foreach ($techHubPaths as $path) {
    p("TechHub path '$path' not in user portal", !str_contains($userHtml, $path));
    p("TechHub path '$path' not in admin portal", !str_contains($adminHtml, $path));
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n=================================================================\n";
echo "  Result: {$pass} PASS, {$fail} FAIL, {$warn} WARN\n";
echo "=================================================================\n";
if ($fail === 0) echo "  ✓ TH-30 (static) + TH-31 + TH-32: ALL PASS\n";
exit($fail > 0 ? 1 : 0);
