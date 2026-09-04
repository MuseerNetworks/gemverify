<?php
namespace Middleware;

use Helpers\JWT;
use Helpers\Response;

class AuthMiddleware {

    /**
     * Validate the bearer token AND enforce server-side session inactivity.
     *
     * Security policy:
     *  - Tokens without a `jti` claim are HARD REJECTED (no legacy bypass).
     *  - A valid jti must have an active record in `user_sessions`.
     *  - If (now - last_activity) > SESSION_INACTIVITY_TIMEOUT the session is
     *    revoked immediately and 401 is returned.
     *  - Every passing request updates last_activity to now().
     *
     * @return array Validated JWT payload
     */
    public static function handle(): array {
        // ── 1. Extract token (cookie preferred → Authorization header fallback) ──
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

        // ── 2. Verify JWT signature + expiry ─────────────────────────────────
        $payload = JWT::decode($token);
        if (!$payload || !isset($payload['user_id']) || !isset($payload['type']) || $payload['type'] !== 'user') {
            Response::unauthorized('Token invalid or expired');
            exit;
        }

        // ── 3. Hard cutover: jti required ────────────────────────────────────
        if (empty($payload['jti'])) {
            Response::unauthorized('Session invalid. Please log in again.');
            exit;
        }

        $jti = $payload['jti'];
        $now = time();

        // ── 4. DB session check: active + inactivity window ──────────────────
        try {
            $db   = db();
            $stmt = $db->prepare(
                'SELECT last_activity, disconnected_at, is_active FROM user_sessions WHERE jti = ? LIMIT 1'
            );
            $stmt->execute([$jti]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // DB failure — fail secure
            Response::serverError('Session validation unavailable');
            exit;
        }

        if (!$session || !(bool)$session['is_active']) {
            Response::unauthorized('Session terminated. Please log in again.');
            exit;
        }

        // ── 4a. Reconnect grace period (tab close vs page reload) ───────────
        // If disconnected_at was recorded by pagehide sendBeacon:
        // - If <= 15s elapsed: it's a page reload (F5). Allow through and clear timestamp.
        // - If > 15s elapsed: the tab was closed and abandoned. Invalidate session.
        if (!empty($session['disconnected_at'])) {
            $disconnectElapsed = $now - (int)$session['disconnected_at'];
            if ($disconnectElapsed > 15) {
                try {
                    $db->prepare('UPDATE user_sessions SET is_active = 0 WHERE jti = ?')->execute([$jti]);
                } catch (\Exception $e) { /* best effort */ }
                Response::unauthorized('Session closed. Please log in again.');
                exit;
            }
        }

        $timeout = defined('SESSION_INACTIVITY_TIMEOUT') ? (int)SESSION_INACTIVITY_TIMEOUT : 300;

        if (($now - (int)$session['last_activity']) > $timeout) {
            // Revoke the session
            try {
                $db->prepare('UPDATE user_sessions SET is_active = 0 WHERE jti = ?')->execute([$jti]);
            } catch (\Exception $e) { /* best effort */ }
            Response::unauthorized('Session expired due to inactivity. Please log in again.');
            exit;
        }

        // ── 5. Refresh last_activity & clear disconnected_at ─────────────────
        try {
            $db->prepare('UPDATE user_sessions SET last_activity = ?, disconnected_at = NULL WHERE jti = ?')
               ->execute([$now, $jti]);
        } catch (\Exception $e) { /* non-fatal */ }

        return $payload;
    }

    public static function getUserId(): int {
        $payload = self::handle();
        return (int) $payload['user_id'];
    }

    public static function getPayload(): array {
        return self::handle();
    }
}




