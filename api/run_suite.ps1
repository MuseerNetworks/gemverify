$baseUrl = "http://localhost/gemverify/api"

Write-Host "--- TEST 1: REGISTER USER ---"
$regBody = @{
    business_name = "GemVerify Partner Hub"
    email = "partner@gemverify.com"
    phone = "08011223344"
    password = "UserPassword@2026"
    confirm_password = "UserPassword@2026"
} | ConvertTo-Json

$regRes = Invoke-RestMethod -Uri "$baseUrl/auth/register" -Method Post -Body $regBody -ContentType "application/json"
$regRes | ConvertTo-Json -Depth 5

Write-Host "`n--- TEST 2: LOGIN USER ---"
$loginBody = @{
    email = "partner@gemverify.com"
    password = "UserPassword@2026"
} | ConvertTo-Json

$loginRes = Invoke-RestMethod -Uri "$baseUrl/auth/login" -Method Post -Body $loginBody -ContentType "application/json"
$loginRes | ConvertTo-Json -Depth 5
$userToken = $loginRes.data.token

Write-Host "`n--- TEST 3: GET USER PROFILE WITH TOKEN ---"
$userHeaders = @{ "Authorization" = "Bearer $userToken" }
$profileRes = Invoke-RestMethod -Uri "$baseUrl/user/profile" -Method Get -Headers $userHeaders
$profileRes | ConvertTo-Json -Depth 5

Write-Host "`n--- TEST 4: LOGIN ADMIN ---"
$adminLoginBody = @{
    email = "admin@gemverify.com"
    password = "Admin@2026"
} | ConvertTo-Json

$adminLoginRes = Invoke-RestMethod -Uri "$baseUrl/admin/auth/login" -Method Post -Body $adminLoginBody -ContentType "application/json"
$adminLoginRes | ConvertTo-Json -Depth 5
$adminToken = $adminLoginRes.data.token

Write-Host "`n--- TEST 5: GET ADMIN STATS WITH ADMIN TOKEN ---"
$adminHeaders = @{ "Authorization" = "Bearer $adminToken" }
$statsRes = Invoke-RestMethod -Uri "$baseUrl/admin/stats" -Method Get -Headers $adminHeaders
$statsRes | ConvertTo-Json -Depth 5

Write-Host "`n--- TEST 6: GET ALL MANUAL REQUESTS (ADMIN) ---"
$reqsRes = Invoke-RestMethod -Uri "$baseUrl/admin/requests" -Method Get -Headers $adminHeaders
$reqsRes | ConvertTo-Json -Depth 5
