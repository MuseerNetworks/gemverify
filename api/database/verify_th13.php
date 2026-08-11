<?php
/**
 * TH-13: Phase 3 BVN Verification — Full Stack Verification
 */
declare(strict_types=1);
$pass = 0; $fail = 0;
function ok($l){global $pass;echo "[PASS] $l\n";$pass++;}
function er($l,$d=''){global $fail;echo "[FAIL] $l".($d?": $d":'')."\n";$fail++;}
function chk($c,$l,$d=''){$c?ok($l):er($l,$d);}

echo "=== TH-13: Phase 3 BVN Full Verification ===\n\n";

$html = file_get_contents('C:/xampp/htdocs/gemverify/user/index.html');
$svc  = file_get_contents('C:/xampp/htdocs/gemverify/api/src/Services/TechHubService.php');
$ctrl = file_get_contents('C:/xampp/htdocs/gemverify/api/src/Controllers/ApiRequestController.php');
$routes = file_get_contents('C:/xampp/htdocs/gemverify/api/routes/user.php');

// ── Backend: TechHubService BVN mapping ───────────────────────────────────
echo "--- 1. Backend: BVN Endpoint Mapping ---\n";
chk(str_contains($svc, "'bvn-verification'"),                    'bvn-verification in SYNC_ENDPOINT_MAP');
chk(str_contains($svc, "'bvn_premium_slip.php'"),                'premium variant → bvn_premium_slip.php');
chk(str_contains($svc, "'bvn_full_details_slip.php'"),           'full variant → bvn_full_details_slip.php');
chk(str_contains($svc, "'bvn-verification'     => 'pdf_base64'"),'BVN result type = pdf_base64');
chk(str_contains($svc, "sanitiseBvn"),                           'sanitiseBvn() helper exists');
chk(str_contains($svc, "['bvn' =>"),                             'BVN sync payload = [bvn => ...]');

// ── Backend: Controller handles BVN ───────────────────────────────────────
echo "\n--- 2. Backend: Controller BVN Routing ---\n";
chk(str_contains($ctrl, "'bvn-verification'") || str_contains($ctrl, 'bvn'),
    'Controller has BVN references (via sync route)');
chk(str_contains($ctrl, 'submitSync'),                           'Controller calls submitSync for BVN');
chk(str_contains($ctrl, 'pdf_base64'),                           'Controller handles pdf_base64 response');
chk(str_contains($ctrl, 'gv_reference'),                        'Controller returns gv_reference');
chk(str_contains($ctrl, 'wallet_balance_after'),                 'Controller returns wallet_balance_after');

// ── Backend: Route ─────────────────────────────────────────────────────────
echo "\n--- 3. Backend: API Route ---\n";
chk(str_contains($routes, '/api-services/submit'),               'Route /api-services/submit exists');
chk(str_contains($routes, 'ApiRequestController'),               'ApiRequestController wired');

// ── Frontend: BVN Component ────────────────────────────────────────────────
echo "\n--- 4. Frontend: BVN Component (Qy) ---\n";
// Get just the Qy component
$qyStart = strpos($html, 'function Qy({onProceed:e})');
$qyEnd   = strpos($html, 'function dy({onProceed:e})');
$qy = ($qyStart !== false && $qyEnd !== false) ? substr($html, $qyStart, $qyEnd - $qyStart) : '';

chk(!empty($qy),                                                  'Qy() component found');
chk(str_contains($qy, '"bvn-verification"'),                      'bvn-verification slug in onProceed');
chk(str_contains($qy, 'bvn:l'),                                   'bvn:l field in payload');
chk(str_contains($qy, 'variantKey:A.id'),                         'variantKey:A.id in payload');
chk(str_contains($qy, 'pin:t'),                                   'pin:t in payload');
chk(str_contains($qy, 'currentPrice'),                            'currentPrice used (not hardcoded)');
chk(!str_contains($qy, 'Compact Chips'),                          '"Compact Chips" label removed');
chk(str_contains($qy, '"Slip Type"'),                             '"Slip Type" clean label');
chk(str_contains($qy, 'window.getServicePrice'),                  'Dynamic pricing via getServicePrice');
chk(str_contains($qy, 'isConfigured'),                            'isConfigured guard on submit button');
chk(str_contains($qy, 'Price not configured'),                    '"Price not configured" fallback text');
chk(str_contains($qy, 'cursor-not-allowed'),                      'Button disabled when not configured');
chk(str_contains($qy, '"bvn_premium_slip.php"') === false,        'No hardcoded endpoint in frontend');

// ── Frontend: Routing ──────────────────────────────────────────────────────
echo "\n--- 5. Frontend: API Service Routing ---\n";
// GV_API_SERVICES should include bvn-verification
$apiSvcPos = strpos($html, 'GV_API_SERVICES = [');
if ($apiSvcPos !== false) {
    $apiSvcChunk = substr($html, $apiSvcPos, 200);
    chk(str_contains($apiSvcChunk, '"bvn-verification"'),         'bvn-verification in GV_API_SERVICES');
} else {
    er('GV_API_SERVICES constant not found');
}
chk(str_contains($html, 'api-services/submit'),                   'TechHub submit endpoint in frontend');
chk(str_contains($html, 'data.pdf_base64'),                       'pdf_base64 handler in doSubmitPayment');
chk(str_contains($html, 'setPdfModal'),                           'PDF modal state in frontend');
chk(str_contains($html, 'Download PDF'),                          'Download PDF button in modal');

// ── Summary ────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "  TH-13 BVN Phase 3: $pass passed, $fail failed\n";
echo "=========================================\n";
exit($fail > 0 ? 1 : 0);
