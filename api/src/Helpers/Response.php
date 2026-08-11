<?php

namespace Helpers;

class Response
{
    public static function cors(): void
    {
        $allowed = defined('CORS_ALLOWED_ORIGINS') ? CORS_ALLOWED_ORIGINS : '*';
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if ($allowed === '*') {
            header("Access-Control-Allow-Origin: *");
        } else {
            $origins = array_map('trim', explode(',', $allowed));
            if (in_array($origin, $origins)) {
                header("Access-Control-Allow-Origin: " . $origin);
                header("Access-Control-Allow-Credentials: true");
            } else {
                // If origin is not allowed, do not send Access-Control-Allow-Origin header.
                // We should still allow preflight requests to succeed with HTTP 200, 
                // but the browser will reject the actual request due to the missing header.
            }
        }
        
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Idempotency-Key");
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }

    public static function success(mixed $data = [], string $message = 'Success', int $httpCode = 200): never
    {
        self::send(true, $message, $data, [], $httpCode);
    }

    public static function error(string $message, array $errors = [], int $httpCode = 400): never
    {
        self::send(false, $message, [], $errors, $httpCode);
    }

    public static function unauthorized(string $message = 'Unauthorized'): never
    {
        self::send(false, $message, [], [], 401);
    }

    public static function forbidden(string $message = 'Forbidden'): never
    {
        self::send(false, $message, [], [], 403);
    }

    public static function notFound(string $message = 'Not found'): never
    {
        self::send(false, $message, [], [], 404);
    }

    public static function serverError(string $message = 'Server error'): never
    {
        self::send(false, $message, [], [], 500);
    }

    private static function send(bool $success, string $message, mixed $data, array $errors, int $httpCode): never
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        
        $response = [
            'success' => $success,
            'message' => $message,
        ];
        
        if (!empty($data) || $success) {
            $response['data'] = $data;
        } else {
            $response['data'] = new \stdClass();
        }
        
        if (!empty($errors)) {
            $response['errors'] = $errors;
        } else {
            $response['errors'] = [];
        }
        
        echo json_encode($response);
        exit();
    }
}


