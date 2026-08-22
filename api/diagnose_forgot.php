<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain');

echo "=== GemVerify SMTP Socket Diagnostic ===\n\n";

function sendSMTPSocket($host, $port, $to, $subject, $htmlContent, $fromEmail, $fromName) {
    echo "Connecting to $host:$port...\n";
    $socket = @fsockopen($host, $port, $errno, $errstr, 10);
    if (!$socket) {
        echo "✗ Connection failed: $errstr ($errno)\n";
        return false;
    }
    echo "✓ Connected!\n";
    
    $response = fgets($socket, 512);
    echo "S: $response";
    
    fwrite($socket, "EHLO localhost\r\n");
    $response = fgets($socket, 512);
    echo "S: $response";
    while (substr($response, 3, 1) === '-') {
        $response = fgets($socket, 512);
        echo "S: $response";
    }
    
    fwrite($socket, "MAIL FROM:<$fromEmail>\r\n");
    $response = fgets($socket, 512);
    echo "S: $response";
    
    fwrite($socket, "RCPT TO:<$to>\r\n");
    $response = fgets($socket, 512);
    echo "S: $response";
    
    fwrite($socket, "DATA\r\n");
    $response = fgets($socket, 512);
    echo "S: $response";
    
    $headers = [
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "From: =?utf-8?B?" . base64_encode($fromName) . "?= <$fromEmail>",
        "To: <$to>",
        "Subject: =?utf-8?B?" . base64_encode($subject) . "?=",
        "Date: " . date('r'),
        "X-Mailer: GemVerify SMTP Socket Helper"
    ];
    
    $data = implode("\r\n", $headers) . "\r\n\r\n" . $htmlContent . "\r\n.\r\n";
    fwrite($socket, $data);
    $response = fgets($socket, 512);
    echo "S: $response";
    
    fwrite($socket, "QUIT\r\n");
    $response = fgets($socket, 512);
    echo "S: $response";
    
    fclose($socket);
    return strpos($response, '221') !== false || true;
}

try {
    $to = 'yasiridris6@gmail.com';
    $from = 'support@gemverify.com.ng';
    $name = 'GemVerify Support';
    
    echo "Testing Port 25 (localhost)...\n";
    sendSMTPSocket('127.0.0.1', 25, $to, 'Test Port 25', '<h3>Test Port 25</h3>', $from, $name);
    
    echo "\n-----------------------------------------\n";
    echo "Testing Port 587 (localhost)...\n";
    sendSMTPSocket('127.0.0.1', 587, $to, 'Test Port 587', '<h3>Test Port 587</h3>', $from, $name);
    
} catch (Throwable $t) {
    echo "\nException: " . $t->getMessage() . "\n";
}
