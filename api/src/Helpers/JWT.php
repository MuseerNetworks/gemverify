<?php

namespace Helpers;

class JWT
{
    public static function encode(array $payload, string $secret = JWT_SECRET, int $expiry = 86400): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload['iss'] = 'gemverify';
        $payload['iat'] = time();
        $payload['exp'] = time() + $expiry;
        
        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public static function decode(string $token, string $secret = JWT_SECRET): array|false
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        [$base64UrlHeader, $base64UrlPayload, $base64UrlSignature] = $parts;
        
        $signature = self::base64UrlDecode($base64UrlSignature);
        $expectedSignature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        
        if (!hash_equals($signature, $expectedSignature)) {
            return false;
        }
        
        $payload = json_decode(self::base64UrlDecode($base64UrlPayload), true);
        
        if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
            return false;
        }
        
        return $payload;
    }

    public static function verify(string $token, string $secret): bool
    {
        return self::decode($token, $secret) !== false;
    }

    private static function base64UrlEncode(string $data): string
    {
        $b64 = base64_encode($data);
        if ($b64 === false) {
            return '';
        }
        return str_replace(['+', '/', '='], ['-', '_', ''], $b64);
    }

    private static function base64UrlDecode(string $data): string
    {
        $b64 = str_replace(['-', '_'], ['+', '/'], $data);
        $padLength = strlen($b64) % 4;
        if ($padLength) {
            $b64 .= str_repeat('=', 4 - $padLength);
        }
        $decoded = base64_decode($b64, true);
        return $decoded !== false ? $decoded : '';
    }
}


