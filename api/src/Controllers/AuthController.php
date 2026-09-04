<?php
namespace Controllers;

use Helpers\Response;
use Helpers\Validator;
use Helpers\JWT;
use Helpers\Mailer;
require_once __DIR__ . "/../../config/database.php";
use Middleware\AuthMiddleware;
use PDO;
use Exception;

class AuthController {
    
    private function getJsonInput(): array {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }

    public function register(): void {
        $data = $this->getJsonInput();
        
        $v = new Validator($data);
        $v->required('business_name')
          ->required('email')->email('email')
          ->required('phone')->phone('phone')
          ->required('password')->password('password', 8);

        if ($data['password'] !== ($data['confirm_password'] ?? '')) {
            Response::error('Passwords do not match', ['confirm_password' => ['Passwords do not match']], 422);
            return;
        }

        if ($v->fails()) {
            Response::error('Validation failed', $v->errors(), 422);
            return;
        }

        $db = db();
        
        // Check email exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            Response::error('Email already registered', ['email' => ['Email already registered']], 409);
            return;
        }
        
        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);
        
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("INSERT INTO users (business_name, email, phone, password_hash, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([
                $data['business_name'],
                $data['email'],
                $data['phone'],
                $passwordHash
            ]);
            
            $userId = $db->lastInsertId();
            
            $stmt = $db->prepare("INSERT INTO wallets (user_id, balance, currency) VALUES (?, 0.00, 'NGN')");
            $stmt->execute([$userId]);

            // Pre-create a pending virtual_accounts row so the row always exists
            $db->prepare("
                INSERT IGNORE INTO virtual_accounts (user_id, status, created_at, updated_at)
                VALUES (?, 'pending', NOW(), NOW())
            ")->execute([$userId]);

            $db->commit();

            // Attempt KatPay virtual account provisioning after commit (non-blocking)
            // If KatPay fails, the pending row stays and will be retried on first wallet view
            try {
                $vaService = new \Services\VirtualAccountService($db);
                $vaService->createForUser((int) $userId, [
                    'business_name' => $data['business_name'],
                    'email'         => $data['email'],
                    'phone'         => $data['phone'],
                ]);
            } catch (\Throwable $e) {
                // Log but never fail registration
                error_log('[VirtualAccount] Auto-provision failed for user ' . $userId . ': ' . $e->getMessage());
            }
            
            Response::success([
                'user' => [
                    'id' => (int) $userId,
                    'business_name' => $data['business_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone']
                ]
            ], 'Registration successful', 201);
            
        } catch (Exception $e) {
            $db->rollBack();
            Response::error('Registration failed: ' . $e->getMessage(), [], 500);
        }
    }

    public function login(): void {
        $data = $this->getJsonInput();
        
        $v = new Validator($data);
        $v->required('email')->email('email')
          ->required('password');

        if ($v->fails()) {
            Response::error('Validation failed', $v->errors(), 422);
            return;
        }
        
        $db = db();
        
        $stmt = $db->prepare("
            SELECT u.id, u.business_name, u.email, u.password_hash, u.is_active, u.deleted_at, w.balance 
            FROM users u
            LEFT JOIN wallets w ON u.id = w.user_id
            WHERE u.email = ?
        ");
        $stmt->execute([$data['email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($data['password'], $user['password_hash'])) {
            Response::unauthorized('Invalid email or password');
            return;
        }
        
        if ((int)$user['is_active'] === 0 || !empty($user['deleted_at'])) {
            Response::forbidden('Account is disabled or suspended. Please contact support.');
            return;
        }
        
        $db->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?")->execute([$user['id']]);

        // Generate a unique JWT ID for server-side session tracking
        $jti    = bin2hex(random_bytes(16)); // 32-char hex
        $expiry = 28800; // 8 hours absolute
        $now    = time();

        $token = JWT::encode([
            'user_id'       => $user['id'],
            'email'         => $user['email'],
            'business_name' => $user['business_name'],
            'type'          => 'user',
            'jti'           => $jti
        ], JWT_SECRET, $expiry);

        // Revoke any existing active sessions for this user (single-session policy)
        $db->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ? AND is_active = 1")
           ->execute([$user['id']]);

        // Insert new session record
        $db->prepare("
            INSERT INTO user_sessions (jti, user_id, last_activity, expires_at)
            VALUES (?, ?, ?, ?)
        ")->execute([$jti, $user['id'], $now, $now + $expiry]);

        setcookie("gv_token", $token, [
            'expires'  => $now + $expiry,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        Response::success([
            'token' => $token,
            'user'  => [
                'id'            => $user['id'],
                'business_name' => $user['business_name'],
                'email'         => $user['email']
            ],
            'wallet_balance' => (float) $user['balance']
        ], 'Login successful');
    }

    public function adminLogin(): void {
        $data = $this->getJsonInput();
        
        $v = new Validator($data);
        $v->required('email')->email('email')
          ->required('password');

        if ($v->fails()) {
            Response::error('Validation failed', $v->errors(), 422);
            return;
        }
        
        $db = db();
        
        $stmt = $db->prepare("SELECT id, name, email, password_hash, role, is_active FROM admins WHERE email = ?");
        $stmt->execute([$data['email']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin || !password_verify($data['password'], $admin['password_hash'])) {
            Response::unauthorized('Invalid email or password');
            return;
        }
        
        if (!$admin['is_active']) {
            Response::forbidden('Admin account is disabled');
            return;
        }
        
        $db->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = ?")->execute([$admin['id']]);

        // Generate unique JWT ID for server-side admin session tracking
        $jti    = bin2hex(random_bytes(16));
        $expiry = 7200; // 2 hours absolute
        $now    = time();

        $token = JWT::encode([
            'admin_id' => $admin['id'],
            'email'    => $admin['email'],
            'name'     => $admin['name'],
            'role'     => $admin['role'],
            'type'     => 'admin',
            'jti'      => $jti
        ], JWT_SECRET, $expiry);

        // Revoke any existing active admin sessions (single-session policy)
        $db->prepare("UPDATE admin_sessions SET is_active = 0 WHERE admin_id = ? AND is_active = 1")
           ->execute([$admin['id']]);

        // Insert new admin session record
        $db->prepare("
            INSERT INTO admin_sessions (jti, admin_id, last_activity, expires_at)
            VALUES (?, ?, ?, ?)
        ")->execute([$jti, $admin['id'], $now, $now + $expiry]);

        setcookie("gv_admin_token", $token, [
            'expires'  => $now + $expiry,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        Response::success([
            'token' => $token,
            'admin' => [
                'id'   => $admin['id'],
                'name' => $admin['name'],
                'email'=> $admin['email'],
                'role' => $admin['role']
            ]
        ], 'Admin login successful');
    }

    // ── First-Admin Setup ────────────────────────────────────────────────────

    /**
     * GET /admin/setup
     * Returns whether first-admin setup is required (zero admins in DB).
     * Public endpoint — no authentication required.
     */
    public function checkSetupRequired(): void {
        $db    = db();
        $count = (int) $db->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        Response::success(['setup_required' => $count === 0]);
    }

    /**
     * POST /admin/setup
     * Creates the very first super_admin account.
     * Returns 403 immediately if any admin already exists — no exceptions.
     * Public endpoint — no authentication required.
     */
    public function createFirstAdmin(): void {
        $db    = db();
        $count = (int) $db->query("SELECT COUNT(*) FROM admins")->fetchColumn();

        // Hard server-side guard — cannot be bypassed by any frontend trick
        if ($count > 0) {
            Response::forbidden('Setup is disabled: an administrator account already exists.');
            return;
        }

        $data = $this->getJsonInput();

        $v = new Validator($data);
        $v->required('name')
          ->required('email')->email('email')
          ->required('password')->password('password', 8)
          ->required('confirm_password');

        if ($v->fails()) {
            Response::error('Validation failed', $v->errors(), 422);
            return;
        }

        if (($data['password'] ?? '') !== ($data['confirm_password'] ?? '')) {
            Response::error('Passwords do not match', ['confirm_password' => ['Passwords do not match']], 422);
            return;
        }

        $hash = password_hash($data['password'], PASSWORD_BCRYPT);

        $stmt = $db->prepare(
            "INSERT INTO admins (name, email, password_hash, role, is_active) VALUES (?, ?, ?, 'super_admin', 1)"
        );
        $stmt->execute([trim($data['name']), trim($data['email']), $hash]);

        Response::success([], 'Administrator account created successfully. Please sign in.');
    }

    public function setPin(): void {
        $userId = AuthMiddleware::getUserId();
        $data = $this->getJsonInput();
        
        $v = new Validator($data);
        $v->required('pin')->pin('pin');

        if (($data['pin'] ?? '') !== ($data['confirm_pin'] ?? '')) {
            Response::error('PINs do not match', ['confirm_pin' => ['PINs do not match']], 422);
            return;
        }

        if ($v->fails()) {
            Response::error('Validation failed', $v->errors(), 422);
            return;
        }
        
        $pinHash = password_hash($data['pin'], PASSWORD_BCRYPT);
        
        $db = db();
        $stmt = $db->prepare("UPDATE users SET pin_hash = ? WHERE id = ?");
        $stmt->execute([$pinHash, $userId]);
        
        Response::success([], 'PIN set successfully');
    }

    public function changePin(): void {
        $userId = AuthMiddleware::getUserId();
        $data = $this->getJsonInput();
        
        $v = new Validator($data);
        $v->required('current_pin')->required('new_pin')->pin('new_pin');

        if (($data['new_pin'] ?? '') !== ($data['confirm_new_pin'] ?? '')) {
            Response::error('New PINs do not match', ['confirm_new_pin' => ['New PINs do not match']], 422);
            return;
        }

        if ($v->fails()) {
            Response::error('Validation failed', $v->errors(), 422);
            return;
        }
        
        $db = db();
        $stmt = $db->prepare("SELECT pin_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($data['current_pin'], $user['pin_hash'])) {
            Response::error('Current PIN is incorrect', [], 400);
            return;
        }
        
        $pinHash = password_hash($data['new_pin'], PASSWORD_BCRYPT);
        $stmt = $db->prepare("UPDATE users SET pin_hash = ? WHERE id = ?");
        $stmt->execute([$pinHash, $userId]);
        
        Response::success([], 'PIN changed successfully');
    }

    public function me(): void {
        $userId = AuthMiddleware::getUserId();
        
        $db = db();
        $stmt = $db->prepare("
            SELECT u.id, u.business_name, u.email, u.phone, u.is_active, 
                   w.balance as wallet_balance,
                   (SELECT COUNT(*) FROM notifications WHERE user_id = u.id AND is_read = 0) as unread_notifications
            FROM users u
            LEFT JOIN wallets w ON u.id = w.user_id
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            Response::success(['message' => 'User not found'], 404);
            return;
        }
        
        // Cast types
        $user['wallet_balance'] = (float) $user['wallet_balance'];
        $user['unread_notifications'] = (int) $user['unread_notifications'];
        $user['is_active'] = (bool) $user['is_active'];
        
        Response::success($user);
    }

    public function refreshToken(): void {
        // Extract token: Authorization header preferred → cookie fallback
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
            $token = $_COOKIE['gv_admin_token'] ?? $_COOKIE['gv_token'] ?? '';
        }

        if (!$token) {
            Response::unauthorized('Missing session token');
            return;
        }

        $payload = JWT::decode($token);
        if (!$payload || empty($payload['type'])) {
            Response::unauthorized('Token invalid or expired');
            return;
        }

        $type = $payload['type'];
        if ($type !== 'admin' && $type !== 'user') {
            Response::unauthorized('Invalid token type');
            return;
        }

        if ($type === 'admin' && empty($payload['admin_id'])) {
            Response::unauthorized('Invalid admin token');
            return;
        }
        if ($type === 'user' && empty($payload['user_id'])) {
            Response::unauthorized('Invalid user token');
            return;
        }

        $now = time();
        $db = db();
        $oldJti = $payload['jti'] ?? null;

        // Verify existing session and enforce inactivity limit
        if ($oldJti) {
            $table = ($type === 'admin') ? 'admin_sessions' : 'user_sessions';
            if ($type === 'admin') {
                \Middleware\AdminMiddleware::ensureAdminSessionsTable($db);
            } else {
                \Middleware\AuthMiddleware::ensureUserSessionsTable($db);
            }

            try {
                $stmt = $db->prepare("SELECT last_activity, is_active FROM {$table} WHERE jti = ? LIMIT 1");
                $stmt->execute([$oldJti]);
                $sess = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($sess && !(bool)$sess['is_active']) {
                    Response::unauthorized('Session terminated. Please log in again.');
                    return;
                }

                $timeout = ($type === 'admin')
                    ? (defined('ADMIN_INACTIVITY_TIMEOUT') ? (int)ADMIN_INACTIVITY_TIMEOUT : 300)
                    : (defined('SESSION_INACTIVITY_TIMEOUT') ? (int)SESSION_INACTIVITY_TIMEOUT : 300);

                if ($sess && ($now - (int)$sess['last_activity']) > $timeout) {
                    $db->prepare("UPDATE {$table} SET is_active = 0 WHERE jti = ?")->execute([$oldJti]);
                    Response::unauthorized('Session expired due to inactivity. Please log in again.');
                    return;
                }

                // Revoke old session
                $db->prepare("UPDATE {$table} SET is_active = 0 WHERE jti = ?")->execute([$oldJti]);
            } catch (\Exception $e) {
                error_log('[RefreshToken] Session check error: ' . $e->getMessage());
            }
        }

        // Mint new token with fresh jti
        $expiry = ($type === 'admin') ? 7200 : 28800; // 2h admin, 8h user
        $newJti = bin2hex(random_bytes(16));
        $newPayload = $payload;
        $newPayload['jti'] = $newJti;
        unset($newPayload['iss'], $newPayload['iat'], $newPayload['exp']);

        $token = JWT::encode($newPayload, JWT_SECRET, $expiry);

        // Record new session in DB
        try {
            if ($type === 'admin') {
                $adminId = (int)$payload['admin_id'];
                \Middleware\AdminMiddleware::ensureAdminSessionsTable($db);
                $db->prepare("INSERT INTO admin_sessions (jti, admin_id, last_activity, expires_at, is_active) VALUES (?,?,?,?,1)")
                   ->execute([$newJti, $adminId, $now, $now + $expiry]);
                setcookie("gv_admin_token", $token, [
                    'expires'  => $now + $expiry, 'path' => '/',
                    'secure'   => isset($_SERVER['HTTPS']),
                    'httponly' => true, 'samesite' => 'Lax'
                ]);
            } else {
                $userId = (int)$payload['user_id'];
                \Middleware\AuthMiddleware::ensureUserSessionsTable($db);
                $db->prepare("INSERT INTO user_sessions (jti, user_id, last_activity, expires_at, is_active) VALUES (?,?,?,?,1)")
                   ->execute([$newJti, $userId, $now, $now + $expiry]);
                setcookie("gv_token", $token, [
                    'expires'  => $now + $expiry, 'path' => '/',
                    'secure'   => isset($_SERVER['HTTPS']),
                    'httponly' => true, 'samesite' => 'Lax'
                ]);
            }
        } catch (\Exception $e) {
            error_log('[RefreshToken] Session insert error: ' . $e->getMessage());
        }

        Response::success(['token' => $token]);
    }

    /**
     * POST /auth/logout
     * Revokes the server-side session and clears the HttpOnly cookie.
     * Accepts token from: HttpOnly cookie | Authorization header | JSON body
     * (JSON body path supports navigator.sendBeacon() calls on tab close).
     * Public endpoint — does not call AuthMiddleware (token may already be partially invalid).
     */
    public function logout(): void {
        // Extract token: Authorization header preferred → JSON body (sendBeacon) → cookie fallback
        $token = '';
        $headers     = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader  = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader && preg_match('/Bearer\s(\S+)/i', $authHeader, $m)) {
            $token = $m[1];
        }

        if (!$token) {
            // sendBeacon posts as application/json with token in body
            $body  = json_decode(file_get_contents('php://input'), true);
            $token = $body['token'] ?? '';
        }

        if (!$token) {
            $token = $_COOKIE['gv_admin_token'] ?? $_COOKIE['gv_token'] ?? '';
        }

        if ($token) {
            $payload = JWT::decode($token);
            if ($payload && !empty($payload['jti'])) {
                $jti  = $payload['jti'];
                $type = $payload['type'] ?? 'user';
                try {
                    $db = db();
                    if ($type === 'admin') {
                        \Middleware\AdminMiddleware::ensureAdminSessionsTable($db);
                        $db->prepare('UPDATE admin_sessions SET is_active = 0 WHERE jti = ?')->execute([$jti]);
                    } else {
                        \Middleware\AuthMiddleware::ensureUserSessionsTable($db);
                        $db->prepare('UPDATE user_sessions  SET is_active = 0 WHERE jti = ?')->execute([$jti]);
                    }
                } catch (\Exception $e) { /* best effort */ }
            }
        }

        // Clear HttpOnly cookies regardless of token validity
        $cookieBase = [
            'expires' => time() - 3600,
            'path'    => '/',
            'secure'  => isset($_SERVER['HTTPS']),
            'httponly'=> true,
            'samesite'=> 'Lax'
        ];
        setcookie('gv_token',       '', $cookieBase);
        setcookie('gv_admin_token', '', $cookieBase);

        Response::success(null, 'Logged out successfully');
    }

    /**
     * POST /auth/disconnect
     * Records disconnected_at timestamp when a tab unloads or pagehides.
     * Unlike logout(), this does NOT set is_active = 0 immediately.
     * It allows a 15-second grace period so page refreshes (F5) can reconnect seamlessly.
     * If no reconnect occurs within 15 seconds, the session is invalidated by middleware.
     */
    public function disconnect(): void {
        $token = '';
        $headers    = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader && preg_match('/Bearer\s(\S+)/i', $authHeader, $m)) {
            $token = $m[1];
        }

        if (!$token) {
            $body  = json_decode(file_get_contents('php://input'), true);
            $token = $body['token'] ?? '';
        }

        if (!$token) {
            $token = $_COOKIE['gv_admin_token'] ?? $_COOKIE['gv_token'] ?? '';
        }

        if ($token) {
            $payload = JWT::decode($token);
            if ($payload && !empty($payload['jti'])) {
                $jti  = $payload['jti'];
                $type = $payload['type'] ?? 'user';
                $now  = time();
                try {
                    $db = db();
                    if ($type === 'admin') {
                        \Middleware\AdminMiddleware::ensureAdminSessionsTable($db);
                        $db->prepare('UPDATE admin_sessions SET disconnected_at = ? WHERE jti = ? AND is_active = 1')
                           ->execute([$now, $jti]);
                    } else {
                        \Middleware\AuthMiddleware::ensureUserSessionsTable($db);
                        $db->prepare('UPDATE user_sessions SET disconnected_at = ? WHERE jti = ? AND is_active = 1')
                           ->execute([$now, $jti]);
                    }
                } catch (\Exception $e) { /* best effort */ }
            }
        }

        Response::success(null, 'Disconnected');
    }

    /**
     * POST /auth/heartbeat
     * Lightweight protected endpoint the frontend calls to signal user activity.
     * Middleware updates last_activity as a side-effect of any
     * protected call, so this simply returns 200 to confirm the session is alive.
     */
    public function heartbeat(): void {
        $headers    = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $token      = '';
        if ($authHeader && preg_match('/Bearer\s(\S+)/i', $authHeader, $m)) {
            $token = $m[1];
        }
        if (!$token) {
            $token = $_COOKIE['gv_admin_token'] ?? $_COOKIE['gv_token'] ?? '';
        }

        if ($token) {
            $payload = JWT::decode($token);
            if ($payload && ($payload['type'] ?? '') === 'admin') {
                \Middleware\AdminMiddleware::handle();
                Response::success(null, 'ok');
                return;
            }
        }

        AuthMiddleware::getUserId(); // validates user token + updates last_activity
        Response::success(null, 'ok');
    }


    public function changePassword(): void {
        $userId = AuthMiddleware::getUserId();
        $data = $this->getJsonInput();
        
        $v = new \Helpers\Validator($data);
        $v->required('current_password')
          ->required('new_password')
          ->password('new_password', 8);

        if (($data['new_password'] ?? '') !== ($data['confirm_new_password'] ?? '')) {
            Response::error('New passwords do not match', ['confirm_new_password' => ['New passwords do not match']], 422);
            return;
        }

        if ($v->fails()) {
            Response::error('Validation failed', $v->errors(), 422);
            return;
        }
        
        $db = db();
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($data['current_password'], $user['password_hash'])) {
            Response::error('Current password is incorrect', [], 400);
            return;
        }
        
        $passwordHash = password_hash($data['new_password'], PASSWORD_BCRYPT);
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$passwordHash, $userId]);
        
        Response::success([], 'Password changed successfully');
    }

    public function forgotPassword(): void {
        $data = $this->getJsonInput();
        
        $v = new Validator($data);
        $v->required('email')->email('email');

        if ($v->fails()) {
            Response::error('Validation failed', $v->errors(), 422);
            return;
        }
        
        $db = db();
        
        // Check if user exists
        $stmt = $db->prepare("SELECT id, business_name FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$data['email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            // Return success even if email is not found to prevent user enumeration attacks
            Response::success([], 'If your email is registered, you will receive a password reset link shortly.');
            return;
        }
        
        // Generate secure 64-char token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiration
        
        // Save or update token
        $stmt = $db->prepare("
            INSERT INTO password_resets (email, token, expires_at)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), created_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$data['email'], $token, $expiresAt]);
        
        // Send email
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        
        // Dynamically determine the base path of the project relative to document root
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $apiPos = strpos($scriptName, '/api/');
        $basePath = ($apiPos !== false) ? substr($scriptName, 0, $apiPos) . '/' : '/';
        
        // Construct the reset URL dynamically
        $resetUrl = "{$protocol}://{$domain}{$basePath}user/?token={$token}";
        
        $subject = "Password Reset Request — GemVerify";
        $htmlContent = "
            <div style='font-family: Arial, sans-serif; max-width: 500px; margin: auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 16px;'>
                <h2 style='color: #0050FF; margin-top: 0;'>GemVerify Portal</h2>
                <p>Hello <strong>" . htmlspecialchars($user['business_name']) . "</strong>,</p>
                <p>We received a request to reset your password. Click the button below to choose a new one. This link will expire in 1 hour:</p>
                <div style='text-align: center; margin: 24px 0;'>
                    <a href='" . htmlspecialchars($resetUrl) . "' style='background-color: #0050FF; color: white; padding: 12px 24px; text-decoration: none; font-weight: bold; border-radius: 8px; display: inline-block;'>Reset Password</a>
                </div>
                <p style='font-size: 12px; color: #64748b;'>If the button above does not work, copy and paste this URL into your browser:</p>
                <p style='font-size: 12px; word-break: break-all; color: #0050FF;'>" . htmlspecialchars($resetUrl) . "</p>
                <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0;'>
                <p style='font-size: 11px; color: #94a3b8;'>If you did not request this, you can safely ignore this email.</p>
            </div>
        ";
        
        $sent = Mailer::sendHTML($data['email'], $subject, $htmlContent);
        
        if ($sent) {
            Response::success([], 'If your email is registered, you will receive a password reset link shortly.');
        } else {
            Response::error('Failed to send reset email. Please contact support.', [], 500);
        }
    }

    public function resetPassword(): void {
        $data = $this->getJsonInput();
        
        $v = new Validator($data);
        $v->required('token')
          ->required('password')->password('password', 8);
          
        if ($v->fails()) {
            Response::error('Validation failed', $v->errors(), 422);
            return;
        }
        
        $db = db();
        
        // Find token
        $stmt = $db->prepare("SELECT email, expires_at FROM password_resets WHERE token = ?");
        $stmt->execute([$data['token']]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reset) {
            Response::error('Invalid or expired reset token.', [], 400);
            return;
        }
        
        // Check expiration
        if (strtotime($reset['expires_at']) < time()) {
            // Delete expired token
            $db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$reset['email']]);
            Response::error('Your reset token has expired. Please request a new link.', [], 400);
            return;
        }
        
        // Update user password
        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);
        
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
            $stmt->execute([$passwordHash, $reset['email']]);
            
            // Delete token
            $db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$reset['email']]);
            
            $db->commit();
            Response::success([], 'Password has been reset successfully. You can now log in.');
        } catch (Exception $e) {
            $db->rollBack();
            Response::error('Failed to reset password: ' . $e->getMessage(), [], 500);
        }
    }
}


