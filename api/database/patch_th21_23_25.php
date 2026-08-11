<?php
/**
 * Phase 6+7+8 Patch
 * 
 * TH-21: Personalization — fix trackingId → tracking_id in payload
 * TH-23: BVN Retrieval — fix first/last/phone → first_name/last_name/phone_number
 * TH-25: IPE Clearance — already correct (tracking_id used), just verify
 */
declare(strict_types=1);

$file    = 'C:/xampp/htdocs/gemverify/user/index.html';
$content = file_get_contents($file);
$changed = false;

echo "=== Phase 6+7+8: Personalization, BVN Retrieval, IPE Clearance ===\n\n";

// ── TH-21: Personalization: trackingId → tracking_id ─────────────────────
// Old: {slug:"personalization", pin:l, trackingId:A}
// New: {slug:"personalization", pin:l, tracking_id:A}
$oldPersonal = '{slug:"personalization",pin:l,trackingId:A}';
$newPersonal = '{slug:"personalization",pin:l,tracking_id:A}';

if (str_contains($content, $oldPersonal)) {
    $content = str_replace($oldPersonal, $newPersonal, $content);
    $changed = true;
    echo "[FIX 1] Personalization: trackingId → tracking_id\n";
} elseif (str_contains($content, $newPersonal)) {
    echo "[SKIP 1] Personalization: tracking_id already correct\n";
} else {
    // Try without spaces
    $p1 = strpos($content, '"personalization"');
    $p2 = $p1 ? strpos($content, 'onProceed', $p1 - 500) : false;
    echo "[ERROR 1] trackingId not found in expected format\n";
    if ($p1) echo "  Context: " . substr($content, $p1 - 100, 300) . "\n";
}

// ── TH-23: BVN Retrieval: fix field name mapping ──────────────────────────
// The cy() component uses state object A with fields: first, last, phone
// Payload: {slug:"bvn-retrieval", pin:A.pin, ...A}
// This spreads: first, last, phone, pin  → but backend needs first_name, last_name, phone_number
//
// Strategy: Replace the payload in the onProceed call to explicitly map fields
// Old: onClick:()=>e("BVN Retrieval",price,{slug:"bvn-retrieval",pin:A.pin,...A})
// New: onClick:()=>e("BVN Retrieval",price,{slug:"bvn-retrieval",pin:A.pin,first_name:A.first,last_name:A.last,phone_number:A.phone})
$oldBvnRetr = 'onClick:()=>e("BVN Retrieval",price,{slug:"bvn-retrieval",pin:A.pin,...A})';
$newBvnRetr = 'onClick:()=>e("BVN Retrieval",price,{slug:"bvn-retrieval",pin:A.pin,first_name:A.first,last_name:A.last,phone_number:A.phone})';

if (str_contains($content, $oldBvnRetr)) {
    $content = str_replace($oldBvnRetr, $newBvnRetr, $content);
    $changed = true;
    echo "[FIX 2] BVN Retrieval: first/last/phone → first_name/last_name/phone_number\n";
} elseif (str_contains($content, $newBvnRetr)) {
    echo "[SKIP 2] BVN Retrieval: field names already correct\n";
} else {
    $p = strpos($content, '"bvn-retrieval"');
    echo "[ERROR 2] BVN Retrieval payload not in expected format\n";
    if ($p) echo "  Context: " . substr($content, $p - 50, 250) . "\n";
}

// ── TH-25: IPE Clearance — verify already correct ────────────────────────
// Context found: {slug:"ipe-clearance-single",tracking_id:l,pin:t}
// No fix needed.
echo "[SKIP 3] IPE Clearance: tracking_id already correct in payload\n";

// ── Write ─────────────────────────────────────────────────────────────────
if ($changed) {
    file_put_contents($file, $content);
    echo "\n[WRITE] File saved. Size: " . number_format(strlen($content)) . " bytes\n";
} else {
    echo "\n[INFO] No changes written.\n";
}

// ── Verify ─────────────────────────────────────────────────────────────────
echo "\n=== Verification ===\n";
$final = file_get_contents($file);

// Personalization
echo "Personalization tracking_id:    " . (str_contains($final, '"personalization",pin:l,tracking_id:A') ? 'YES ✓' : 'NO ✗') . "\n";
$badPersonal = str_contains($final, '"personalization",pin:l,trackingId:A');
echo "Personalization trackingId gone: " . (!$badPersonal ? 'YES ✓' : 'STILL PRESENT ✗') . "\n";

// BVN Retrieval
echo "BVN Retrieval first_name:       " . (str_contains($final, 'first_name:A.first') ? 'YES ✓' : 'NO ✗') . "\n";
echo "BVN Retrieval last_name:        " . (str_contains($final, 'last_name:A.last') ? 'YES ✓' : 'NO ✗') . "\n";
echo "BVN Retrieval phone_number:     " . (str_contains($final, 'phone_number:A.phone') ? 'YES ✓' : 'NO ✗') . "\n";
echo "BVN Retrieval no spread (...A): " . (!str_contains($final, 'bvn-retrieval",pin:A.pin,...A') ? 'YES ✓' : 'STILL HAS SPREAD ✗') . "\n";

// IPE Clearance
echo "IPE tracking_id correct:        " . (str_contains($final, '"ipe-clearance-single",tracking_id:l,pin:t') ? 'YES ✓' : 'NO ✗') . "\n";
