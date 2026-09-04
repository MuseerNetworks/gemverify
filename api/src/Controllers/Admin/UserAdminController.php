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

            // Search filter (ID, name, email, phone, NUBAN account number)
            if ($search !== '') {
                if (ctype_digit($search)) {
                    $whereClauses[] = '(u.id = :search_id OR u.business_name LIKE :search_like OR u.email LIKE :search_like OR u.phone LIKE :search_like OR va.account_number LIKE :search_like OR u.account_number LIKE :search_like)';
                    $params['search_id']   = (int) $search;
                    $params['search_like'] = '%' . $search . '%';
                } else {
                    $whereClauses[] = '(u.business_name LIKE :search_like OR u.email LIKE :search_like OR u.phone LIKE :search_like OR va.account_number LIKE :search_like OR va.bank_name LIKE :search_like)';
                    $params['search_like'] = '%' . $search . '%';
                }
            }

            $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

            // Count total matching
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM users u LEFT JOIN virtual_accounts va ON va.user_id = u.id $whereSql");
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

            // Fetch users with wallet, request count, and virtual account
            $sql = "
                SELECT u.id, u.business_name, u.email, u.phone,
                       u.is_active, u.deleted_at, u.created_at, u.updated_at,
                       COALESCE(w.balance, 0) AS wallet_balance,
                       $reqCountSql AS request_count,
                       va.account_number AS va_account_number,
                       va.bank_name AS va_bank_name,
                       va.account_name AS va_account_name,
                       COALESCE(va.status, 'none') AS va_status
                FROM users u
                LEFT JOIN wallets w ON w.user_id = u.id
                LEFT JOIN virtual_accounts va ON va.user_id = u.id
                $whereSql
                ORDER BY u.created_at DESC
                LIMIT $perPage OFFSET $offset
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format boolean / numerical / virtual account fields
            foreach ($users as &$u) {
                $u['id']             = (int) $u['id'];
                $u['is_active']      = (int) $u['is_active'];
                $u['is_suspended']   = ($u['is_active'] === 0 || !empty($u['deleted_at'])) ? 1 : 0;
                $u['wallet_balance'] = (float) $u['wallet_balance'];
                $u['request_count']  = (int) $u['request_count'];
                $u['account_number'] = $u['va_account_number'] ?: ($u['account_number'] ?? null);
                $u['bank_name']      = $u['va_bank_name'] ?? null;
                $u['va_status']      = $u['va_status'] ?? 'none';
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

            // Fetch dedicated virtual bank account details
            $vaStmt = $this->db->prepare("
                SELECT account_number, account_name, bank_name, bank_code, currency, status, last_credit_at, created_at, updated_at
                FROM virtual_accounts
                WHERE user_id = ?
                LIMIT 1
            ");
            $vaStmt->execute([$id]);
            $va = $vaStmt->fetch(PDO::FETCH_ASSOC);

            if ($va) {
                $user['virtual_account'] = [
                    'account_number' => $va['account_number'] ?: ($user['account_number'] ?? null),
                    'account_name'   => $va['account_name']   ?: ($user['account_name'] ?? null),
                    'bank_name'      => $va['bank_name']      ?? null,
                    'bank_code'      => $va['bank_code']      ?? null,
                    'currency'       => $va['currency']       ?? 'NGN',
                    'status'         => $va['status']         ?? 'none',
                    'last_credit_at' => $va['last_credit_at'] ?? null,
                    'created_at'     => $va['created_at']     ?? null,
                ];
            } else {
                $user['virtual_account'] = [
                    'account_number' => $user['account_number'] ?? null,
                    'account_name'   => $user['account_name']   ?? null,
                    'bank_name'      => null,
                    'bank_code'      => null,
                    'currency'       => 'NGN',
                    'status'         => !empty($user['account_number']) ? 'active' : 'none',
                    'last_credit_at' => null,
                    'created_at'     => null,
                ];
            }

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

    /**
     * POST /admin/users/{id}/virtual-account/retrieve
     * Retrieves or re-generates dedicated funding virtual account for a user via KatPay.
     * Supports:
     *  - Returning active account if already present (unless force_refresh is requested)
     *  - Idempotent provisioning / recovery via VirtualAccountService & KatPayService
     *  - Syncing users and virtual_accounts tables
     *  - Audit logging
     */
    public function retrieveVirtualAccount(int $id): void
    {
        AdminMiddleware::requireRole('support'); // support, admin, or super_admin
        $adminId = AdminMiddleware::getAdminId();

        $data  = $this->getJsonInput();
        $force = !empty($data['force_refresh']);

        try {
            // Check user exists
            $stmt = $this->db->prepare("SELECT id, business_name, email, phone FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                Response::notFound('User not found');
                return;
            }

            $vaService = new \Services\VirtualAccountService($this->db);
            $existing  = $vaService->getByUserId($id);

            // If account is already active and non-empty and not forced, return immediately
            if (!$force && $existing && $existing['status'] === 'active' && !empty($existing['account_number'])) {
                Response::success([
                    'user_id'        => $id,
                    'account_number' => $existing['account_number'],
                    'account_name'   => $existing['account_name'],
                    'bank_name'      => $existing['bank_name'],
                    'bank_code'      => $existing['bank_code'] ?? null,
                    'status'         => 'active',
                    'is_new'         => false,
                    'message'        => 'Virtual account already active'
                ], 'Virtual account retrieved successfully');
                return;
            }

            // Provision or re-query gateway via VirtualAccountService
            $userInfo = [
                'business_name' => $user['business_name'],
                'email'         => $user['email'],
                'phone'         => $user['phone'],
            ];

            try {
                $va = $vaService->createForUser($id, $userInfo);
            } catch (\Exception $e) {
                // If create fails, attempt lookup by email directly
                try {
                    $katpay = new \Services\KatPayService();
                    $recovered = $katpay->getVirtualAccountByEmail($user['email']);
                    if (!empty($recovered['account_number'])) {
                        // Upsert recovered account
                        $upStmt = $this->db->prepare("
                            INSERT INTO virtual_accounts (user_id, katpay_va_id, account_number, account_name, bank_name, bank_code, status, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
                            ON DUPLICATE KEY UPDATE
                                katpay_va_id   = VALUES(katpay_va_id),
                                account_number = VALUES(account_number),
                                account_name   = VALUES(account_name),
                                bank_name      = VALUES(bank_name),
                                bank_code      = VALUES(bank_code),
                                status         = 'active',
                                updated_at     = NOW()
                        ");
                        $upStmt->execute([
                            $id,
                            $recovered['id'] ?? ($recovered['uuid'] ?? null),
                            $recovered['account_number'],
                            $recovered['account_name'] ?? $user['business_name'],
                            $recovered['bank_name'] ?? 'PalmPay',
                            $recovered['bank_code'] ?? null,
                        ]);
                        $va = $vaService->getByUserId($id);
                    } else {
                        throw $e;
                    }
                } catch (\Exception $recoverEx) {
                    Response::error('Gateway account retrieval failed: ' . $e->getMessage(), [], 422);
                    return;
                }
            }

            if (empty($va['account_number'])) {
                Response::error('Gateway did not return an account number. Status is pending.', ['status' => $va['status'] ?? 'pending'], 422);
                return;
            }

            // Dual-sync users table
            try {
                $this->db->prepare("
                    UPDATE users 
                    SET account_number = ?, account_name = ?, updated_at = NOW() 
                    WHERE id = ?
                ")->execute([
                    $va['account_number'],
                    $va['account_name'] ?? $user['business_name'],
                    $id
                ]);
            } catch (\Exception $e) { /* non-fatal */ }

            // Write audit log
            try {
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $auditNotes = "Admin #{$adminId} retrieved/provisioned virtual account for User #{$id} ({$user['email']}): {$va['bank_name']} - {$va['account_number']}.";
                $this->db->prepare("
                    INSERT INTO audit_logs (actor_type, actor_id, action, old_value, new_value, notes, ip_address, created_at)
                    VALUES ('admin', ?, 'VIRTUAL_ACCOUNT_RETRIEVED', ?, ?, ?, ?, NOW())
                ")->execute([
                    $adminId,
                    json_encode(['status' => $existing['status'] ?? 'none']),
                    json_encode(['account_number' => $va['account_number'], 'bank_name' => $va['bank_name']]),
                    $auditNotes,
                    $ip
                ]);
            } catch (\Exception $e) { /* non-fatal */ }

            Response::success([
                'user_id'        => $id,
                'account_number' => $va['account_number'],
                'account_name'   => $va['account_name'] ?? $user['business_name'],
                'bank_name'      => $va['bank_name'],
                'bank_code'      => $va['bank_code'] ?? null,
                'status'         => 'active',
                'is_new'         => true,
            ], 'Virtual account retrieved and synced successfully');

        } catch (\Exception $e) {
            Response::error('Failed to retrieve virtual account: ' . $e->getMessage(), [], 500);
        }
    }
}
