$baseUrl = "http://localhost/gemverify/api"

Write-Host "1. GET /services"
$s = Invoke-RestMethod -Uri "$baseUrl/services" -Method Get
$s | ConvertTo-Json -Depth 5

Write-Host "`n2. POST /admin/auth/login"
$body = @{ email = "admin@gemverify.com"; password = "Admin@2026" } | ConvertTo-Json
$l = Invoke-RestMethod -Uri "$baseUrl/admin/auth/login" -Method Post -Body $body -ContentType "application/json"
$l | ConvertTo-Json -Depth 5
