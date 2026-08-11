$baseUrl = "http://localhost/gemverify/api"

# Get token for user id 5
$loginBody = @{ email = "e2e_verified@gemverify.com"; password = "UserPass@2026" } | ConvertTo-Json
$userLogin = Invoke-RestMethod -Uri "$baseUrl/auth/login" -Method Post -Body $loginBody -ContentType "application/json"
$userToken = $userLogin.data.token

$boundary = [System.Guid]::NewGuid().ToString()
$fileBytes = [System.Text.Encoding]::UTF8.GetBytes("fake image content")
$fileName = "applicant_photo.jpg"

$formFields = @{
    service_slug = "nin-enrollment"
    idempotency_key = [System.Guid]::NewGuid().ToString()
    pin = "1234"
    form_data = (@{
        applicant_type = "adult"
        surname = "E2E_SURNAME"
        first = "E2E_FIRST"
        dob = "1995-05-15"
        phone = "08123456789"
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

try {
    $resp = $req.GetResponse()
    $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
    Write-Host "Success Response:" $reader.ReadToEnd()
} catch [System.Net.WebException] {
    $resp = $_.Exception.Response
    if ($resp) {
        $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
        Write-Host "Multipart Submit Error:" $reader.ReadToEnd()
    } else {
        Write-Host "WebException without response:" $_.Exception.Message
    }
} catch {
    Write-Host "General Exception:" $_.Exception.Message
}
