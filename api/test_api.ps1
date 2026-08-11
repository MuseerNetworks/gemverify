$baseUrl = "http://localhost/gemverify/api"

# 1. Health check / Get services
Write-Host "1. Testing GET /services..."
try {
    $res = Invoke-RestMethod -Uri "$baseUrl/services" -Method Get
    Write-Host "Status: Success, Found Categories: "$res.data.Count
} catch {
    Write-Host "Error: $_"
}

# 2. Test Auth Login (Default Admin)
Write-Host "`n2. Testing POST /admin/auth/login..."
$body = @{ email = "admin@gemverify.com"; password = "Admin@2026" } | ConvertTo-Json
try {
    $res = Invoke-RestMethod -Uri "$baseUrl/admin/auth/login" -Method Post -Body $body -ContentType "application/json"
    Write-Host "Status: Success, Admin Token Received: "$res.data.token.Substring(0, 20)...
} catch {
    Write-Host "Error: $_"
}
