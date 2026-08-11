<?php
namespace Middleware;

use Helpers\JWT;
use Helpers\Response;

class AuthMiddleware {
    public static function handle(): array {
        $token = $_COOKIE['gv_token'] ?? '';
        if (!$token) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? '';
            if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
                $token = $matches[1];
            }
        }
        
        if (!$token) {
            Response::unauthorized('Missing or invalid session token');
            exit;
        }
        
        try {
            $payload = JWT::decode($token);
            if (!isset($payload['user_id']) || !isset($payload['type']) || $payload['type'] !== 'user') {
                Response::unauthorized('Invalid token payload');
                exit;
            }
            return $payload;
        } catch (\Exception $e) {
            Response::unauthorized('Token invalid or expired');
            exit;
        }
    }

    public static function getUserId(): int {
        $payload = self::handle();
        return (int) $payload['user_id'];
    }

    public static function getPayload(): array {
        return self::handle();
    }
}



