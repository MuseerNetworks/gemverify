$baseUrl = "http://localhost/gemverify/api"
$email = "e2e_fresh_user_" + [System.Guid]::NewGuid().ToString().Substring(0,8) + "@gemverify.com"
$regBody = @{
    business_name = "Fresh Production Test User"
    email = $email
    phone = "08129998877"
    password = "UserPassword@2026"
    confirm_password = "UserPassword@2026"
} | ConvertTo-Json

Write-Host "1. Registering New User: $email"
$regRes = Invoke-RestMethod -Uri "$baseUrl/auth/register" -Method Post -Body $regBody -ContentType "application/json"
Write-Host "Registration Success: "$regRes.success
Write-Host "New User ID: "$regRes.data.user.id

Write-Host "`n2. Logging in New User"
$loginBody = @{ email = $email; password = "UserPassword@2026" } | ConvertTo-Json
$loginRes = Invoke-RestMethod -Uri "$baseUrl/auth/login" -Method Post -Body $loginBody -ContentType "application/json"
$userToken = $loginRes.data.token
$userHeaders = @{ Authorization = "Bearer $userToken" }
Write-Host "Login Success: "$loginRes.success

Write-Host "`n3. Checking New User Wallet Balance"
$walletRes = Invoke-RestMethod -Uri "$baseUrl/user/wallet" -Method Get -Headers $userHeaders
Write-Host "New User Balance: ?"$walletRes.data.balance

Write-Host "`n4. Checking New User Requests List"
$reqsRes = Invoke-RestMethod -Uri "$baseUrl/manual/requests" -Method Get -Headers $userHeaders
Write-Host "New User Requests Count: "$reqsRes.data.data.Count

Write-Host "`n5. Admin Login & Stats Check"
$adminLoginBody = @{ email = "admin@gemverify.com"; password = "Admin@2026" } | ConvertTo-Json
$adminLoginRes = Invoke-RestMethod -Uri "$baseUrl/admin/auth/login" -Method Post -Body $adminLoginBody -ContentType "application/json"
$adminToken = $adminLoginRes.data.token
$adminHeaders = @{ Authorization = "Bearer $adminToken" }
Write-Host "Admin Login Success: "$adminLoginRes.success

$adminStatsRes = Invoke-RestMethod -Uri "$baseUrl/admin/stats" -Method Get -Headers $adminHeaders
Write-Host "Admin Live Stats Total Users: "$adminStatsRes.data.total_users
Write-Host "Admin Live Stats Total Requests: "$adminStatsRes.data.total_requests
Write-Host "Admin Live Stats Total Revenue: ?"$adminStatsRes.data.total_revenue

$adminReqsRes = Invoke-RestMethod -Uri "$baseUrl/admin/requests" -Method Get -Headers $adminHeaders
Write-Host "Admin Live Requests Count: "$adminReqsRes.data.data.Count
