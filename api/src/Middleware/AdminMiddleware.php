<?php
namespace Middleware;

use Helpers\JWT;
use Helpers\Response;

class AdminMiddleware {
    public static function handle(): array {
        $token = $_COOKIE['gv_admin_token'] ?? '';
        if (!$token) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            if (!$authHeader && function_exists('getallheaders')) {
                $headers = getallheaders();
                foreach ($headers as $k => $v) {
                    if (strcasecmp($k, 'Authorization') === 0) {
                        $authHeader = $v;
                        break;
                    }
                }
            }
            if ($authHeader && preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
                $token = $matches[1];
            }
        }
        
        if (!$token) {
            Response::unauthorized('Missing or invalid session token');
            exit;
        }
        
        try {
            $payload = JWT::decode($token);
            if (!isset($payload['admin_id']) || !isset($payload['type']) || $payload['type'] !== 'admin') {
                Response::unauthorized('Invalid token payload');
                exit;
            }
            return $payload;
        } catch (\Exception $e) {
            Response::unauthorized('Token invalid or expired');
            exit;
        }
    }

    public static function getAdminId(): int {
        $payload = self::handle();
        return (int) $payload['admin_id'];
    }

    public static function getRole(): string {
        $payload = self::handle();
        return (string) ($payload['role'] ?? 'support');
    }

    public static function requireRole(string $minimumRole): void {
        $payload = self::handle();
        $role = $payload['role'] ?? 'support';
        
        $hierarchy = [
            'support' => 1,
            'admin' => 2,
            'super_admin' => 3
        ];
        
        $userLevel = $hierarchy[$role] ?? 1;
        $requiredLevel = $hierarchy[$minimumRole] ?? 3;

        if ($userLevel < $requiredLevel) {
            Response::forbidden("Action requires $minimumRole role or higher");
            exit;
        }
    }
}



