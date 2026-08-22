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
            SELECT u.id, u.business_name, u.email, u.password_hash, u.is_active, w.balance 
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
        
        if (!$user['is_active']) {
            Response::forbidden('Account is disabled');
            return;
        }
        
        $db->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?")->execute([$user['id']]);
        
        $token = JWT::encode([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'business_name' => $user['business_name'],
            'type' => 'user'
        ], JWT_SECRET, 28800); // 8 hours absolute expiry

        setcookie("gv_token", $token, [
            'expires' => time() + 28800, // 8 hours absolute cookie expiry
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        Response::success([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'business_name' => $user['business_name'],
                'email' => $user['email']
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
        
        $token = JWT::encode([
            'admin_id' => $admin['id'],
            'email' => $admin['email'],
            'name' => $admin['name'],
            'role' => $admin['role'],
            'type' => 'admin'
        ], JWT_SECRET, 7200); // 2 hours absolute expiry

        setcookie("gv_admin_token", $token, [
            'expires' => time() + 7200, // 2 hours absolute cookie expiry
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        Response::success([
            'token' => $token,
            'admin' => [
                'id' => $admin['id'],
                'name' => $admin['name'],
                'email' => $admin['email'],
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
        $payload = AuthMiddleware::getPayload();
        
        $expiry = 86400; // default fallback (24h)
        if (($payload['type'] ?? '') === 'user') {
            $expiry = 28800; // 8h
        } elseif (($payload['type'] ?? '') === 'admin') {
            $expiry = 7200; // 2h
        }

        $token = JWT::encode($payload, JWT_SECRET, $expiry);
        Response::success(['token' => $token]);
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
        
        // Construct the reset URL
        $resetUrl = "{$protocol}://{$domain}/gemverify/user/?token={$token}";
        
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


