$baseUrl = "http://localhost/gemverify/api"
$php = "C:\xampp\php\php.exe"

# Get token for user id 4
$loginBody = @{ email = "e2e_verified@gemverify.com"; password = "UserPass@2026" } | ConvertTo-Json
$userLogin = Invoke-RestMethod -Uri "$baseUrl/auth/login" -Method Post -Body $loginBody -ContentType "application/json"
$userToken = $userLogin.data.token

# Set PIN
$pinBody = @{ pin = "1234"; confirm_pin = "1234" } | ConvertTo-Json
$setPin = Invoke-RestMethod -Uri "$baseUrl/auth/set-pin" -Method Post -Body $pinBody -ContentType "application/json" -Headers @{ Authorization = "Bearer $userToken" }

# Fund wallet
& $php -r "require 'C:/xampp/htdocs/gemverify/api/config/database.php'; db()->exec('UPDATE wallets SET balance=50000.00, ledger_balance=50000.00 WHERE user_id=4');"

# Test simple POST to /manual/submit with json
$postData = @{
    service_slug = "nin-enrollment"
    idempotency_key = [System.Guid]::NewGuid().ToString()
    pin = "1234"
    form_data = @{
        surname = "TEST_SURNAME"
        first = "TEST_FIRST"
        dob = "1990-01-01"
        phone = "08012345678"
    }
} | ConvertTo-Json

try {
    $res = Invoke-RestMethod -Uri "$baseUrl/manual/submit" -Method Post -Body $postData -ContentType "application/json" -Headers @{ Authorization = "Bearer $userToken" }
    $res | ConvertTo-Json -Depth 5
} catch {
    $stream = $_.Exception.Response.GetResponseStream()
    $reader = New-Object System.IO.StreamReader($stream)
    Write-Host "Error Output:" $reader.ReadToEnd()
}
