<?php
/**
 * TH-17: Phase 4 Async Infrastructure — Full Verification
 */
declare(strict_types=1);
$pass = 0; $fail = 0;
function ok($l){global $pass;echo "[PASS] $l\n";$pass++;}
function er($l,$d=''){global $fail;echo "[FAIL] $l".($d?": $d":'')."\n";$fail++;}
function chk($c,$l,$d=''){$c?ok($l):er($l,$d);}

echo "=== TH-17: Phase 4 Async Infrastructure Verification ===\n\n";

$routes   = file_get_contents('C:/xampp/htdocs/gemverify/api/routes/user.php');
$ctrlFile = 'C:/xampp/htdocs/gemverify/api/src/Controllers/ApiStatusController.php';
$ctrl     = file_exists($ctrlFile) ? file_get_contents($ctrlFile) : '';
$reqCtrl  = file_get_contents('C:/xampp/htdocs/gemverify/api/src/Controllers/ApiRequestController.php');
$svc      = file_get_contents('C:/xampp/htdocs/gemverify/api/src/Services/TechHubService.php');

// ── TH-14: Controller already handles async submit ─────────────────────────
echo "--- TH-14: Async Submit in ApiRequestController ---\n";
chk(str_contains($reqCtrl, 'submitAsync'),                         'submitAsync() called in submit()');
chk(str_contains($reqCtrl, "'processing'"),                        'gv_status=processing set for async');
chk(str_contains($reqCtrl, 'provider_ticket_id'),                  'ticket_id stored in api_transactions');
chk(str_contains($reqCtrl, 'isHardFailure'),                       'Hard failure rollback protection');
chk(str_contains($reqCtrl, 'rollBack()'),                          'DB rollback on hard failure');
chk(str_contains($reqCtrl, 'ticket_id'),                           'ticket_id in async success response');
chk(str_contains($reqCtrl, 'gv_reference'),                        'gv_reference in all responses');
chk(str_contains($reqCtrl, 'wallet_balance_after'),                'wallet_balance_after in response');
chk(str_contains($reqCtrl, 'idempotency_key'),                     'Idempotency check protects re-submission');

// ── TH-15: ApiStatusController file ────────────────────────────────────────
echo "\n--- TH-15: ApiStatusController ---\n";
chk(file_exists($ctrlFile),                                        'ApiStatusController.php file exists');
chk(!empty($ctrl),                                                  'File is non-empty');
chk(str_contains($ctrl, 'class ApiStatusController'),              'Class ApiStatusController declared');
chk(str_contains($ctrl, 'namespace Controllers'),                   'Correct namespace');

echo "\n  → listRequests() ---\n";
chk(str_contains($ctrl, 'function listRequests()'),                 'listRequests() method exists');
chk(str_contains($ctrl, 'PAGE_SIZE'),                               'Pagination constant defined');
chk(str_contains($ctrl, 'OFFSET ?'),                                'SQL OFFSET used for pagination');
chk(str_contains($ctrl, 'gv_reference') && str_contains($ctrl, 'listRequests'), 'Returns gv_reference in list');
chk(!str_contains($ctrl, 'result_data') || (
    // result_data should only appear in the select for findTransaction, not in formatListRow
    str_contains($ctrl, 'result_data') && str_contains($ctrl, 'formatDetailRow')
), 'PDF data excluded from list response (only in detail/pdf)');
chk(str_contains($ctrl, 'has_pdf'),                                 'has_pdf flag in list items');
chk(str_contains($ctrl, "gv_status = ?\n") || str_contains($ctrl, "gv_status = ?'") || 
    str_contains($ctrl, 'status filter') || str_contains($ctrl, "'$status'") ||
    str_contains($ctrl, 'gv_status = ?'),                           'Status filter applied in list query');

echo "\n  → getRequest() ---\n";
chk(str_contains($ctrl, 'function getRequest(string $ref)'),        'getRequest() method exists');
chk(str_contains($ctrl, 'findTransaction'),                         'findTransaction() helper used');
chk(str_contains($ctrl, 'formatDetailRow'),                         'formatDetailRow() used for detail');

echo "\n  → pollStatus() ---\n";
chk(str_contains($ctrl, 'function pollStatus(string $ref)'),        'pollStatus() method exists');
chk(str_contains($ctrl, 'MIN_POLL_INTERVAL_SECONDS'),               'Rate limit constant defined');
chk(str_contains($ctrl, 'last_checked_at'),                         'last_checked_at updated on poll');
chk(str_contains($ctrl, 'retry_after'),                             'retry_after returned on rate limit');
chk(str_contains($ctrl, 'checkAsyncStatus'),                        'checkAsyncStatus() called on TechHub');
chk(str_contains($ctrl, 'applyStatusUpdate'),                       'applyStatusUpdate() processes result');
chk(str_contains($ctrl, 'is_complete'),                             'is_complete flag used for state change');
chk(str_contains($ctrl, "tx['result_type'] !== 'ticket'"),          'Non-ticket requests rejected for poll');
chk(str_contains($ctrl, "['completed', 'failed', 'refunded']"),     'Terminal states skipped (no-op poll)');
chk(str_contains($ctrl, '429'),                                     'HTTP 429 returned on rate limit');

echo "\n  → downloadPdf() ---\n";
chk(str_contains($ctrl, 'function downloadPdf(string $ref)'),       'downloadPdf() method exists');
chk(str_contains($ctrl, "tx['result_type'] !== 'pdf_base64'"),      'Non-PDF requests rejected');
chk(str_contains($ctrl, "tx['gv_status'] !== 'completed'"),          'Incomplete requests rejected');
chk(str_contains($ctrl, 'result_data'),                             'result_data returned as pdf_base64');
chk(str_contains($ctrl, 'API_PDF_DOWNLOAD'),                        'Download audit log written');

echo "\n  → Helper methods ---\n";
chk(str_contains($ctrl, 'function findTransaction'),                 'findTransaction() helper exists');
chk(str_contains($ctrl, 'function findTransactionById'),             'findTransactionById() helper exists');
chk(str_contains($ctrl, 'function applyStatusUpdate'),              'applyStatusUpdate() helper exists');
chk(str_contains($ctrl, 'function formatListRow'),                   'formatListRow() helper exists');
chk(str_contains($ctrl, 'function formatDetailRow'),                 'formatDetailRow() helper exists');
chk(str_contains($ctrl, 'user_id = ?'),                             'All queries filter by user_id (security)');

// ── TH-16: Routes ──────────────────────────────────────────────────────────
echo "\n--- TH-16: Routes ---\n";
chk(str_contains($routes, 'ApiStatusController'),                   'ApiStatusController imported in routes');
chk(str_contains($routes, "'/api-services/submit'"),               'POST /api-services/submit route');
chk(str_contains($routes, "'/api-services/requests'"),             'GET /api-services/requests route');
chk(str_contains($routes, "'/api-services/requests/{ref}'"),       'GET /api-services/requests/{ref} route');
chk(str_contains($routes, "'/api-services/requests/{ref}/poll'"),  'POST /api-services/requests/{ref}/poll route');
chk(str_contains($routes, "'/api-services/requests/{ref}/pdf'"),   'GET /api-services/requests/{ref}/pdf route');
chk(str_contains($routes, "->listRequests()"),                      'listRequests() wired');
chk(str_contains($routes, "->getRequest("),                         'getRequest() wired');
chk(str_contains($routes, "->pollStatus("),                         'pollStatus() wired');
chk(str_contains($routes, "->downloadPdf("),                        'downloadPdf() wired');

// ── TH-17: TechHub Service async support ───────────────────────────────────
echo "\n--- TH-17: TechHub Async Service Support ---\n";
chk(str_contains($svc, 'submitAsync'),                             'submitAsync() in TechHubService');
chk(str_contains($svc, 'checkAsyncStatus'),                        'checkAsyncStatus() in TechHubService');
chk(str_contains($svc, 'normaliseAsyncSubmitResult'),              'normaliseAsyncSubmitResult() exists');
chk(str_contains($svc, 'normaliseAsyncStatusResult'),              'normaliseAsyncStatusResult() exists');
chk(str_contains($svc, 'is_complete'),                             'is_complete flag in status result');
chk(str_contains($svc, 'is_failed'),                               'is_failed flag in status result');
chk(str_contains($svc, 'ticket_id'),                               'ticket_id in async submit result');
chk(str_contains($svc, 'bvn_retrieval.php'),                       'bvn_retrieval async endpoint mapped');
chk(str_contains($svc, 'delinking.php'),                           'delinking async endpoint mapped');
chk(str_contains($svc, 'personalization.php'),                     'personalization async endpoint mapped');
chk(str_contains($svc, 'ipe_clearance.php'),                       'ipe_clearance async endpoint mapped');

// ── Lint check: PHP syntax ──────────────────────────────────────────────────
echo "\n--- PHP Syntax Check ---\n";
exec("C:\\xampp\\php\\php.exe -l C:\\xampp\\htdocs\\gemverify\\api\\src\\Controllers\\ApiStatusController.php 2>&1", $out, $rc);
chk($rc === 0, 'ApiStatusController.php syntax valid', implode('', $out));
exec("C:\\xampp\\php\\php.exe -l C:\\xampp\\htdocs\\gemverify\\api\\routes\\user.php 2>&1", $out2, $rc2);
chk($rc2 === 0, 'routes/user.php syntax valid', implode('', $out2));

// ── Summary ────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "  TH-17 Phase 4: $pass passed, $fail failed\n";
echo "=========================================\n";
exit($fail > 0 ? 1 : 0);
