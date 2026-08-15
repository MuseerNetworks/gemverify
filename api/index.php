<?php
/**
 * GemVerify API — Main Router Entry Point
 * All requests routed here via .htaccess
 */

declare(strict_types=1);

use Helpers\Response;

// ── Bootstrap ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/storage.php';

// Manually load helpers (not namespaced)
require_once __DIR__ . '/src/Helpers/Response.php';
require_once __DIR__ . '/src/Helpers/JWT.php';
require_once __DIR__ . '/src/Helpers/Validator.php';
require_once __DIR__ . '/src/Helpers/Sanitizer.php';

// ── Autoloader ─────────────────────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
    $file  = __DIR__ . '/src/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// ── CORS ───────────────────────────────────────────────────────────────────
Response::cors();
header('Content-Type: application/json; charset=UTF-8');

// ── Parse Request ──────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['REQUEST_URI'] ?? '/';

// Strip query string
if (strpos($uri, '?') !== false) {
    $uri = substr($uri, 0, strpos($uri, '?'));
}

// Strip base path
$basePath = API_BASE_PATH;
if (strpos($uri, $basePath) === 0) {
    $uri = substr($uri, strlen($basePath));
}

$uri = '/' . trim($uri, '/');

// ── Route Registry ─────────────────────────────────────────────────────────
$routes = [];

function addRoute(string $method, string $pattern, callable $handler): void {
    global $routes;
    $routes[] = [
        'method'  => strtoupper($method),
        'pattern' => $pattern,
        'handler' => $handler,
    ];
}

function matchRoute(string $pattern, string $uri): array|false {
    $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
    $regex = '#^' . $regex . '$#';
    if (preg_match($regex, $uri, $matches)) {
        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }
    return false;
}

// ── Load Routes ────────────────────────────────────────────────────────────
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/user.php';
require_once __DIR__ . '/routes/admin.php';

// ── Dispatch ───────────────────────────────────────────────────────────────
$dispatched = false;

foreach ($routes as $route) {
    if ($route['method'] !== $method) {
        continue;
    }

    $params = matchRoute($route['pattern'], $uri);
    if ($params !== false) {
        try {
            call_user_func($route['handler'], $params);
        } catch (Throwable $e) {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                Response::serverError($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            } else {
                error_log('[GemVerify] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                Response::serverError('An internal error occurred. Please try again.');
            }
        }
        $dispatched = true;
        break;
    }
}

if (!$dispatched) {
    Response::notFound('Endpoint not found: ' . $method . ' ' . $uri);
}
