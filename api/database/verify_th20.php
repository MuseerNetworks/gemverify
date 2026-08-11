<?php
/**
 * TH-20: Phase 5 Self-Service — Full Stack Verification
 */
declare(strict_types=1);
$pass = 0; $fail = 0;
function ok($l){global $pass;echo "[PASS] $l\n";$pass++;}
function er($l,$d=''){global $fail;echo "[FAIL] $l".($d?": $d":'')."\n";$fail++;}
function chk($c,$l,$d=''){$c?ok($l):er($l,$d);}

echo "=== TH-20: Phase 5 Self-Service Verification ===\n\n";

$html   = file_get_contents('C:/xampp/htdocs/gemverify/user/index.html');
$svc    = file_get_contents('C:/xampp/htdocs/gemverify/api/src/Services/TechHubService.php');
$ctrl   = file_get_contents('C:/xampp/htdocs/gemverify/api/src/Controllers/ApiRequestController.php');
$routes = file_get_contents('C:/xampp/htdocs/gemverify/api/routes/user.php');

// Extract uy() component
$uyStart = strpos($html, 'function uy({onProceed:e})');
$uyEnd   = strpos($html, 'function Qy({onProceed:e})');
$uy = ($uyStart !== false && $uyEnd !== false) ? substr($html, $uyStart, $uyEnd - $uyStart) : '';

// Get GV_API_SERVICES line
$apiPos  = strpos($html, 'let GV_API_SERVICES = [');
$apiLine = $apiPos !== false ? substr($html, $apiPos, 200) : '';

// ── Backend: TechHubService self-service mapping ────────────────────────────
echo "--- 1. Backend: TechHubService Self-Service Mapping ---\n";
chk(str_contains($svc, "'self-service'"),                           'self-service in ASYNC_ENDPOINT_MAP');
chk(str_contains($svc, "'Delinking Email' => 'delinking.php'"),     'Delinking Email → delinking.php');
chk(str_contains($svc, "RESULT_TYPE_MAP") && str_contains($svc, "'self-service'         => 'ticket'"),
                                                                     'self-service result type = ticket');
chk(str_contains($svc, "'self-service' =>\n") || str_contains($svc, "'self-service' => ["),
                                                                     'self-service in resolveEndpoint');
chk(str_contains($svc, "variantKey === 'Delinking Email'"),         'Delinking Email variant check in resolveEndpoint');
chk(str_contains($svc, "self-service variant") && str_contains($svc, "is not a TechHub service"),
    'Retrieval NIN Details throws (not TechHub)');
chk(str_contains($svc, "'self-service' =>\n            [\n") || str_contains($svc, "'self-service' => [\n"),
    'self-service async payload built'
);
chk(str_contains($svc, "buildAsyncPayload") && str_contains($svc, "'nin'"),
    'self-service async payload built: nin field');
chk(str_contains($svc, "'email' => trim(\$formData['email']"),
    'email field sanitised in self-service payload');

// ── Backend: ApiRequestController self-service routing ──────────────────────
echo "\n--- 2. Backend: Controller Self-Service Routing ---\n";
chk(str_contains($ctrl, "MANUAL_VARIANT_OVERRIDES"),                'MANUAL_VARIANT_OVERRIDES defined');
chk(str_contains($ctrl, "'self-service' => ['Retrieval NIN Details']"),
                                                                     'Retrieval NIN Details in MANUAL_VARIANT_OVERRIDES');
chk(str_contains($ctrl, "isManualVariantOverride"),                  'isManualVariantOverride() called');
chk(str_contains($ctrl, "delegateToManualEngine"),                   'delegateToManualEngine() exists');
chk(str_contains($ctrl, "'self-service' =>\n") || str_contains($ctrl, "'self-service' => ["),
                                                                     'self-service in REQUIRED_FIELDS');
chk(str_contains($ctrl, "'Delinking Email'        => ['nin', 'email']"),
                                                                     'REQUIRED_FIELDS: Delinking needs nin+email');
chk(str_contains($ctrl, "'Retrieval NIN Details'  => []"),           'REQUIRED_FIELDS: Retrieval uses manual engine validation');

// ── Backend: Route ──────────────────────────────────────────────────────────
echo "\n--- 3. Backend: Route ---\n";
chk(str_contains($routes, "'/api-services/submit'"),                 'POST /api-services/submit route exists');
chk(str_contains($routes, 'ApiRequestController'),                   'ApiRequestController wired');

// ── Frontend: GV_API_SERVICES ───────────────────────────────────────────────
echo "\n--- 4. Frontend: GV_API_SERVICES Routing ---\n";
chk(str_contains($apiLine, '"self-service"'),                        '"self-service" in GV_API_SERVICES');
chk(str_contains($apiLine, '"nin-verification"'),                    '"nin-verification" still in GV_API_SERVICES');
chk(str_contains($apiLine, '"bvn-verification"'),                    '"bvn-verification" still in GV_API_SERVICES');
chk(str_contains($apiLine, '"personalization"'),                     '"personalization" still in GV_API_SERVICES');
chk(str_contains($apiLine, '"bvn-retrieval"'),                       '"bvn-retrieval" still in GV_API_SERVICES');
chk(str_contains($apiLine, '"ipe-clearance-single"'),                '"ipe-clearance-single" still in GV_API_SERVICES');

// ── Frontend: uy() Self-Service Component ───────────────────────────────────
echo "\n--- 5. Frontend: uy() Self-Service Component ---\n";
chk(!empty($uy),                                                     'uy() component found');
chk(str_contains($uy, '"self-service"'),                             '"self-service" slug in onProceed payload');
chk(str_contains($uy, 'variantKey:A'),                               'variantKey:A (selected type) in payload');
chk(str_contains($uy, 'nin:t'),                                      'nin:t in payload');
chk(str_contains($uy, 'email:l'),                                    'email:l in payload');
chk(str_contains($uy, 'pin:Q'),                                      'pin:Q in payload');

echo "\n  → Delinking Email path ---\n";
chk(str_contains($uy, '"Delinking Email"'),                          'Delinking Email option exists');
chk(str_contains($uy, 'Email to Delink'),                            '"Email to Delink" label in Delinking path');
chk(str_contains($uy, 'required for delinking'),                     'NIN field added to Delinking Email path');
chk(str_contains($uy, 'Enter email address to delink'),              'Email placeholder updated');

echo "\n  → Retrieval NIN Details path ---\n";
chk(str_contains($uy, '"Retrieval NIN Details"'),                    'Retrieval NIN Details option exists');
chk(str_contains($uy, 'NIN Number') && str_contains($uy, 'Enter NIN'),
                                                                     'NIN field in Retrieval path');

echo "\n  → Button guard ---\n";
chk(str_contains($uy, '!A||!isConfigured'),                         'Base disabled guard: !A||!isConfigured');
chk(str_contains($uy, '!t)||(A==="Delinking Email"&&!l)'),          'Enhanced guard: require nin+email for Delinking');
chk(str_contains($uy, 'Price not configured'),                       '"Price not configured" fallback');
chk(str_contains($uy, 'cursor-not-allowed'),                         'Button disabled when not configured');
chk(str_contains($uy, 'window.getServicePrice("self-service"'),      'Dynamic pricing via getServicePrice');
chk(str_contains($uy, 'isConfigured'),                               'isConfigured used in component');

// ── doSubmitPayment routing ─────────────────────────────────────────────────
echo "\n--- 6. Frontend: doSubmitPayment Routing ---\n";
chk(str_contains($html, 'GV_API_SERVICES.includes(serviceSlug)'),   'GV_API_SERVICES.includes() routing check');
chk(str_contains($html, '../api/api-services/submit'),               'API submit endpoint used');
chk(str_contains($html, 'data.pdf_base64'),                         'pdf_base64 handler');
chk(str_contains($html, 'data.ticket_id'),                          'ticket_id handler for async');
chk(str_contains($html, 'wallet_balance_after'),                    'Wallet balance updated after submit');

// ── Summary ─────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "  TH-20 Phase 5: $pass passed, $fail failed\n";
echo "=========================================\n";
exit($fail > 0 ? 1 : 0);
