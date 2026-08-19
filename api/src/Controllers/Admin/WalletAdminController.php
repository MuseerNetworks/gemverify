<?php
namespace Controllers\Admin;

use Helpers\Response;
use Middleware\AdminMiddleware;
use Services\KatPayService;
use Services\WalletService;
use PDO;
use RuntimeException;

require_once __DIR__ . "/../../../config/database.php";

/**
 * WalletAdminController
 *
 * Admin-facing endpoints for viewing and managing wallet top-up orders.
 * Manual credit is restricted to super_admin role only.
 */
class WalletAdminController {

    // ──────────────────────────────────────────────────────────────────────────
    // GET /admin/wallet/topups
    // List all top-up orders with optional filters
    // ──────────────────────────────────────────────────────────────────────────
    public function listTopUps(): void {
        $db     = db();
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = 25;
        $offset = ($page - 1) * $limit;

        $status = $_GET['status'] ?? '';
        $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;

        $where  = [];
        $params = [];

        if ($status && in_array($status, ['pending','processing','completed','partial','expired','cancelled','failed'], true)) {
            $where[]  = 'wt.status = ?';
            $params[] = $status;
        }
        if ($userId) {
            $where[]  = 'wt.user_id = ?';
            $params[] = $userId;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count
        $cStmt = $db->prepare("SELECT COUNT(*) FROM wallet_topups wt $whereClause");
        $cStmt->execute($params);
        $total = (int) $cStmt->fetchColumn();

        // Fetch with user join
        $stmt = $db->prepare("
            SELECT
              wt.id,
              wt.merchant_reference,
              wt.katpay_uuid,
              wt.user_id,
              u.full_name        AS user_name,
              u.email            AS user_email,
              wt.amount,
              wt.amount_received,
              wt.currency,
              wt.status,
              wt.credited_tx_id,
              wt.admin_note,
              wt.expires_at,
              wt.completed_at,
              wt.created_at,
              wt.updated_at
            FROM wallet_topups wt
            LEFT JOIN users u ON u.id = wt.user_id
            $whereClause
            ORDER BY wt.created_at DESC
            LIMIT :lim OFFSET :off
        ");

        // Bind named params separately since we have positional + named mix
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val);
        }
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['amount']          = (float) $row['amount'];
            $row['amount_received'] = $row['amount_received'] !== null ? (float) $row['amount_received'] : null;
        }
        unset($row);

        Response::success([
            'data'       => $rows,
            'pagination' => [
                'current_page' => $page,
                'per_page'     => $limit,
                'total'        => $total,
                'total_pages'  => (int) ceil($total / $limit),
            ],
            'summary'    => $this->getSummary($db),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /admin/wallet/topups/{ref}
    // Full detail of one top-up order
    // ──────────────────────────────────────────────────────────────────────────
    public function getTopUp(string $ref): void {
        $db   = db();
        $stmt = $db->prepare("
            SELECT
              wt.*,
              u.full_name  AS user_name,
              u.email      AS user_email,
              u.phone      AS user_phone
            FROM wallet_topups wt
            LEFT JOIN users u ON u.id = wt.user_id
            WHERE wt.merchant_reference = ?
        ");
        $stmt->execute([$ref]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            Response::error('Top-up order not found', 404);
            return;
        }

        $row['amount']           = (float) $row['amount'];
        $row['amount_received']  = $row['amount_received'] !== null ? (float) $row['amount_received'] : null;
        $row['payment_account']  = $row['payment_account']  ? json_decode($row['payment_account'],  true) : null;
        $row['callback_payload'] = $row['callback_payload'] ? json_decode($row['callback_payload'], true) : null;

        Response::success($row);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /admin/wallet/topups/{ref}/credit
    // Super-admin manually credits a partial/stuck topup after verifying in KatPay dashboard
    // ──────────────────────────────────────────────────────────────────────────
    public function manualCredit(string $ref): void {
        AdminMiddleware::requireRole('super_admin');

        $db   = db();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // Verify the topup exists and is in a creditable state
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM wallet_topups WHERE merchant_reference = ? FOR UPDATE");
            $stmt->execute([$ref]);
            $topup = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$topup) {
                $db->rollBack();
                Response::error('Top-up not found', 404);
                return;
            }

            if (!empty($topup['credited_tx_id'])) {
                $db->rollBack();
                Response::error('This top-up has already been credited (tx_id: ' . $topup['credited_tx_id'] . ')', 409);
                return;
            }

            if (!in_array($topup['status'], ['partial', 'pending', 'processing', 'failed'], true)) {
                $db->rollBack();
                Response::error('Cannot manually credit a top-up with status: ' . $topup['status'], 422);
                return;
            }

            // Admin-specified credit amount (defaults to amount_received, falls back to amount)
            $creditAmount = isset($body['credit_amount'])
                ? (float) $body['credit_amount']
                : ((float) ($topup['amount_received'] ?? $topup['amount']));

            if ($creditAmount <= 0) {
                $db->rollBack();
                Response::error('Credit amount must be greater than 0', 422);
                return;
            }

            $adminNote = 'Manual credit by admin. Amount: ₦' . $creditAmount
                . '. Note: ' . ($body['note'] ?? 'No note provided.');

            // Independently verify with KatPay before crediting (unless admin explicitly overrides)
            if (empty($body['skip_katpay_verify'])) {
                try {
                    $katpay       = new KatPayService();
                    $verifiedData = $katpay->verifyByMerchantRef($ref);
                    $verifiedStatus = $verifiedData['status'] ?? '';

                    if ($verifiedStatus !== 'completed') {
                        $db->rollBack();
                        Response::error(
                            'KatPay still reports this payment as "' . $verifiedStatus . '". '
                            . 'Add skip_katpay_verify=true to override (super_admin only).',
                            422
                        );
                        return;
                    }
                } catch (RuntimeException $e) {
                    // If KatPay verify fails and admin wants to force credit, allow with override
                    if (empty($body['skip_katpay_verify'])) {
                        $db->rollBack();
                        Response::error(
                            'KatPay verification API error: ' . $e->getMessage()
                            . '. Add skip_katpay_verify=true to force credit.',
                            502
                        );
                        return;
                    }
                    $adminNote .= ' [KatPay verify skipped: ' . $e->getMessage() . ']';
                }
            } else {
                $adminNote .= ' [KatPay verify BYPASSED by admin]';
            }

            // Credit the wallet
            $walletService = new WalletService($db);
            $tx = $walletService->creditAtomically(
                (int) $topup['user_id'],
                $creditAmount,
                'Wallet Top-up via KatPay — Admin Credit (Ref: ' . $ref . ')',
                null
            );

            // Update topup record
            $db->prepare("
                UPDATE wallet_topups SET
                  status = 'completed',
                  amount_received = COALESCE(amount_received, ?),
                  credited_tx_id = ?,
                  admin_note = ?,
                  completed_at = NOW(),
                  updated_at = NOW()
                WHERE id = ?
            ")->execute([$creditAmount, $tx['id'], $adminNote, $topup['id']]);

            $db->commit();

            Response::success([
                'message'         => 'Wallet credited successfully',
                'credited_amount' => $creditAmount,
                'transaction_ref' => $tx['reference'],
            ]);

        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('Credit failed: ' . $e->getMessage(), 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PATCH /admin/users/{id}/virtual-account
    // Super-admin manually sets account details for a user stuck in 'pending'.
    // Use this when the user was deleted+re-registered and KatPay can't auto-
    // provision a new account because the customer already exists on their side.
    // Paste the account_number from the KatPay merchant dashboard.
    // ──────────────────────────────────────────────────────────────────────────
    public function manualResolveVirtualAccount(int $userId): void {
        AdminMiddleware::requireRole('super_admin');

        $db   = db();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $accountNumber = trim($body['account_number'] ?? '');
        $accountName   = trim($body['account_name']   ?? '');
        $bankName      = trim($body['bank_name']       ?? '');
        $bankCode      = trim($body['bank_code']       ?? '');

        if (!$accountNumber) {
            Response::error('account_number is required', 422);
            return;
        }

        // Verify user exists
        $uStmt = $db->prepare("SELECT id, business_name, email FROM users WHERE id = ?");
        $uStmt->execute([$userId]);
        $user = $uStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            Response::error('User not found', 404);
            return;
        }

        // Upsert the virtual_accounts row, marking it active
        $db->prepare("
            INSERT INTO virtual_accounts
              (user_id, katpay_va_id, account_number, account_name, bank_name, bank_code, currency, status, raw_response, created_at, updated_at)
            VALUES
              (?, NULL, ?, ?, ?, ?, 'NGN', 'active', ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
              account_number = VALUES(account_number),
              account_name   = VALUES(account_name),
              bank_name      = VALUES(bank_name),
              bank_code      = VALUES(bank_code),
              status         = 'active',
              raw_response   = VALUES(raw_response),
              updated_at     = NOW()
        ")->execute([
            $userId,
            $accountNumber,
            $accountName  ?: ($user['business_name'] . ' / GemVerify'),
            $bankName     ?: 'Manual',
            $bankCode     ?: '',
            json_encode(['source' => 'admin_manual', 'set_by' => 'super_admin', 'at' => date('c')]),
        ]);

        error_log('[Admin] Virtual account manually resolved for user #' . $userId . ' (' . $user['email'] . ') — acct: ' . $accountNumber);

        Response::success([
            'message'        => 'Virtual account set successfully.',
            'user_id'        => $userId,
            'account_number' => $accountNumber,
            'account_name'   => $accountName,
            'bank_name'      => $bankName,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE: Summary counts for dashboard cards
    // ──────────────────────────────────────────────────────────────────────────
    private function getSummary(object $db): array {
        $stmt = $db->query("
            SELECT
              status,
              COUNT(*) AS count,
              COALESCE(SUM(amount), 0) AS total_amount
            FROM wallet_topups
            GROUP BY status
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [];
        foreach ($rows as $row) {
            $summary[$row['status']] = [
                'count'        => (int) $row['count'],
                'total_amount' => (float) $row['total_amount'],
            ];
        }
        return $summary;
    }
}
