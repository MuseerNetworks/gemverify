<?php
// Enable display errors for diagnostics
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain'); // Plain text so it displays nicely in browser

echo "=== GemVerify Live Diagnostics ===\n\n";

try {
    echo "1. Loading config files...\n";
    require_once __DIR__ . '/config/app.php';
    echo "✓ config/app.php loaded\n";
    
    require_once __DIR__ . '/config/database.php';
    echo "✓ config/database.php loaded\n";
    
    // Safe autoloader
    echo "2. Setting up autoloader...\n";
    spl_autoload_register(function (string $class): void {
        $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
        $file  = __DIR__ . '/src/' . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            echo "✓ Autoloaded class: $class\n";
        }
    });
    
    // Test database connection
    echo "3. Testing database connection...\n";
    $db = db();
    echo "✓ Database connected successfully\n";
    
    // Check if table exists
    echo "4. Checking password_resets table...\n";
    $stmt = $db->query("SHOW TABLES LIKE 'password_resets'");
    if ($stmt->rowCount() > 0) {
        echo "✓ Table 'password_resets' exists\n";
    } else {
        echo "✗ Table 'password_resets' does NOT exist\n";
    }
    
    // Check Mailer class
    echo "5. Testing Mailer class loading...\n";
    if (class_exists('Helpers\Mailer')) {
        echo "✓ Helpers\\Mailer loaded successfully\n";
    } else {
        echo "✗ Helpers\\Mailer class could not be loaded\n";
    }
    
    // Test mail function
    echo "6. Testing native mail() function...\n";
    $to = 'yasiridris6@gmail.com';
    $subject = 'GemVerify Mail Diagnostic Test';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: GemVerify Support <support@gemverify.com.ng>',
        'Reply-To: support@gemverify.com.ng'
    ];
    
    // Safe mail execution
    $sent = @mail($to, $subject, '<h3>GemVerify Diagnostic Test</h3><p>Diagnostic test</p>', implode("\r\n", $headers));
    if ($sent) {
        echo "✓ mail() function returned true (email sent successfully)\n";
    } else {
        echo "✗ mail() function returned false (MTA delivery failure)\n";
    }
    
} catch (Throwable $t) {
    echo "\n!!! FATAL ERROR CAUGHT !!!\n";
    echo "Message: " . $t->getMessage() . "\n";
    echo "File: " . $t->getFile() . "\n";
    echo "Line: " . $t->getLine() . "\n";
    echo "Trace:\n" . $t->getTraceAsString() . "\n";
}
