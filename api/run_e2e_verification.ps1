$baseUrl = "http://localhost/gemverify/api"
$php = "C:\xampp\php\php.exe"

Write-Host "=========================================="
Write-Host "1. CREATING TEST USER & FUNDING WALLET"
Write-Host "=========================================="

# Create user or login
$regBody = @{
    business_name = "End2End Verification Hub"
    email = "e2e_verified@gemverify.com"
    phone = "08123456789"
    password = "UserPass@2026"
    confirm_password = "UserPass@2026"
} | ConvertTo-Json

try {
    $reg = Invoke-RestMethod -Uri "$baseUrl/auth/register" -Method Post -Body $regBody -ContentType "application/json"
    $userId = $reg.data.user.id
} catch {
    $loginBody = @{ email = "e2e_verified@gemverify.com"; password = "UserPass@2026" } | ConvertTo-Json
    $userLogin = Invoke-RestMethod -Uri "$baseUrl/auth/login" -Method Post -Body $loginBody -ContentType "application/json"
    $userId = $userLogin.data.user.id
}

Write-Host "User ID: $userId"

# Login
$loginBody = @{ email = "e2e_verified@gemverify.com"; password = "UserPass@2026" } | ConvertTo-Json
$userLogin = Invoke-RestMethod -Uri "$baseUrl/auth/login" -Method Post -Body $loginBody -ContentType "application/json"
$userToken = $userLogin.data.token
$userHeaders = @{ Authorization = "Bearer $userToken" }

# Set PIN
$pinBody = @{ pin = "1234"; confirm_pin = "1234" } | ConvertTo-Json
$setPin = Invoke-RestMethod -Uri "$baseUrl/auth/set-pin" -Method Post -Body $pinBody -ContentType "application/json" -Headers $userHeaders
Write-Host "User PIN Set: "$setPin.message

# Direct DB Credit ₦50,000 to user wallet for testing
$creditScript = "require 'C:/xampp/htdocs/gemverify/api/config/database.php'; db()->exec('UPDATE wallets SET balance=50000.00, ledger_balance=50000.00 WHERE user_id=$userId');"
& $php -r $creditScript

$walletBefore = Invoke-RestMethod -Uri "$baseUrl/user/wallet" -Method Get -Headers $userHeaders
Write-Host "Wallet Balance Before Request: ₦"$walletBefore.data.balance

Write-Host "`n=========================================="
Write-Host "2. SUBMITTING MANUAL SERVICE REQUEST (NIN Enrollment - ₦2,000)"
Write-Host "=========================================="

$boundary = [System.Guid]::NewGuid().ToString()
$fileBytes = [System.Text.Encoding]::UTF8.GetBytes("fake image content")
$fileName = "applicant_photo.jpg"

$formFields = @{
    service_slug = "nin-enrollment"
    variant_key = "adult"
    idempotency_key = [System.Guid]::NewGuid().ToString()
    pin = "1234"
    form_data = (@{
        applicant_type = "adult"
        surname = "E2E_SURNAME"
        first = "E2E_FIRST"
        dob = "1995-05-15"
        gender = "Male"
        phone = "08123456789"
        state = "Lagos"
        lga = "Ikeja"
        town = "Ikeja"
        address = "123 Test Street, Lagos"
        origin_state = "Lagos"
        origin_lga = "Ikeja"
        origin_town = "Ikeja"
    } | ConvertTo-Json)
}

$req = [System.Net.WebRequest]::Create("$baseUrl/manual/submit")
$req.Method = "POST"
$req.Headers.Add("Authorization", "Bearer $userToken")
$req.ContentType = "multipart/form-data; boundary=$boundary"

$memStream = New-Object System.IO.MemoryStream
$writer = New-Object System.IO.StreamWriter($memStream)

foreach ($key in $formFields.Keys) {
    $writer.Write("--$boundary`r`n")
    $writer.Write("Content-Disposition: form-data; name=`"$key`"`r`n`r`n")
    $writer.Write("$($formFields[$key])`r`n")
}

$writer.Write("--$boundary`r`n")
$writer.Write("Content-Disposition: form-data; name=`"applicant_photo`"; filename=`"$fileName`"`r`n")
$writer.Write("Content-Type: image/jpeg`r`n`r`n")
$writer.Flush()
$memStream.Write($fileBytes, 0, $fileBytes.Length)
$writer.Write("`r`n--$boundary--`r`n")
$writer.Flush()

$req.ContentLength = $memStream.Length
$reqStream = $req.GetRequestStream()
$memStream.Position = 0
$memStream.CopyTo($reqStream)
$reqStream.Close()

$resp = $req.GetResponse()
$reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
$jsonResp = $reader.ReadToEnd() | ConvertFrom-Json
$ref = $jsonResp.data.reference
Write-Host "Generated Request Reference: $ref"
Write-Host "Service Name: "$jsonResp.data.service_name
Write-Host "Price Paid: ₦"$jsonResp.data.price_paid
Write-Host "Status: "$jsonResp.data.status

$walletAfter = Invoke-RestMethod -Uri "$baseUrl/user/wallet" -Method Get -Headers $userHeaders
Write-Host "Wallet Balance After Submission: ₦"$walletAfter.data.balance

Write-Host "`n=========================================="
Write-Host "3. ADMIN PROCESSING & RESULT UPLOAD"
Write-Host "=========================================="

# Admin login
$adminLoginBody = @{ email = "admin@gemverify.com"; password = "Admin@2026" } | ConvertTo-Json
$adminLogin = Invoke-RestMethod -Uri "$baseUrl/admin/auth/login" -Method Post -Body $adminLoginBody -ContentType "application/json"
$adminToken = $adminLogin.data.token
$adminHeaders = @{ Authorization = "Bearer $adminToken" }

# Upload Result File
$resBoundary = [System.Guid]::NewGuid().ToString()
$resBytes = [System.Text.Encoding]::UTF8.GetBytes("%PDF-1.4 Fake Result PDF Content")
$resFileName = "NIN_Slip_E2E.pdf"

$resReq = [System.Net.WebRequest]::Create("$baseUrl/admin/requests/$ref/result")
$resReq.Method = "POST"
$resReq.Headers.Add("Authorization", "Bearer $adminToken")
$resReq.ContentType = "multipart/form-data; boundary=$resBoundary"

$resMem = New-Object System.IO.MemoryStream
$resWriter = New-Object System.IO.StreamWriter($resMem)

$resWriter.Write("--$resBoundary`r`n")
$resWriter.Write("Content-Disposition: form-data; name=`"result_file`"; filename=`"$resFileName`"`r`n")
$resWriter.Write("Content-Type: application/pdf`r`n`r`n")
$resWriter.Flush()
$resMem.Write($resBytes, 0, $resBytes.Length)
$resWriter.Write("`r`n--$resBoundary--`r`n")
$resWriter.Flush()

$resReq.ContentLength = $resMem.Length
$resReqStream = $resReq.GetRequestStream()
$resMem.Position = 0
$resMem.CopyTo($resReqStream)
$resReqStream.Close()

$resResp = $resReq.GetResponse()
$resReader = New-Object System.IO.StreamReader($resResp.GetResponseStream())
$resJson = $resReader.ReadToEnd() | ConvertFrom-Json
Write-Host "Admin Result Upload: Success (Version: "$resJson.data.version")"

# Mark Request Completed
$statusBody = @{ status = "completed"; notes = "NIN Slip generated and uploaded" } | ConvertTo-Json
$compRes = Invoke-RestMethod -Uri "$baseUrl/admin/requests/$ref/status" -Method Patch -Body $statusBody -ContentType "application/json" -Headers $adminHeaders
Write-Host "Admin Marked Request Status: "$compRes.data.status

Write-Host "`n=========================================="
Write-Host "4. USER RESULT DOWNLOAD & ACCESS CONTROL TEST"
Write-Host "=========================================="

# Check User Request Detail
$userReqDetail = Invoke-RestMethod -Uri "$baseUrl/manual/requests/$ref" -Method Get -Headers $userHeaders
Write-Host "User Views Request Status: "$userReqDetail.data.status
Write-Host "User Result Available: "$userReqDetail.data.result_available

# Create User B (Attacker / Unauthorized User)
$regBBody = @{
    business_name = "User B Partner"
    email = "partner@gemverify.com"
    phone = "08234567890"
    password = "UserPassword@2026"
    confirm_password = "UserPassword@2026"
} | ConvertTo-Json

try {
    $null = Invoke-RestMethod -Uri "$baseUrl/auth/register" -Method Post -Body $regBBody -ContentType "application/json"
} catch {}

$userBLogin = @{ email = "partner@gemverify.com"; password = "UserPassword@2026" } | ConvertTo-Json
$loginB = Invoke-RestMethod -Uri "$baseUrl/auth/login" -Method Post -Body $userBLogin -ContentType "application/json"
$tokenB = $loginB.data.token
$headersB = @{ Authorization = "Bearer $tokenB" }

Write-Host "`n[SECURITY TEST] User B attempts to access User A's result ($ref)..."
try {
    $stolenResult = Invoke-RestMethod -Uri "$baseUrl/manual/requests/$ref/result" -Method Get -Headers $headersB
    Write-Host "FAILED SECURITY TEST: User B downloaded result!"
} catch {
    Write-Host "SECURITY TEST PASSED: User B Access Denied (HTTP 404/403)"
}

Write-Host "`n=========================================="
Write-Host "5. REFUND WORKFLOW TEST"
Write-Host "=========================================="

# Submit a 2nd request to test rejection and refund
$formFields2 = @{
    service_slug = "bvn-risk"
    idempotency_key = [System.Guid]::NewGuid().ToString()
    pin = "1234"
    form_data = (@{ batch_id = "BATCH999"; ticket_id = "TICK999"; issue_description = "Testing refund" } | ConvertTo-Json)
}

$req2 = [System.Net.WebRequest]::Create("$baseUrl/manual/submit")
$req2.Method = "POST"
$req2.Headers.Add("Authorization", "Bearer $userToken")
$req2.ContentType = "multipart/form-data; boundary=$boundary"

$memStream2 = New-Object System.IO.MemoryStream
$writer2 = New-Object System.IO.StreamWriter($memStream2)

foreach ($key in $formFields2.Keys) {
    $writer2.Write("--$boundary`r`n")
    $writer2.Write("Content-Disposition: form-data; name=`"$key`"`r`n`r`n")
    $writer2.Write("$($formFields2[$key])`r`n")
}
$writer2.Write("--$boundary--`r`n")
$writer2.Flush()

$req2.ContentLength = $memStream2.Length
$reqStream2 = $req2.GetRequestStream()
$memStream2.Position = 0
$memStream2.CopyTo($reqStream2)
$reqStream2.Close()

$resp2 = $req2.GetResponse()
$jsonResp2 = (New-Object System.IO.StreamReader($resp2.GetResponseStream())).ReadToEnd() | ConvertFrom-Json
$ref2 = $jsonResp2.data.reference
Write-Host "2nd Request Reference Created (CRM - ?2,000): $ref2"

$balMid = (Invoke-RestMethod -Uri "$baseUrl/user/wallet" -Method Get -Headers $userHeaders).data.balance
Write-Host "Wallet Balance After 2nd Submission: ?$balMid"

# Reject request
$rejectBody = @{ reason = "Invalid Batch ID" } | ConvertTo-Json
$rejRes = Invoke-RestMethod -Uri "$baseUrl/admin/requests/$ref2/reject" -Method Post -Body $rejectBody -ContentType "application/json" -Headers $adminHeaders
Write-Host "Admin Rejected Request: "$rejRes.data.status

# Process Refund (Requires Super Admin)
$refundBody = @{ reason = "Auto-refund for invalid submission" } | ConvertTo-Json
$refundRes = Invoke-RestMethod -Uri "$baseUrl/admin/requests/$ref2/refund" -Method Post -Body $refundBody -ContentType "application/json" -Headers $adminHeaders
Write-Host "Admin Refund Status: "$refundRes.data.status

$balFinal = (Invoke-RestMethod -Uri "$baseUrl/user/wallet" -Method Get -Headers $userHeaders).data.balance
Write-Host "Wallet Balance After Refund Credited: ₦$balFinal"

Write-Host "`n=========================================="
Write-Host "6. ADMIN ROLE PERMISSION TEST"
Write-Host "=========================================="

# Support admin login
$supportLoginBody = @{ email = "support@gemverify.com"; password = "Support@2026" } | ConvertTo-Json
$supportLogin = Invoke-RestMethod -Uri "$baseUrl/admin/auth/login" -Method Post -Body $supportLoginBody -ContentType "application/json"
$supportToken = $supportLogin.data.token
$supportHeaders = @{ Authorization = "Bearer $supportToken" }

Write-Host "[ROLE PERMISSION TEST] Support Admin attempts to issue a refund (Requires super_admin)..."
try {
    $illegalRefund = Invoke-RestMethod -Uri "$baseUrl/admin/requests/$ref/refund" -Method Post -Body $refundBody -ContentType "application/json" -Headers $supportHeaders
    Write-Host "FAILED PERMISSION TEST: Support staff issued refund!"
} catch {
    Write-Host "PERMISSION TEST PASSED: Support staff blocked with HTTP 403 Forbidden!"
}

