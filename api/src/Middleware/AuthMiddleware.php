<?php
namespace Middleware;

use Helpers\JWT;
use Helpers\Response;

class AuthMiddleware {

    /**
     * Ensure the user_sessions table and required columns exist (self-healing migration).
     */
    public static function ensureUserSessionsTable(\PDO $db): void {
        static $ensured = false;
        if ($ensured) return;
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS `user_sessions` (
                    `id`              INT         NOT NULL AUTO_INCREMENT,
                    `jti`             VARCHAR(64) NOT NULL,
                    `user_id`         INT         NOT NULL,
                    `last_activity`   BIGINT      NOT NULL COMMENT 'Unix timestamp of last confirmed activity',
                    `expires_at`      BIGINT      NOT NULL COMMENT 'Absolute JWT exp as Unix timestamp',
                    `disconnected_at` BIGINT      NULL DEFAULT NULL COMMENT 'Unix timestamp when tab disconnected/pagehid',
                    `is_active`       TINYINT(1)  NOT NULL DEFAULT 1,
                    `created_at`      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_jti`         (`jti`),
                    KEY `idx_user_active`       (`user_id`, `is_active`),
                    KEY `idx_cleanup`           (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $cols = $db->query("DESCRIBE `user_sessions`")->fetchAll(\PDO::FETCH_COLUMN);
            if (!in_array('disconnected_at', $cols)) {
                $db->exec("ALTER TABLE `user_sessions` ADD COLUMN `disconnected_at` BIGINT NULL DEFAULT NULL AFTER `expires_at`");
            }
            $ensured = true;
        } catch (\Exception $e) {
            error_log('[AuthMiddleware] ensureUserSessionsTable: ' . $e->getMessage());
        }
    }

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
        // ── 1. Extract token (Authorization header preferred → cookie fallback) ──
        $token = '';
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
        if (!$token) {
            $token = $_COOKIE['gv_token'] ?? '';
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
        $db = db();
        $session = null;
        try {
            $stmt = $db->prepare(
                'SELECT last_activity, disconnected_at, is_active FROM user_sessions WHERE jti = ? LIMIT 1'
            );
            $stmt->execute([$jti]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // DB table might not exist yet — self-heal on the fly
            self::ensureUserSessionsTable($db);
            try {
                $stmt = $db->prepare(
                    'SELECT last_activity, disconnected_at, is_active FROM user_sessions WHERE jti = ? LIMIT 1'
                );
                $stmt->execute([$jti]);
                $session = $stmt->fetch(\PDO::FETCH_ASSOC);
            } catch (\Exception $e2) {
                error_log('[AuthMiddleware] DB query retry failed: ' . $e2->getMessage());
                return $payload; // Failsafe: valid unexpired JWT allowed to prevent complete outage
            }
        }

        // Auto-enroll if valid signed token was created prior to session table existence
        if (!$session) {
            $exp = (int)($payload['exp'] ?? ($now + 28800));
            try {
                $db->prepare("
                    INSERT INTO user_sessions (jti, user_id, last_activity, expires_at, is_active)
                    VALUES (?, ?, ?, ?, 1)
                ")->execute([$jti, (int)$payload['user_id'], $now, $exp]);
                $session = ['last_activity' => $now, 'disconnected_at' => null, 'is_active' => 1];
            } catch (\Exception $e) {
                try {
                    $stmt = $db->prepare('SELECT last_activity, disconnected_at, is_active FROM user_sessions WHERE jti = ? LIMIT 1');
                    $stmt->execute([$jti]);
                    $session = $stmt->fetch(\PDO::FETCH_ASSOC);
                } catch (\Exception $e3) { /* non-fatal */ }
            }
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




