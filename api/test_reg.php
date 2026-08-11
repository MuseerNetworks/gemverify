<?php
$ch = curl_init('http://localhost/gemverify/api/auth/register');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'business_name' => 'Test Investigation User',
    'email' => 'investigate_user_2026@gemverify.com',
    'phone' => '08012345678',
    'password' => 'TestPass@2026',
    'confirm_password' => 'TestPass@2026'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP STATUS: $code\n";
echo "RESPONSE BODY: $resp\n";
