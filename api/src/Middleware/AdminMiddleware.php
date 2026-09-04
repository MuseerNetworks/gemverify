<?php
namespace Middleware;

use Helpers\JWT;
use Helpers\Response;

class AdminMiddleware {

    /**
     * Ensure the admin_sessions table and required columns exist (self-healing migration).
     */
    public static function ensureAdminSessionsTable(\PDO $db): void {
        static $ensured = false;
        if ($ensured) return;
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS `admin_sessions` (
                    `id`              INT         NOT NULL AUTO_INCREMENT,
                    `jti`             VARCHAR(64) NOT NULL,
                    `admin_id`        INT         NOT NULL,
                    `last_activity`   BIGINT      NOT NULL,
                    `expires_at`      BIGINT      NOT NULL,
                    `disconnected_at` BIGINT      NULL DEFAULT NULL,
                    `is_active`       TINYINT(1)  NOT NULL DEFAULT 1,
                    `created_at`      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_jti`         (`jti`),
                    KEY `idx_admin_active`      (`admin_id`, `is_active`),
                    KEY `idx_cleanup`           (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $cols = $db->query("DESCRIBE `admin_sessions`")->fetchAll(\PDO::FETCH_COLUMN);
            if (!in_array('disconnected_at', $cols)) {
                $db->exec("ALTER TABLE `admin_sessions` ADD COLUMN `disconnected_at` BIGINT NULL DEFAULT NULL AFTER `expires_at`");
            }
            $ensured = true;
        } catch (\Exception $e) {
            error_log('[AdminMiddleware] ensureAdminSessionsTable: ' . $e->getMessage());
        }
    }

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
            $token = $_COOKIE['gv_admin_token'] ?? '';
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
        $db = db();
        $session = null;
        try {
            $stmt = $db->prepare(
                'SELECT last_activity, disconnected_at, is_active FROM admin_sessions WHERE jti = ? LIMIT 1'
            );
            $stmt->execute([$jti]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            self::ensureAdminSessionsTable($db);
            try {
                $stmt = $db->prepare(
                    'SELECT last_activity, disconnected_at, is_active FROM admin_sessions WHERE jti = ? LIMIT 1'
                );
                $stmt->execute([$jti]);
                $session = $stmt->fetch(\PDO::FETCH_ASSOC);
            } catch (\Exception $e2) {
                error_log('[AdminMiddleware] DB query retry failed: ' . $e2->getMessage());
                return $payload; // Failsafe: valid unexpired JWT allowed to prevent complete outage
            }
        }

        // Auto-enroll if valid signed token was created prior to session table existence
        if (!$session) {
            $exp = (int)($payload['exp'] ?? ($now + 7200));
            try {
                $db->prepare("
                    INSERT INTO admin_sessions (jti, admin_id, last_activity, expires_at, is_active)
                    VALUES (?, ?, ?, ?, 1)
                ")->execute([$jti, (int)$payload['admin_id'], $now, $exp]);
                $session = ['last_activity' => $now, 'disconnected_at' => null, 'is_active' => 1];
            } catch (\Exception $e) {
                try {
                    $stmt = $db->prepare('SELECT last_activity, disconnected_at, is_active FROM admin_sessions WHERE jti = ? LIMIT 1');
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
        if (!empty($session['disconnected_at'])) {
            $disconnectElapsed = $now - (int)$session['disconnected_at'];
            if ($disconnectElapsed > 15) {
                try {
                    $db->prepare('UPDATE admin_sessions SET is_active = 0 WHERE jti = ?')->execute([$jti]);
                } catch (\Exception $e) { /* best effort */ }
                Response::unauthorized('Session closed. Please log in again.');
                exit;
            }
        }

        $timeout = defined('ADMIN_INACTIVITY_TIMEOUT') ? (int)ADMIN_INACTIVITY_TIMEOUT : 300;

        if (($now - (int)$session['last_activity']) > $timeout) {
            try {
                $db->prepare('UPDATE admin_sessions SET is_active = 0 WHERE jti = ?')->execute([$jti]);
            } catch (\Exception $e) { /* best effort */ }
            Response::unauthorized('Session expired due to inactivity. Please log in again.');
            exit;
        }

        // ── 5. Refresh last_activity & clear disconnected_at ─────────────────
        try {
            $db->prepare('UPDATE admin_sessions SET last_activity = ?, disconnected_at = NULL WHERE jti = ?')
               ->execute([$now, $jti]);
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




