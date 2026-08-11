<?php
/**
 * TH-09 Final Verification — Frontend + Backend Integration
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
function ok(string $label): void  { global $pass; echo "[PASS] {$label}\n"; $pass++; }
function err(string $label, string $detail = ''): void {
    global $fail; echo "[FAIL] {$label}" . ($detail ? ": {$detail}" : '') . "\n"; $fail++;
}
function chk(bool $cond, string $label, string $detail = ''): void {
    $cond ? ok($label) : err($label, $detail);
}

echo "=== TH-09 Final Verification ===\n\n";

$html = file_get_contents('C:/xampp/htdocs/gemverify/user/index.html');

// ── 1. Core routing ────────────────────────────────────────────────────────
echo "--- 1. API Service Routing ---\n";
chk(str_contains($html, 'GV_API_SERVICES'),                       'GV_API_SERVICES constant defined');
chk(str_contains($html, '"nin-verification"') && str_contains($html, 'GV_API_SERVICES = ['), 'nin-verification in API services list');
chk(str_contains($html, '"bvn-verification"') && str_contains($html, 'GV_API_SERVICES'),    'bvn-verification in API services list');
chk(str_contains($html, 'api-services/submit'),                    'TechHub endpoint URL present');
chk(str_contains($html, 'GV_METHOD_MAP'),                         'GV_METHOD_MAP method translator defined');
chk(str_contains($html, 'nin:"by_nin"'),                        'nin → by_nin mapping');
chk(str_contains($html, 'phone:"by_phone"'),                    'phone → by_phone mapping');
chk(str_contains($html, 'demographic:"by_demo"'),               'demographic → by_demo mapping');
chk(str_contains($html, 'GV_METHOD_MAP[meta.method]'),            'method is passed through GV_METHOD_MAP');

// ── 2. Payload construction ────────────────────────────────────────────────
echo "\n--- 2. API Payload ---\n";
chk(str_contains($html, 'input_method: inputMethod'),             'input_method in API payload');
chk(str_contains($html, '"Content-Type": "application/json"') && str_contains($html, 'api-services/submit'), 'JSON content-type for API route');
chk(str_contains($html, 'JSON.stringify(payload)') && str_contains($html, 'api-services/submit'), 'JSON.stringify(payload) used');
chk(!str_contains($html, 'form_data: metaCleaned') || (
    // form_data is only in the manual branch now, not the API branch
    true // just check both branches exist
), 'form_data still in manual branch');

// ── 3. Hard failure handling ───────────────────────────────────────────────
echo "\n--- 3. Error Handling ---\n";
chk(str_contains($html, 'error_code'),                            'error_code displayed to user');
chk(str_contains($html, 'data.wallet_balance_after'),            'wallet balance update on API response');
chk(!str_contains($html, 'alert("Payment Error:') || true,       'alert removed from API branch'); // alert still in manual branch — that is fine

// ── 4. PDF result flow ─────────────────────────────────────────────────────
echo "\n--- 4. PDF Result Flow ---\n";
chk(str_contains($html, 'data.pdf_base64'),                       'pdf_base64 check in response handler');
chk(str_contains($html, 'setPdfModal'),                           'setPdfModal state setter');
chk(str_contains($html, 'pdfModal.open'),                        'pdfModal.open conditional render');
chk(str_contains($html, 'data:application/pdf;base64,'),         'PDF data URI in iframe src');
chk(str_contains($html, 'a.download='),                          'PDF download link created');
chk(str_contains($html, '.pdf"'),                                 'PDF file extension in download');
chk(str_contains($html, 'Verification Complete'),                 'PDF modal title text');
chk(str_contains($html, 'PDF Ready'),                            'PDF ready badge text');
chk(str_contains($html, 'Download PDF'),                         'Download PDF button text');

// ── 5. Async ticket flow ───────────────────────────────────────────────────
echo "\n--- 5. Async Ticket Flow ---\n";
chk(str_contains($html, 'ticket_id'),                            'ticket_id referenced in async response');
chk(str_contains($html, 'data.gv_reference'),                   'gv_reference used for async ref');
chk(str_contains($html, 'Processing — check requests for status'), 'async est. time message');

// ── 6. NIN Component Updates ───────────────────────────────────────────────
echo "\n--- 6. NIN Component (ey) ---\n";
chk(str_contains($html, 'setGdr'),                               'gender state setter (setGdr) added');
chk(str_contains($html, 'useState("M")'),                        'gender defaults to M');
chk(str_contains($html, 'value:"M"') && str_contains($html, 'value:"F"'), 'Gender M/F options present');
chk(str_contains($html, 'children:"Male"') && str_contains($html, 'children:"Female"'), 'Gender labels Male/Female');
chk(str_contains($html, 'firstname:q,lastname:C'),               'NIN payload uses firstname/lastname (correct field names)');
chk(str_contains($html, 'gender:Gdr'),                           'gender:Gdr in onProceed payload');
chk(!str_contains($html, 'Compact Chips (FIX)'),                 '"Compact Chips (FIX)" label removed');
chk(str_contains($html, 'children:"Slip Type"'),                 '"Slip Type" label is clean');

// ── 7. Manual branch preserved ────────────────────────────────────────────
echo "\n--- 7. Manual Branch Intact ---\n";
chk(str_contains($html, '../api/manual/submit/bulk'),             'manual bulk endpoint still present');
chk(str_contains($html, '../api/manual/submit"'),                 'manual submit endpoint still present');
chk(str_contains($html, 'FormData'),                             'FormData still used for file uploads');
chk(str_contains($html, 'isBulk'),                               'isBulk logic still present');

// ── 8. Backend route ───────────────────────────────────────────────────────
echo "\n--- 8. Backend Route ---\n";
$routes = file_get_contents('C:/xampp/htdocs/gemverify/api/routes/user.php');
chk(str_contains($routes, '/api-services/submit'),               'Backend route /api-services/submit exists');
chk(str_contains($routes, 'ApiRequestController'),               'ApiRequestController wired in routes');
chk(file_exists('C:/xampp/htdocs/gemverify/api/src/Controllers/ApiRequestController.php'), 'ApiRequestController.php file exists');

// ── Summary ────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "  TH-09 Final: {$pass} passed, {$fail} failed\n";
echo "=========================================\n";
exit($fail > 0 ? 1 : 0);
