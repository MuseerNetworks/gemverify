<?php

// Environment Loader
function loadEnv(string $path): void {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0 || strpos($line, ';') === 0 || empty($line)) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $name = trim($parts[0]);
        $value = trim($parts[1]);
        
        // Remove surrounding quotes
        if (preg_match('/^"([^"]*)"$/', $value, $matches) || preg_match('/^\'([^\']*)\'$/', $value, $matches)) {
            $value = $matches[1];
        }
        
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

loadEnv(__DIR__ . '/../.env');

// Validate JWT_SECRET (Fail safely in production with clean error)
$jwtSecret = getenv('JWT_SECRET');
if (empty($jwtSecret)) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Configuration Error: JWT_SECRET environment variable is missing or empty.'
    ]);
    exit();
}

$storage_base = __DIR__ . '/../../../storage';
if (!is_dir($storage_base)) mkdir($storage_base, 0755, true);

$log_base = __DIR__ . '/../../../logs';
if (!is_dir($log_base)) mkdir($log_base, 0755, true);

define('APP_NAME', 'GemVerify');
define('APP_VERSION', '1.0.0');
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?? true, FILTER_VALIDATE_BOOLEAN));
define('JWT_SECRET', $jwtSecret);
define('JWT_EXPIRY', 86400); // 24 hours in seconds
define('JWT_REFRESH_EXPIRY', 604800); // 7 days
define('SESSION_INACTIVITY_TIMEOUT', 300); // 5 minutes — user portal server-side inactivity limit
define('ADMIN_INACTIVITY_TIMEOUT',   300); // 5 minutes — admin panel server-side inactivity limit
define('API_BASE_PATH', getenv('API_BASE_PATH') ?: '/api');
define('STORAGE_BASE_PATH', realpath($storage_base));
define('LOG_PATH', realpath($log_base));
define('MAX_DOCUMENT_SIZE', 2097152); // 2MB
define('MAX_SCREENSHOT_SIZE', 5242880); // 5MB
define('MAX_RESULT_SIZE', 10485760); // 10MB
define('ALLOWED_DOCUMENT_TYPES', ['image/jpeg', 'image/png', 'application/pdf']);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png']);
define('ALLOWED_RESULT_TYPES', ['image/jpeg', 'image/png', 'application/pdf']);
define('ALLOWED_DOCUMENT_EXTENSIONS', ['jpg', 'jpeg', 'png', 'pdf']);
define('ALLOWED_IMAGE_EXTENSIONS', ['jpg', 'jpeg', 'png']);
define('RATE_LIMIT_REQUESTS', 60);
define('RATE_LIMIT_WINDOW', 60);

// Load allowed origins from environment
define('CORS_ALLOWED_ORIGINS', getenv('CORS_ALLOWED_ORIGINS') ?: 'http://localhost/gemverify');

// ── TechHub Provider Configuration ──────────────────────────────────────────
// These values come exclusively from .env — never hardcoded, never exposed to frontend.
define('TECHHUB_BASE_URL',  rtrim(getenv('TECHHUB_BASE_URL') ?: '', '/'));
define('TECHHUB_API_KEY',   getenv('TECHHUB_API_KEY') ?: '');
define('TECHHUB_TIMEOUT',   (int)(getenv('TECHHUB_TIMEOUT') ?: 30));

// ── KatPay Payment Gateway Configuration ────────────────────────────────────
// These values come exclusively from .env — never hardcoded, never exposed to frontend.
define('KATPAY_API_KEY',      getenv('KATPAY_API_KEY')      ?: '');
define('KATPAY_SECRET_KEY',   getenv('KATPAY_SECRET_KEY')   ?: '');
define('KATPAY_MERCHANT_ID',  getenv('KATPAY_MERCHANT_ID')  ?: '');
define('KATPAY_WEBHOOK_SECRET', getenv('KATPAY_WEBHOOK_SECRET') ?: '');
define('KATPAY_BASE_URL',     rtrim(getenv('KATPAY_BASE_URL') ?: 'https://api.katpay.co/v1', '/'));
define('KATPAY_CALLBACK_URL', getenv('KATPAY_CALLBACK_URL') ?: '');
define('KATPAY_MIN_TOPUP',    (float)(getenv('KATPAY_MIN_TOPUP') ?: 100));
define('KATPAY_BANK_CODES',   getenv('KATPAY_BANK_CODES')   ?: 'PALMPAY,OPAY');

// ── S8V Identity Verification Provider Configuration ─────────────────────────
// These values come exclusively from .env — never hardcoded, never exposed to frontend.
define('S8V_API_BASE',        rtrim(getenv('S8V_API_BASE') ?: 'https://www.s8v.ng/api', '/'));
define('S8V_API_TOKEN',       getenv('S8V_API_TOKEN') ?: '');
define('S8V_WEBHOOK_SECRET',  getenv('S8V_WEBHOOK_SECRET') ?: '');
define('S8V_TIMEOUT',         (int)(getenv('S8V_TIMEOUT') ?: 30));




if (APP_DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

date_default_timezone_set('Africa/Lagos');

