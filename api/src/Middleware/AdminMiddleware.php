<?php
namespace Middleware;

use Helpers\JWT;
use Helpers\Response;

class AdminMiddleware {

    /**
     * Validate admin bearer token AND enforce server-side session inactivity.
     *
     * Security policy (same as AuthMiddleware but for admin_sessions):
     *  - Tokens without `jti` are HARD REJECTED.
     *  - jti must exist in admin_sessions with is_active = 1.
     *  - Inactive beyond ADMIN_INACTIVITY_TIMEOUT → revoke + 401.
     *  - Every passing request updates last_activity.
     *
     * @return array Validated JWT payload
     */
    public static function handle(): array {
        // ── 1. Extract token ──────────────────────────────────────────────────
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

        // ── 2. Verify JWT signature + expiry ─────────────────────────────────
        $payload = JWT::decode($token);
        if (!$payload || !isset($payload['admin_id']) || !isset($payload['type']) || $payload['type'] !== 'admin') {
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

        // ── 4. DB session check ───────────────────────────────────────────────
        try {
            $db   = db();
            $stmt = $db->prepare(
                'SELECT last_activity, is_active FROM admin_sessions WHERE jti = ? LIMIT 1'
            );
            $stmt->execute([$jti]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            Response::serverError('Session validation unavailable');
            exit;
        }

        if (!$session || !(bool)$session['is_active']) {
            Response::unauthorized('Session terminated. Please log in again.');
            exit;
        }

        $timeout = defined('ADMIN_INACTIVITY_TIMEOUT') ? (int)ADMIN_INACTIVITY_TIMEOUT : 300;

        if (($now - (int)$session['last_activity']) > $timeout) {
            try {
                $db->prepare('UPDATE admin_sessions SET is_active = 0 WHERE jti = ?')->execute([$jti]);
            } catch (\Exception $e) { /* best effort */ }
            Response::unauthorized('Session expired due to inactivity. Please log in again.');
            exit;
        }

        // ── 5. Refresh last_activity ─────────────────────────────────────────
        try {
            $db->prepare('UPDATE admin_sessions SET last_activity = ? WHERE jti = ?')->execute([$now, $jti]);
        } catch (\Exception $e) { /* non-fatal */ }

        return $payload;
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
            'support'    => 1,
            'admin'      => 2,
            'super_admin'=> 3
        ];

        $userLevel    = $hierarchy[$role] ?? 1;
        $requiredLevel= $hierarchy[$minimumRole] ?? 3;

        if ($userLevel < $requiredLevel) {
            Response::forbidden("Action requires $minimumRole role or higher");
            exit;
        }
    }
}




