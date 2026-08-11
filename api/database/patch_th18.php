<?php
/**
 * TH-18 + TH-19 Patch
 *
 * TH-18: Wire self-service → TechHub by adding "self-service" to GV_API_SERVICES.
 *        Fix uy() component to include NIN field for Delinking Email path
 *        (TechHub's delinking endpoint needs both NIN + email).
 *
 * TH-19: Retrieval NIN Details already routes to manual engine via
 *        isManualVariantOverride in ApiRequestController. Backend-side is done.
 *        Frontend: "Retrieval NIN Details" path in uy() is already correct —
 *        it sends nin:t to /api-services/submit which the backend receives and
 *        delegates to the manual engine. No frontend change needed beyond TH-18.
 */
declare(strict_types=1);

$file    = 'C:/xampp/htdocs/gemverify/user/index.html';
$content = file_get_contents($file);
$changed = false;

echo "=== TH-18 + TH-19: Self-Service Integration Patch ===\n\n";

// ── 1. Add "self-service" to GV_API_SERVICES ─────────────────────────────
$oldServices = 'GV_API_SERVICES = ["nin-verification","bvn-verification","personalization","bvn-retrieval","ipe-clearance-single"]';
$newServices = 'GV_API_SERVICES = ["nin-verification","bvn-verification","self-service","personalization","bvn-retrieval","ipe-clearance-single"]';

if (str_contains($content, $oldServices)) {
    $content = str_replace($oldServices, $newServices, $content);
    $changed = true;
    echo "[FIX 1] Added 'self-service' to GV_API_SERVICES\n";
} elseif (str_contains($content, $newServices)) {
    echo "[SKIP 1] 'self-service' already in GV_API_SERVICES\n";
} else {
    echo "[ERROR 1] GV_API_SERVICES not found in expected format\n";
    // Print what we have
    $pos = strpos($content, 'GV_API_SERVICES');
    if ($pos) echo "  Found: " . substr($content, $pos, 150) . "\n";
}

// ── 2. Fix uy() component — add NIN field for Delinking Email path ─────────
//
// Current uy() layout:
//   - Request Type select (A)
//   - A==="Delinking Email" => Email to Delink field (l)
//   - A==="Retrieval NIN Details" => NIN Number field (t)
//   - PIN (Q)
//   - Submit → {slug:"self-service", variantKey:A, pin:Q, email:l, nin:t}
//
// Fix: For Delinking Email, also show NIN field (mapped to same 't' state)
//      BUT 't' is already used for "Retrieval NIN Details" NIN — use a separate
//      state for Delinking NIN. We'll re-use 't' since they're mutually exclusive
//      (only one variant shown at a time), but add the NIN field under Delinking Email too.
//
// New layout for Delinking Email:
//   - NIN Number field (t) - needed by TechHub
//   - Email to Delink (l)
//
// This way:
//   Delinking Email: nin:t (filled), email:l (filled) → both required fields present
//   Retrieval NIN Details: nin:t (filled), email:l (empty) → backend routes to manual, no validation

// Old Delinking Email section (just email):
$oldDelinkSection = 'A==="Delinking Email"&&u("div",{children:[i(h,{req:!0,children:"Email to Delink"}),i(y,{value:l,onChange:o,placeholder:"Enter email"})]})';
// New: NIN + Email both under Delinking Email
$newDelinkSection = 'A==="Delinking Email"&&u("div",{className:"flex flex-col gap-3",children:[u("div",{children:[i(h,{req:!0,children:"NIN Number"}),i(y,{value:t,onChange:r,placeholder:"Enter 11-digit NIN (required for delinking)"})]}),u("div",{children:[i(h,{req:!0,children:"Email to Delink"}),i(y,{value:l,onChange:o,placeholder:"Enter email address to delink"})]})]})';

if (str_contains($content, $oldDelinkSection)) {
    $content = str_replace($oldDelinkSection, $newDelinkSection, $content);
    $changed = true;
    echo "[FIX 2] Added NIN field under Delinking Email path in uy() component\n";
} elseif (str_contains($content, $newDelinkSection)) {
    echo "[SKIP 2] NIN field already present in Delinking Email path\n";
} else {
    echo "[ERROR 2] Delinking Email section not found in expected format\n";
    $pos = strpos($content, '"Delinking Email"&&');
    if ($pos) echo "  Found at $pos: " . substr($content, $pos, 200) . "\n";
}

// ── 3. Also fix button disabled state to require NIN for Delinking Email ──
// Current disabled: !A || !isConfigured
// Improved: also require NIN (t) when A==="Delinking Email"
$oldDisabled = 'disabled:!A||!isConfigured,onClick:()=>e(`Self Service Delinking - ${A}`,price,{slug:"self-service",variantKey:A,pin:Q,email:l,nin:t})';
$newDisabled = 'disabled:!A||!isConfigured||(A==="Delinking Email"&&!t)||(A==="Delinking Email"&&!l),onClick:()=>e(`Self Service Delinking - ${A}`,price,{slug:"self-service",variantKey:A,pin:Q,email:l,nin:t})';

if (str_contains($content, $oldDisabled)) {
    $content = str_replace($oldDisabled, $newDisabled, $content);
    $changed = true;
    echo "[FIX 3] Enhanced button disabled state: require nin+email for Delinking Email\n";
} elseif (str_contains($content, $newDisabled)) {
    echo "[SKIP 3] Button disabled enhancement already applied\n";
} else {
    echo "[ERROR 3] Button disabled state not found in expected format\n";
    $pos = strpos($content, 'Self Service Delinking');
    if ($pos) echo "  Found at $pos: " . substr($content, max(0,$pos-80), 300) . "\n";
}

// ── 4. Add input_method:null to self-service meta (not needed — doSubmitPayment handles it) ──
// Already handled: doSubmitPayment sets inputMethod = meta.method || null — self-service sends no method ✓

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

echo "'self-service' in GV_API_SERVICES: ";
$pos = strpos($final, 'GV_API_SERVICES');
$apiLine = substr($final, $pos, 200);
echo (str_contains($apiLine, '"self-service"') ? 'YES ✓' : 'NO ✗') . "\n";

$uyStart = strpos($final, 'function uy({onProceed:e})');
$uyEnd   = strpos($final, 'function Qy({onProceed:e})');
$uy = substr($final, $uyStart, $uyEnd - $uyStart);

echo "NIN field under Delinking Email: " . (str_contains($uy, 'required for delinking') ? 'YES ✓' : 'NO ✗') . "\n";
echo "Email to Delink field present:    " . (str_contains($uy, 'Email to Delink') ? 'YES ✓' : 'NO ✗') . "\n";
echo "NIN for Retrieval still present:  " . (str_contains($uy, 'Retrieval NIN Details') ? 'YES ✓' : 'NO ✗') . "\n";
echo "nin:t in payload:                 " . (str_contains($uy, 'nin:t') ? 'YES ✓' : 'NO ✗') . "\n";
echo "email:l in payload:               " . (str_contains($uy, 'email:l') ? 'YES ✓' : 'NO ✗') . "\n";
echo "Enhanced disabled guard:          " . (str_contains($uy, '!t)||(A==="Delinking Email"&&!l)') ? 'YES ✓' : 'NO ✗') . "\n";
echo "self-service slug in onProceed:   " . (str_contains($uy, '"self-service"') ? 'YES ✓' : 'NO ✗') . "\n";
