<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';

echo "=== STARTING VERIFICATION FLOW TESTS ===\n\n";

$db = db();

// Helper to authenticate admin
function getAdminToken() {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost/gemverify/api/admin/auth/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'email' => 'admin@gemverify.com',
        'password' => 'Admin@2026'
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    $json = json_decode($res, true);
    curl_close($ch);
    return $json['data']['token'] ?? '';
}

// Helper to authenticate user
function getUserToken() {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost/gemverify/api/auth/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'email' => 'test_verify_user@gemverify.com',
        'password' => 'User@2026'
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    $json = json_decode($res, true);
    curl_close($ch);
    return $json['data']['token'] ?? '';
}

$adminToken = getAdminToken();
if (!$adminToken) {
    die("ERROR: Failed to authenticate admin!\n");
}
echo "1. Authenticated as Admin successfully.\n";

$userToken = getUserToken();
if (!$userToken) {
    die("ERROR: Failed to authenticate user!\n");
}
echo "2. Authenticated as User successfully.\n";

// --- TEST A: PRICE UPDATE FLOW ---
echo "\n--- TEST A: PRICE UPDATE FLOW ---\n";

// 1. Get current price for NIN Enrollment
$stmt = $db->prepare("SELECT price, id FROM service_pricing WHERE service_id = 1 AND variant_key IS NULL LIMIT 1");
$stmt->execute();
$pricingRow = $stmt->fetch(PDO::FETCH_ASSOC);
$originalPrice = (float) $pricingRow['price'];
$pricingId = (int) $pricingRow['id'];
echo "Original Price of NIN Enrollment: ₦$originalPrice (Pricing ID: $pricingId)\n";

// 2. Modify price to 2200.00 via Admin API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost/gemverify/api/admin/services/1/pricing/$pricingId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['price' => 2200.00]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $adminToken",
    "Content-Type: application/json"
]);
$res = curl_exec($ch);
$json = json_decode($res, true);
curl_close($ch);

if (!($json['success'] ?? false)) {
    die("ERROR: Admin price update failed: " . json_encode($json) . "\n");
}
echo "Price updated to ₦2200.00 via Admin API PATCH.\n";

// 3. Confirm directly in Database
$stmt = $db->prepare("SELECT price FROM service_pricing WHERE id = ?");
$stmt->execute([$pricingId]);
$dbPrice = (float) $stmt->fetchColumn();
if ($dbPrice === 2200.00) {
    echo "VERIFIED: Database pricing table contains new price (₦2200.00).\n";
} else {
    die("ERROR: Database price does not match updated price! DB contains ₦$dbPrice\n");
}

// 4. Confirm in User API GET /api/services
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost/gemverify/api/services");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
$json = json_decode($res, true);
curl_close($ch);

$ninService = null;
foreach ($json['data']['NIN'] ?? [] as $s) {
    if ($s['slug'] === 'nin-enrollment') {
        $ninService = $s;
        break;
    }
}
$flatPrice = 0;
if ($ninService) {
    foreach ($ninService['variants'] as $v) {
        if ($v['key'] === null || $v['key'] === '') {
            $flatPrice = $v['price'];
            break;
        }
    }
}

if ((float)$flatPrice == 2200.0) {
    echo "VERIFIED: User API GET /api/services returns new price (₦2200.00).\n";
} else {
    die("ERROR: User API returned incorrect price for flat variant! Returned ₦$flatPrice\n");
}

// 5. Restore original price
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost/gemverify/api/admin/services/1/pricing/$pricingId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['price' => $originalPrice]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $adminToken",
    "Content-Type: application/json"
]);
curl_exec($ch);
curl_close($ch);
echo "Restored original price of ₦$originalPrice.\n";


// --- TEST B: AUTHORITATIVE BILLING SECURITY TEST ---
echo "\n--- TEST B: AUTHORITATIVE BILLING SECURITY TEST ---\n";

// Get user's current wallet balance
$userId = 3; // test_verify_user@gemverify.com
$stmt = $db->prepare("SELECT balance FROM wallets WHERE user_id = ?");
$stmt->execute([$userId]);
$balanceBefore = (float) $stmt->fetchColumn();
echo "User balance before transaction: ₦$balanceBefore\n";

if ($balanceBefore < $originalPrice) {
    // Fund wallet enough to cover the service price comfortably
    $topUp = $originalPrice * 2;
    $db->prepare("UPDATE wallets SET balance = balance + $topUp WHERE user_id = ?")->execute([$userId]);
    $balanceBefore += $topUp;
    echo "Funded wallet. New balance: ₦$balanceBefore\n";
}

// Submit a paid NIN Enrollment request with an intentionally incorrect client-supplied price of 99999.00
$idempotencyKey = bin2hex(random_bytes(16));
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost/gemverify/api/manual/submit");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'service_slug' => 'nin-enrollment',
    'variant_key' => null,
    'idempotency_key' => $idempotencyKey,
    'pin' => '1234', // test pin
    'form_data' => [
        'surname' => 'Doe',
        'first' => 'John',
        'dob' => '1995-05-15',
        'gender' => 'Male',
        'phone' => '08012345678',
        'state' => 'Lagos',
        'lga' => 'Ikeja',
        'town' => 'Ikeja',
        'address' => '123 Test Street',
        'originState' => 'Lagos',
        'originLga' => 'Ikeja',
        'originTown' => 'Ikeja'
    ],
    'client_price_override' => 99999.00 // Intentionally fake client-supplied value to prove it is ignored
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $userToken",
    "Content-Type: application/json"
]);
$res = curl_exec($ch);
$json = json_decode($res, true);
curl_close($ch);

if (!($json['success'] ?? false)) {
    die("ERROR: Transaction submission failed: " . json_encode($json) . "\n");
}
$reqRef = $json['data']['reference'];
echo "Transaction submitted successfully. Request reference: $reqRef\n";

// Get user's wallet balance after transaction
$stmt = $db->prepare("SELECT balance FROM wallets WHERE user_id = ?");
$stmt->execute([$userId]);
$balanceAfter = (float) $stmt->fetchColumn();
$actualDeducted = $balanceBefore - $balanceAfter;
echo "User balance after transaction: ₦$balanceAfter\n";
echo "Actual balance deducted: ₦$actualDeducted\n";

if (abs($actualDeducted - $originalPrice) < 0.01) {
    echo "VERIFIED: Deducted amount matches the database price (₦$originalPrice), client value was completely ignored!\n";
} else {
    die("ERROR: Deducted amount did not match database price! Deducted ₦$actualDeducted instead of ₦$originalPrice\n");
}

// Confirm transaction details in database ledger
$stmt = $db->prepare("SELECT price_paid, transaction_id FROM manual_requests WHERE reference = ?");
$stmt->execute([$reqRef]);
$requestRow = $stmt->fetch(PDO::FETCH_ASSOC);
$pricePaid = (float) $requestRow['price_paid'];
$txnId = (int) $requestRow['transaction_id'];

$stmt = $db->prepare("SELECT amount FROM transactions WHERE id = ?");
$stmt->execute([$txnId]);
$ledgerAmount = (float) $stmt->fetchColumn();

echo "Manual request price_paid column: ₦$pricePaid\n";
echo "Transaction ledger amount: ₦$ledgerAmount\n";

if (abs($pricePaid - $originalPrice) < 0.01 && abs($ledgerAmount - $originalPrice) < 0.01) {
    echo "VERIFIED: Database transaction ledger and manual request record show exact DB amount (₦$originalPrice).\n";
} else {
    die("ERROR: Ledger entries did not match database price! Ledger: ₦$ledgerAmount, Paid: ₦$pricePaid\n");
}


// --- TEST C: SECURITY ACCESS CONTROL ---
echo "\n--- TEST C: SECURITY ACCESS CONTROL ---\n";

// Attempt to change price using a normal user token (unauthorized role)
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost/gemverify/api/admin/services/1/pricing/$pricingId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['price' => 999.00]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $userToken", // Using user token
    "Content-Type: application/json"
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Normal user PATCH HTTP response code: $httpCode\n";
if ($httpCode === 403 || $httpCode === 401) {
    echo "VERIFIED: Normal user modification is rejected with HTTP $httpCode (access denied).\n";
} else {
    die("ERROR: Normal user modification was NOT rejected! Returned HTTP $httpCode instead of 401/403\n");
}

echo "\n=== ALL TESTS PASSED SUCCESSFULLY! ===\n";
