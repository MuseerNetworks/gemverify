<?php
namespace Controllers\Admin;

use Helpers\Response;
use Middleware\AdminMiddleware;
use PDO;
use Exception;

class UserAdminController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = db();
    }

    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }

    /**
     * GET /admin/users
     * Lists users with filtering, search, and pagination.
     * Query params:
     *   - filter: all | active | suspended | today
     *   - search: query string (matches ID, business_name, email, phone)
     *   - page: integer (default 1)
     *   - per_page: integer (default 50)
     */
    public function getUsers(): void
    {
        AdminMiddleware::requireRole('support'); // support, admin, or super_admin

        try {
            $page    = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 50)));
            $offset  = ($page - 1) * $perPage;
            $filter  = $_GET['filter'] ?? 'all';
            $search  = trim($_GET['search'] ?? $_GET['q'] ?? '');

            $whereClauses = [];
            $params       = [];

            // Status filter
            if ($filter === 'active') {
                $whereClauses[] = 'u.is_active = 1 AND u.deleted_at IS NULL';
            } elseif ($filter === 'suspended') {
                $whereClauses[] = '(u.is_active = 0 OR u.deleted_at IS NOT NULL)';
            } elseif ($filter === 'today') {
                $whereClauses[] = 'u.is_active = 1 AND u.updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
            }

            // Search filter (ID, name, email, phone)
            if ($search !== '') {
                if (ctype_digit($search)) {
                    $whereClauses[] = '(u.id = :search_id OR u.business_name LIKE :search_like OR u.email LIKE :search_like OR u.phone LIKE :search_like)';
                    $params['search_id']   = (int) $search;
                    $params['search_like'] = '%' . $search . '%';
                } else {
                    $whereClauses[] = '(u.business_name LIKE :search_like OR u.email LIKE :search_like OR u.phone LIKE :search_like)';
                    $params['search_like'] = '%' . $search . '%';
                }
            }

            $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

            // Count total matching
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM users u $whereSql");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            // Check if api_transactions exists for unified request count
            $apiTxnExists = (bool) $this->db->query("
                SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = 'api_transactions'
            ")->fetchColumn();

            $reqCountSql = $apiTxnExists
                ? "((SELECT COUNT(*) FROM manual_requests WHERE user_id = u.id) + (SELECT COUNT(*) FROM api_transactions WHERE user_id = u.id))"
                : "(SELECT COUNT(*) FROM manual_requests WHERE user_id = u.id)";

            // Fetch users with wallet and request count
            $sql = "
                SELECT u.id, u.business_name, u.email, u.phone,
                       u.is_active, u.deleted_at, u.created_at, u.updated_at,
                       COALESCE(w.balance, 0) AS wallet_balance,
                       $reqCountSql AS request_count
                FROM users u
                LEFT JOIN wallets w ON w.user_id = u.id
                $whereSql
                ORDER BY u.created_at DESC
                LIMIT $perPage OFFSET $offset
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format boolean / numerical fields
            foreach ($users as &$u) {
                $u['id']             = (int) $u['id'];
                $u['is_active']      = (int) $u['is_active'];
                $u['is_suspended']   = ($u['is_active'] === 0 || !empty($u['deleted_at'])) ? 1 : 0;
                $u['wallet_balance'] = (float) $u['wallet_balance'];
                $u['request_count']  = (int) $u['request_count'];
            }
            unset($u);

            Response::success([
                'users'      => $users,
                'total'      => $total,
                'page'       => $page,
                'per_page'   => $perPage,
                'last_page'  => (int) ceil($total / $perPage),
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    /**
     * GET /admin/users/{id}
     * Retrieves full detail of a specific user.
     */
    public function getUserDetail(int $id): void
    {
        AdminMiddleware::requireRole('support');

        try {
            $stmt = $this->db->prepare("
                SELECT u.id, u.business_name, u.email, u.phone,
                       u.account_name, u.account_number,
                       u.is_active, u.deleted_at, u.created_at, u.updated_at,
                       COALESCE(w.balance, 0) AS wallet_balance,
                       (SELECT COUNT(*) FROM manual_requests WHERE user_id = u.id) AS manual_requests_count,
                       (SELECT COUNT(*) FROM transactions WHERE user_id = u.id) AS transactions_count
                FROM users u
                LEFT JOIN wallets w ON w.user_id = u.id
                WHERE u.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                Response::notFound('User not found');
                return;
            }

            // Include api_transactions if table exists
            $apiTxnExists = (bool) $this->db->query("
                SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = 'api_transactions'
            ")->fetchColumn();

            $apiCount = 0;
            if ($apiTxnExists) {
                $apiStmt = $this->db->prepare("SELECT COUNT(*) FROM api_transactions WHERE user_id = ?");
                $apiStmt->execute([$id]);
                $apiCount = (int) $apiStmt->fetchColumn();
            }

            $user['id']                    = (int) $user['id'];
            $user['is_active']             = (int) $user['is_active'];
            $user['is_suspended']          = ($user['is_active'] === 0 || !empty($user['deleted_at'])) ? 1 : 0;
            $user['wallet_balance']        = (float) $user['wallet_balance'];
            $user['manual_requests_count'] = (int) $user['manual_requests_count'];
            $user['api_requests_count']    = $apiCount;
            $user['total_requests']        = $user['manual_requests_count'] + $apiCount;
            $user['transactions_count']    = (int) $user['transactions_count'];

            Response::success($user);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    /**
     * POST /admin/users/{id}/restore
     * Restores / reactivates a suspended or soft-deleted user.
     * Preserves user ID, wallet, transactions, and verification records.
     */
    public function restoreUser(int $id): void
    {
        AdminMiddleware::requireRole('admin'); // admin or super_admin
        $adminId = AdminMiddleware::getAdminId();

        $data   = $this->getJsonInput();
        $reason = trim($data['reason'] ?? '');

        try {
            // Retrieve target user
            $stmt = $this->db->prepare("SELECT id, business_name, email, is_active, deleted_at FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                Response::notFound('User not found');
                return;
            }

            // Check if already active and not deleted
            if ((int) $user['is_active'] === 1 && empty($user['deleted_at'])) {
                Response::error('User account is already active', [], 400);
                return;
            }

            $prevStatus = (int) $user['is_active'] === 0 ? 'suspended' : 'deleted';

            $this->db->beginTransaction();

            // 1. Update user to active status and clear soft-delete
            $updateStmt = $this->db->prepare("
                UPDATE users
                SET is_active = 1, deleted_at = NULL, updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$id]);

            // 2. Write audit log
            $auditNotes = "Admin #{$adminId} restored User #{$id} ({$user['email']}). Previous status: {$prevStatus}.";
            if ($reason !== '') {
                $auditNotes .= " Reason: " . $reason;
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $auditStmt = $this->db->prepare("
                INSERT INTO audit_logs (actor_type, actor_id, action, old_value, new_value, notes, ip_address, created_at)
                VALUES ('admin', ?, 'ACCOUNT_RESTORED', ?, ?, ?, ?, NOW())
            ");
            $auditStmt->execute([
                $adminId,
                json_encode(['is_active' => (int) $user['is_active'], 'deleted_at' => $user['deleted_at']]),
                json_encode(['is_active' => 1, 'deleted_at' => null]),
                $auditNotes,
                $ip
            ]);

            $this->db->commit();

            Response::success([
                'user_id'       => $id,
                'business_name' => $user['business_name'],
                'email'         => $user['email'],
                'is_active'     => 1,
                'status'        => 'active'
            ], 'User account restored successfully');
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Response::error('Failed to restore user: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * POST /admin/users/{id}/suspend
     * Suspends / deactivates an active user and revokes all active sessions.
     */
    public function suspendUser(int $id): void
    {
        AdminMiddleware::requireRole('admin');
        $adminId = AdminMiddleware::getAdminId();

        $data   = $this->getJsonInput();
        $reason = trim($data['reason'] ?? '');

        try {
            $stmt = $this->db->prepare("SELECT id, business_name, email, is_active, deleted_at FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                Response::notFound('User not found');
                return;
            }

            if ((int) $user['is_active'] === 0) {
                Response::error('User account is already suspended', [], 400);
                return;
            }

            $this->db->beginTransaction();

            // 1. Mark inactive
            $updateStmt = $this->db->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ?");
            $updateStmt->execute([$id]);

            // 2. Immediately revoke any active sessions in user_sessions
            try {
                $this->db->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ? AND is_active = 1")
                         ->execute([$id]);
            } catch (Exception $e) { /* table might not exist in old tests */ }

            // 3. Write audit log
            $auditNotes = "Admin #{$adminId} suspended User #{$id} ({$user['email']}).";
            if ($reason !== '') {
                $auditNotes .= " Reason: " . $reason;
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $auditStmt = $this->db->prepare("
                INSERT INTO audit_logs (actor_type, actor_id, action, old_value, new_value, notes, ip_address, created_at)
                VALUES ('admin', ?, 'ACCOUNT_SUSPENDED', ?, ?, ?, ?, NOW())
            ");
            $auditStmt->execute([
                $adminId,
                json_encode(['is_active' => 1]),
                json_encode(['is_active' => 0]),
                $auditNotes,
                $ip
            ]);

            $this->db->commit();

            Response::success([
                'user_id'       => $id,
                'business_name' => $user['business_name'],
                'email'         => $user['email'],
                'is_active'     => 0,
                'status'        => 'suspended'
            ], 'User account suspended successfully');
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Response::error('Failed to suspend user: ' . $e->getMessage(), [], 500);
        }
    }
}
