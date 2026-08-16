<?php
namespace Controllers\Admin;

use Helpers\Response;
use Helpers\Validator;
use Middleware\AdminMiddleware;
use PDO;

require_once __DIR__ . "/../../../config/database.php";

/**
 * AdminManagementController
 *
 * Super-admin-only endpoints for listing, creating, updating and
 * activating/deactivating administrator accounts.
 *
 * All methods enforce the 'super_admin' role via AdminMiddleware.
 */
class AdminManagementController {

    // ──────────────────────────────────────────────────────────────────────────
    // GET /admin/admins
    // List all admin accounts (super_admin only)
    // ──────────────────────────────────────────────────────────────────────────
    public function listAdmins(): void {
        AdminMiddleware::requireRole('super_admin');

        $db   = db();
        $stmt = $db->query("
            SELECT id, name, email, role, is_active, last_login_at, created_at
            FROM admins
            ORDER BY created_at ASC
        ");

        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast types for clean JSON
        foreach ($admins as &$a) {
            $a['id']        = (int) $a['id'];
            $a['is_active'] = (bool) $a['is_active'];
        }

        Response::success($admins);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /admin/admins
    // Create a new admin account (super_admin only)
    // ──────────────────────────────────────────────────────────────────────────
    public function createAdmin(): void {
        AdminMiddleware::requireRole('super_admin');

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

        // Only allow 'admin' or 'support' — super_admin cannot be created via this endpoint
        $allowedRoles = ['admin', 'support'];
        $role = $data['role'] ?? 'admin';
        if (!in_array($role, $allowedRoles, true)) {
            Response::error('Invalid role', ['role' => ['Role must be admin or support']], 422);
            return;
        }

        $db = db();

        // Check for duplicate email
        $check = $db->prepare("SELECT id FROM admins WHERE email = ?");
        $check->execute([trim($data['email'])]);
        if ($check->fetch()) {
            Response::error('Email already in use', ['email' => ['An admin with this email already exists']], 409);
            return;
        }

        $hash = password_hash($data['password'], PASSWORD_BCRYPT);

        $stmt = $db->prepare("
            INSERT INTO admins (name, email, password_hash, role, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([trim($data['name']), trim($data['email']), $hash, $role]);

        $newId = (int) $db->lastInsertId();

        Response::success([
            'id'    => $newId,
            'name'  => trim($data['name']),
            'email' => trim($data['email']),
            'role'  => $role
        ], 'Administrator account created successfully.', 201);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PATCH /admin/admins/{id}/role
    // Change the role of an admin account (super_admin only, cannot change own)
    // ──────────────────────────────────────────────────────────────────────────
    public function updateRole(int $id): void {
        AdminMiddleware::requireRole('super_admin');

        $currentAdminId = AdminMiddleware::getAdminId();

        if ($currentAdminId === $id) {
            Response::forbidden('You cannot change your own role.');
            return;
        }

        $data = $this->getJsonInput();
        $role = $data['role'] ?? '';

        $allowedRoles = ['super_admin', 'admin', 'support'];
        if (!in_array($role, $allowedRoles, true)) {
            Response::error('Invalid role', ['role' => ['Role must be super_admin, admin or support']], 422);
            return;
        }

        $db   = db();
        $stmt = $db->prepare("SELECT id FROM admins WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('Administrator not found.');
            return;
        }

        $db->prepare("UPDATE admins SET role = ? WHERE id = ?")->execute([$role, $id]);

        Response::success([], 'Role updated successfully.');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PATCH /admin/admins/{id}/active
    // Toggle active/inactive status (super_admin only, cannot deactivate own)
    // ──────────────────────────────────────────────────────────────────────────
    public function toggleActive(int $id): void {
        AdminMiddleware::requireRole('super_admin');

        $currentAdminId = AdminMiddleware::getAdminId();

        if ($currentAdminId === $id) {
            Response::forbidden('You cannot deactivate your own account.');
            return;
        }

        $db   = db();
        $stmt = $db->prepare("SELECT id, is_active FROM admins WHERE id = ?");
        $stmt->execute([$id]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            Response::notFound('Administrator not found.');
            return;
        }

        $newStatus = $admin['is_active'] ? 0 : 1;
        $db->prepare("UPDATE admins SET is_active = ? WHERE id = ?")->execute([$newStatus, $id]);

        $label = $newStatus ? 'activated' : 'deactivated';
        Response::success(['is_active' => (bool) $newStatus], "Administrator account {$label}.");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helper — read JSON request body
    // ──────────────────────────────────────────────────────────────────────────
    private function getJsonInput(): array {
        $raw = file_get_contents('php://input');
        return json_decode($raw, true) ?? [];
    }
}
