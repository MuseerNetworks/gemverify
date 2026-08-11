<?php
namespace Controllers;

use Helpers\Response;
use Helpers\Validator;
use Helpers\JWT;
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
        ]);

        setcookie("gv_token", $token, [
            'expires' => time() + 86400 * 30, // 30 days
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
        ]);

        setcookie("gv_admin_token", $token, [
            'expires' => time() + 86400 * 30, // 30 days
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
        
        // Exclude exp/iat from old payload
        unset($payload['iat'], $payload['exp']);
        
        $token = JWT::encode($payload);
        Response::success(['token' => $token]);
    }
}


