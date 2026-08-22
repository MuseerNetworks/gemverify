<?php
header('Content-Type: application/json');

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

// Safe autoloader
spl_autoload_register(function (string $class): void {
    $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
    $file  = __DIR__ . '/src/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

$response = [
    'stage1_database' => 'pending',
    'stage2_mailer' => 'pending',
    'stage3_mail_function' => 'pending',
    'errors' => []
];

// Stage 1: Database verification
try {
    $db = db();
    $stmt = $db->query("SHOW TABLES LIKE 'password_resets'");
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        $response['stage1_database'] = 'success (table password_resets exists)';
    } else {
        $response['stage1_database'] = 'failed (table password_resets does NOT exist)';
        $response['errors'][] = 'Database table "password_resets" is missing on live. You need to run the patch script or SQL query to create it.';
    }
} catch (Exception $e) {
    $response['stage1_database'] = 'error';
    $response['errors'][] = 'Database connection error: ' . $e->getMessage();
}

// Stage 2: Mailer loading and execution verification
try {
    if (class_exists('Helpers\Mailer')) {
        $response['stage2_mailer'] = 'success (Mailer helper loaded successfully)';
    } else {
        $response['stage2_mailer'] = 'failed (Mailer class not found by autoloader)';
        $response['errors'][] = 'Autoloader could not find Helpers\Mailer class. Check case-sensitivity of directory names on Linux.';
    }
} catch (Exception $e) {
    $response['stage2_mailer'] = 'error';
    $response['errors'][] = 'Mailer class load exception: ' . $e->getMessage();
}

// Stage 3: Test native mail() sending
try {
    // Disable error display and catch warnings as exceptions
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });
    
    $to = 'yasiridris6@gmail.com';
    $subject = 'GemVerify Mail Diagnostic Test';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: GemVerify Support <support@gemverify.com.ng>',
        'Reply-To: support@gemverify.com.ng'
    ];
    
    $sent = mail($to, $subject, '<h3>GemVerify Diagnostic Test</h3><p>If you see this, mail() works on live server.</p>', implode("\r\n", $headers));
    
    restore_error_handler();
    
    if ($sent) {
        $response['stage3_mail_function'] = 'success (mail sent)';
    } else {
        $response['stage3_mail_function'] = 'failed (mail function returned false)';
        $response['errors'][] = 'PHP native mail() function returned false. The mail transfer agent (MTA) may not be running or configured on this server.';
    }
} catch (Exception $e) {
    restore_error_handler();
    $response['stage3_mail_function'] = 'error';
    $response['errors'][] = 'mail() execution threw exception/error: ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
