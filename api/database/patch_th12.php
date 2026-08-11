<?php
/**
 * TH-12 Patch — BVN Verification component (Qy) label fix
 * Fixes "Slip Type — Compact Chips" → "Slip Type"
 * No other changes needed: bvn:l payload + slug:"bvn-verification" are correct,
 * and bvn-verification is already in GV_API_SERVICES routing list.
 */
declare(strict_types=1);

$file = 'C:/xampp/htdocs/gemverify/user/index.html';
$content = file_get_contents($file);
echo "=== TH-12: BVN Component Label Fix ===\n";
echo "Size: " . number_format(strlen($content)) . " bytes\n\n";

// Guard
if (!str_contains($content, 'Slip Type \xe2\x80\x94 Compact Chips')) {
    // Try ASCII em-dash variant
    if (!str_contains($content, 'Slip Type — Compact Chips')) {
        echo "[SKIP] Compact Chips label already removed from BVN component.\n";
        exit(0);
    }
}

// Find the BVN Qy component boundaries
$start = strpos($content, 'function Qy({onProceed:e})');
$end   = strpos($content, 'function dy({onProceed:e})');
if ($start === false || $end === false) {
    echo "[ERROR] Cannot find Qy() boundaries\n";
    exit(1);
}

$qy = substr($content, $start, $end - $start);

// Confirm the label is in the component
$labelPos = strpos($qy, 'Slip Type');
if ($labelPos === false) {
    echo "[ERROR] 'Slip Type' not found in Qy component\n";
    exit(1);
}
echo "Label context: " . substr($qy, $labelPos, 60) . "\n";

// The fix: replace "Slip Type — Compact Chips" with "Slip Type"
// The em-dash is UTF-8 E2 80 94
$oldLabel = "Slip Type \xe2\x80\x94 Compact Chips";
$newLabel = "Slip Type";

// Also: the fee display shows A.price but should show currentPrice (live price from db)
// Current: k(Q.price) inside chip loop — Q is individual variant, correct
// Current: label display: k(A.price) → should be k(currentPrice) 
// Let's check what the submit button uses
echo "\nSubmit button onClick payload:\n";
$onClick = strpos($qy, 'onClick:()=>e(');
echo substr($qy, $onClick, 100) . "\n";

// Fix the label
if (str_contains($qy, $oldLabel)) {
    $qy_fixed = str_replace($oldLabel, $newLabel, $qy);
    echo "\n[FIX] Label replaced: '$oldLabel' → '$newLabel'\n";
} else {
    // Try the HTML entity version
    $oldLabel2 = "Slip Type &mdash; Compact Chips";
    $qy_fixed = str_contains($qy, $oldLabel2) ? str_replace($oldLabel2, $newLabel, $qy) : $qy;
    echo "[WARN] Standard em-dash not found, tried other variants\n";
}

// Rebuild file
$newContent = substr($content, 0, $start) . $qy_fixed . substr($content, $end);
file_put_contents($file, $newContent);
echo "[WRITE] File saved. Size: " . number_format(strlen($newContent)) . " bytes\n";

// Verify
$final = file_get_contents($file);
$qy2 = substr($final, strpos($final, 'function Qy({onProceed:e})'), 3000);
echo "\n=== BVN Component Verification ===\n";
echo "Compact Chips gone:     " . (!str_contains($qy2, 'Compact Chips') ? 'YES ✓' : 'STILL PRESENT ✗') . "\n";
echo "Slip Type present:      " . (str_contains($qy2, '"Slip Type"') ? 'YES ✓' : 'MISSING ✗') . "\n";
echo "bvn-verification slug:  " . (str_contains($qy2, '"bvn-verification"') ? 'YES ✓' : 'MISSING ✗') . "\n";
echo "bvn:l in payload:       " . (str_contains($qy2, 'bvn:l') ? 'YES ✓' : 'MISSING ✗') . "\n";
