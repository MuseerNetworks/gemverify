<?php
namespace Middleware;

use Helpers\Response;
use PDO;

class RateLimitMiddleware {
    public static function enforce(string $endpoint, int $maxAttempts = 60, int $decaySeconds = 60): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $db = db();
        $now = time();

        // 1. Clean up expired rows
        $db->prepare("DELETE FROM rate_limits WHERE reset_time < ?")->execute([$now]);

        // 2. Fetch current attempts
        $stmt = $db->prepare("SELECT request_count, reset_time FROM rate_limits WHERE ip_address = ? AND endpoint = ?");
        $stmt->execute([$ip, $endpoint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if ($row['request_count'] >= $maxAttempts) {
                $retryAfter = $row['reset_time'] - $now;
                header("Retry-After: $retryAfter");
                Response::error('Too many requests. Please try again later.', [], 429);
                exit;
            }
            // Increment count
            $stmtUpd = $db->prepare("UPDATE rate_limits SET request_count = request_count + 1 WHERE ip_address = ? AND endpoint = ?");
            $stmtUpd->execute([$ip, $endpoint]);
        } else {
            // Insert new record
            $resetTime = $now + $decaySeconds;
            $stmtIns = $db->prepare("INSERT INTO rate_limits (ip_address, endpoint, request_count, reset_time) VALUES (?, ?, 1, ?)");
            $stmtIns->execute([$ip, $endpoint, $resetTime]);
        }
    }
}
