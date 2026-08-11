<?php
/**
 * TH-22 + TH-24 + TH-26: Phase 6+7+8 Verification
 * NIN Personalization, BVN Retrieval, IPE Clearance Single
 */
declare(strict_types=1);
$pass = 0; $fail = 0;
function ok($l){global $pass;echo "[PASS] $l\n";$pass++;}
function er($l,$d=''){global $fail;echo "[FAIL] $l".($d?": $d":'')."\n";$fail++;}
function chk($c,$l,$d=''){$c?ok($l):er($l,$d);}

echo "=== TH-22/24/26: Phase 6+7+8 Async Services Verification ===\n\n";

$html = file_get_contents('C:/xampp/htdocs/gemverify/user/index.html');
$svc  = file_get_contents('C:/xampp/htdocs/gemverify/api/src/Services/TechHubService.php');
$ctrl = file_get_contents('C:/xampp/htdocs/gemverify/api/src/Controllers/ApiRequestController.php');

// Extract ly() (personalization)
$lyStart = strpos($html, 'function ly({onProceed:e})');
$iyStart = strpos($html, 'function iy({onProceed:e})');
$ly = ($lyStart && $iyStart) ? substr($html, $lyStart, $iyStart - $lyStart) : '';

// Extract cy() (bvn-retrieval)
$cyStart = strpos($html, 'function cy({onProceed:e})');
$hyStart = strpos($html, 'function hy({onProceed:e})');
$cy = ($cyStart && $hyStart) ? substr($html, $cyStart, $hyStart - $cyStart) : '';

// IPE clearance — search by slug
$ipePayloadPos = strpos($html, '"ipe-clearance-single"');
$ipeContext    = $ipePayloadPos !== false ? substr($html, max(0,$ipePayloadPos-300), 800) : '';

// GV_API_SERVICES
$apiPos  = strpos($html, 'let GV_API_SERVICES = [');
$apiLine = $apiPos !== false ? substr($html, $apiPos, 200) : '';

// ── Phase 6: NIN Personalization ───────────────────────────────────────────
echo "--- Phase 6: NIN Personalization (TH-21/22) ---\n";

echo "\n  → Backend: TechHubService ---\n";
chk(str_contains($svc, "'personalization'     => [null              => 'personalization.php']"),
    'personalization endpoint mapped in ASYNC_ENDPOINT_MAP');
chk(str_contains($svc, "'personalization'      => 'ticket'"),
    'personalization result type = ticket');
chk(str_contains($svc, "'tracking_id' => substr(trim(\$formData['tracking_id']"),
    'tracking_id in buildAsyncPayload for personalization');

echo "\n  → Backend: Controller ---\n";
chk(str_contains($ctrl, "'personalization' =>\n") || str_contains($ctrl, "'personalization' => ["),
    'personalization in REQUIRED_FIELDS');
chk(str_contains($ctrl, "'personalization'") && str_contains($ctrl, "'tracking_id'"),
    'tracking_id required for personalization');

echo "\n  → Frontend: ly() component ---\n";
chk(!empty($ly), 'ly() personalization component found');
chk(str_contains($ly, '"personalization"'), '"personalization" slug in payload');
chk(str_contains($ly, 'tracking_id:A'), 'tracking_id:A in payload (correct)');
chk(!str_contains($ly, 'trackingId:A'), 'trackingId gone from payload (camelCase removed)');
chk(str_contains($ly, 'Tracking ID'), '"Tracking ID" label visible');
chk(str_contains($ly, 'pin:l'), 'pin:l in payload');
chk(str_contains($ly, 'window.getServicePrice("personalization"'), 'Dynamic price via getServicePrice');
chk(str_contains($ly, 'isConfigured'), 'isConfigured guard');
chk(str_contains($ly, 'Price not configured'), '"Price not configured" fallback');

echo "\n  → GV_API_SERVICES ---\n";
chk(str_contains($apiLine, '"personalization"'), '"personalization" in GV_API_SERVICES');

// ── Phase 7: BVN Retrieval ─────────────────────────────────────────────────
echo "\n--- Phase 7: BVN Retrieval (TH-23/24) ---\n";

echo "\n  → Backend: TechHubService ---\n";
chk(str_contains($svc, "'bvn-retrieval'       => [null              => 'bvn_retrieval.php']"),
    'bvn-retrieval endpoint mapped in ASYNC_ENDPOINT_MAP');
chk(str_contains($svc, "'bvn-retrieval'        => 'ticket'"),
    'bvn-retrieval result type = ticket');
chk(str_contains($svc, "'first_name'   => trim(\$formData['first_name']"),
    'first_name in buildAsyncPayload for bvn-retrieval');
chk(str_contains($svc, "'last_name'    => trim(\$formData['last_name']"),
    'last_name in buildAsyncPayload for bvn-retrieval');
chk(str_contains($svc, "'phone_number' =>") && str_contains($svc, "sanitisePhone"),
    'phone_number (sanitised) in buildAsyncPayload');

echo "\n  → Backend: Controller ---\n";
chk(str_contains($ctrl, "'bvn-retrieval' =>\n") || str_contains($ctrl, "'bvn-retrieval' => ["),
    'bvn-retrieval in REQUIRED_FIELDS');
chk(str_contains($ctrl, "'first_name', 'last_name', 'phone_number'"),
    'REQUIRED_FIELDS: first_name, last_name, phone_number required');

echo "\n  → Frontend: cy() component ---\n";
chk(!empty($cy), 'cy() bvn-retrieval component found');
chk(str_contains($cy, '"bvn-retrieval"'), '"bvn-retrieval" slug in payload');
chk(str_contains($cy, 'first_name:A.first'), 'first_name:A.first in payload');
chk(str_contains($cy, 'last_name:A.last'), 'last_name:A.last in payload');
chk(str_contains($cy, 'phone_number:A.phone'), 'phone_number:A.phone in payload');
chk(!str_contains($cy, ',...A}'), 'Object spread (...A) removed from payload');
chk(str_contains($cy, 'First Name'), '"First Name" label');
chk(str_contains($cy, 'Last Name'), '"Last Name" label');
chk(str_contains($cy, 'Phone Number'), '"Phone Number" label');
chk(str_contains($cy, 'window.getServicePrice("bvn-retrieval"'), 'Dynamic price via getServicePrice');
chk(str_contains($cy, 'isConfigured'), 'isConfigured guard');
chk(str_contains($cy, 'Price not configured'), '"Price not configured" fallback');

echo "\n  → GV_API_SERVICES ---\n";
chk(str_contains($apiLine, '"bvn-retrieval"'), '"bvn-retrieval" in GV_API_SERVICES');

// ── Phase 8: IPE Clearance Single ─────────────────────────────────────────
echo "\n--- Phase 8: IPE Clearance Single (TH-25/26) ---\n";

echo "\n  → Backend: TechHubService ---\n";
chk(str_contains($svc, "'ipe-clearance-single'=> [null              => 'ipe_clearance.php']"),
    'ipe-clearance-single endpoint mapped in ASYNC_ENDPOINT_MAP');
chk(str_contains($svc, "'ipe-clearance-single' => 'ticket'"),
    'ipe-clearance-single result type = ticket');
chk(str_contains($svc, "'tracking_id' => substr(preg_replace"),
    'tracking_id sanitised in buildAsyncPayload for ipe-clearance-single');

echo "\n  → Backend: Controller ---\n";
chk(str_contains($ctrl, "'ipe-clearance-single' =>\n") || str_contains($ctrl, "'ipe-clearance-single' => ["),
    'ipe-clearance-single in REQUIRED_FIELDS');
chk(str_contains($ctrl, "'ipe-clearance-single'") && str_contains($ctrl, "'tracking_id'"),
    'tracking_id required for ipe-clearance-single');

echo "\n  → Frontend: IPE payload ---\n";
chk(!empty($ipeContext), 'IPE clearance-single payload found in frontend');
chk(str_contains($ipeContext, '"ipe-clearance-single"'), '"ipe-clearance-single" slug in onProceed');
chk(str_contains($ipeContext, 'tracking_id:l'), 'tracking_id:l in payload');
chk(str_contains($ipeContext, 'pin:t'), 'pin:t in payload');
chk(str_contains($ipeContext, 'isConfigured'), 'isConfigured guard in IPE');
chk(str_contains($ipeContext, 'Price not configured'), '"Price not configured" fallback');

echo "\n  → GV_API_SERVICES ---\n";
chk(str_contains($apiLine, '"ipe-clearance-single"'), '"ipe-clearance-single" in GV_API_SERVICES');

// ── Summary ────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "  TH-22/24/26 Phases 6+7+8: $pass passed, $fail failed\n";
echo "=========================================\n";
exit($fail > 0 ? 1 : 0);
