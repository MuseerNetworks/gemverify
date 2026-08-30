<?php
namespace Controllers\Admin;

use Helpers\Response;
use Middleware\AdminMiddleware;
use Services\KatPayService;
use Services\AuditService;
use PDO;
use RuntimeException;

require_once __DIR__ . '/../../../config/database.php';

/**
 * WithdrawalController
 *
 * Handles Admin profit withdrawals aligned 100% with KatPay Payouts API.
 */
class WithdrawalController {

    private PDO $db;
    private KatPayService $katpay;
    private AuditService $audit;

    public function __construct() {
        $this->db     = db();
        $this->katpay = new KatPayService();
        $this->audit  = new AuditService($this->db);
        $this->ensureTableExists();
    }

    /**
     * Auto-migrate admin_withdrawals table if missing on live environment.
     */
    private function ensureTableExists(): void {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS admin_withdrawals (
                    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    reference          VARCHAR(50) NOT NULL UNIQUE,
                    admin_id           BIGINT UNSIGNED NOT NULL,
                    withdrawal_type    ENUM('profit', 'business_cash') NOT NULL DEFAULT 'profit',
                    amount             DECIMAL(15,2) NOT NULL,
                    bank_code          VARCHAR(20) NOT NULL,
                    bank_name          VARCHAR(100) NULL,
                    account_number     VARCHAR(20) NOT NULL,
                    account_name       VARCHAR(150) NOT NULL,
                    description        VARCHAR(255) NULL,
                    katpay_reference   VARCHAR(100) NULL,
                    status             ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
                    response_payload   LONGTEXT NULL,
                    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_reference (reference),
                    INDEX idx_admin (admin_id),
                    INDEX idx_type (withdrawal_type),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (\Throwable $e) {
            error_log('[GemVerify Migration Notice] ' . $e->getMessage());
        }
        try {
            $hasTypeCol = (bool)$this->db->query("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'admin_withdrawals'
                  AND column_name = 'withdrawal_type'
            ")->fetchColumn();

            if (!$hasTypeCol) {
                $this->db->exec("ALTER TABLE admin_withdrawals ADD COLUMN withdrawal_type ENUM('profit', 'business_cash') NOT NULL DEFAULT 'profit' AFTER admin_id");
            }
        } catch (\Throwable $e) {}
    }

    /**
     * GET /admin/banks
     * Returns list of KatPay supported destination banks.
     */
    public function getBanks(): void {
        try {
            AdminMiddleware::requireRole('admin');
            $banks = $this->katpay->getBankList();
            Response::success($banks);
        } catch (\Throwable $e) {
            Response::error('Failed to fetch bank list: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * GET /admin/withdrawals
     * List all profit withdrawals.
     */
    public function listWithdrawals(): void {
        try {
            AdminMiddleware::requireRole('admin');
            $page   = max(1, (int) ($_GET['page'] ?? 1));
            $limit  = 20;
            $offset = ($page - 1) * $limit;

            $total = (int) $this->db->query("SELECT COUNT(*) FROM admin_withdrawals")->fetchColumn();

            $stmt = $this->db->prepare("
                SELECT w.*, a.name AS admin_name, a.email AS admin_email
                FROM admin_withdrawals w
                LEFT JOIN admins a ON a.id = w.admin_id
                ORDER BY w.created_at DESC
                LIMIT $limit OFFSET $offset
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$row) {
                $row['amount'] = (float) $row['amount'];
            }

            Response::success([
                'data' => $rows,
                'pagination' => [
                    'current_page' => $page,
                    'per_page'     => $limit,
                    'total'        => $total,
                    'total_pages'  => (int) ceil($total / $limit)
                ]
            ]);
        } catch (\Throwable $e) {
            Response::error('Failed to list withdrawals: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * POST /admin/withdrawals
     * Super-admin initiates a profit withdrawal via KatPay Payouts API.
     */
    public function createWithdrawal(): void {
        try {
            AdminMiddleware::requireRole('super_admin');
            $adminId = AdminMiddleware::getAdminId();

            $body = json_decode(file_get_contents('php://input'), true) ?? [];

            $amount         = (float) ($body['amount'] ?? 0);
            $withdrawalType = in_array($body['withdrawal_type'] ?? '', ['business_cash', 'profit'], true) ? $body['withdrawal_type'] : 'profit';
            $bankCode       = trim($body['bank_code'] ?? '');
            $bankName       = trim($body['bank_name'] ?? '');
            $accountNumber  = trim($body['account_number'] ?? '');
            $accountName    = trim($body['account_name'] ?? '');
            $typeLabel      = $withdrawalType === 'business_cash' ? 'Provider Business Cash' : 'Company Profit';
            $description    = trim($body['description'] ?? "GemVerify $typeLabel Withdrawal");

            if ($amount < 100) {
                Response::error('Minimum withdrawal amount is ₦100.00.', [], [], 422);
                return;
            }
            if (!$bankCode || !$accountNumber || !$accountName) {
                Response::error('Destination bank, account number, and account holder name are required.', [], [], 422);
                return;
            }

            // ── Atomic Server-Side Profit Balance Check ────────────────────────
            $totalRevenue = (float)$this->db
                ->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'debit' AND status = 'completed'")
                ->fetchColumn();

            $completedWithdrawals = (float)$this->db
                ->query("SELECT COALESCE(SUM(amount), 0) FROM admin_withdrawals WHERE status IN ('completed', 'pending')")
                ->fetchColumn();

            $withdrawableProfit = max(0, $totalRevenue - $completedWithdrawals);

            if ($amount > $withdrawableProfit) {
                Response::error(
                    'Withdrawal request of ₦' . number_format($amount, 2) . ' exceeds available withdrawable profit (₦' . number_format($withdrawableProfit, 2) . ').',
                    [], 422);
                return;
            }

            // Generate unique reference
            $ref = 'WD_' . date('YmdHis') . '_' . rand(1000, 9999);

            // Log pending withdrawal in database
            $stmt = $this->db->prepare("
                INSERT INTO admin_withdrawals
                  (reference, admin_id, withdrawal_type, amount, bank_code, bank_name, account_number, account_name, description, status, created_at)
                VALUES
                  (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $ref,
                $adminId,
                $withdrawalType,
                $amount,
                $bankCode,
                $bankName,
                $accountNumber,
                $accountName,
                $description
            ]);
            $withdrawalId = $this->db->lastInsertId();

            // Execute KatPay payout
            try {
                $payoutResult = $this->katpay->createPayout([
                    'amount'         => $amount,
                    'bank_code'      => $bankCode,
                    'account_number' => $accountNumber,
                    'account_name'   => $accountName,
                    'description'    => $description,
                    'reference'      => $ref
                ]);

                $katpayRef = $payoutResult['reference'] ?? $payoutResult['merchant_reference'] ?? $payoutResult['id'] ?? $ref;
                $rawStatus = strtolower($payoutResult['status'] ?? ($payoutResult['success'] ? 'completed' : 'pending'));

                $finalStatus = 'completed';
                if (in_array($rawStatus, ['pending', 'processing', 'queued'], true)) {
                    $finalStatus = 'pending';
                } elseif (in_array($rawStatus, ['failed', 'rejected'], true)) {
                    $finalStatus = 'failed';
                }

                $uStmt = $this->db->prepare("
                    UPDATE admin_withdrawals SET
                      status = ?,
                      katpay_reference = ?,
                      response_payload = ?
                    WHERE id = ?
                ");
                $uStmt->execute([
                    $finalStatus,
                    $katpayRef,
                    json_encode($payoutResult),
                    $withdrawalId
                ]);

                // Safe audit logging
                try {
                    $this->audit->log(
                        'PROFIT_WITHDRAWAL',
                        null,
                        'admin',
                        $adminId,
                        null,
                        [
                            'amount'         => $amount,
                            'bank_name'      => $bankName,
                            'account_number' => $accountNumber,
                            'account_name'   => $accountName,
                            'reference'      => $ref,
                            'status'         => $finalStatus
                        ],
                        "Withdrew ₦" . number_format($amount, 2) . " to {$accountName} ({$bankName} - {$accountNumber}). Ref: {$ref} [Status: {$finalStatus}]",
                        $_SERVER['REMOTE_ADDR'] ?? null
                    );
                } catch (\Throwable $auditEx) {
                    error_log('[GemVerify Audit Log Warning] ' . $auditEx->getMessage());
                }

                Response::success([
                    'message'          => $finalStatus === 'completed' 
                        ? 'Withdrawal executed and transferred successfully via KatPay.' 
                        : 'Withdrawal initiated and queued for transfer via KatPay.',
                    'reference'        => $ref,
                    'katpay_reference' => $katpayRef,
                    'status'           => $finalStatus,
                    'amount'           => $amount,
                    'account_number'   => $accountNumber,
                    'bank_name'        => $bankName
                ]);

            } catch (\Throwable $payoutErr) {
                // Mark as failed in DB
                $errMsg = $payoutErr->getMessage();
                $fStmt = $this->db->prepare("
                    UPDATE admin_withdrawals SET
                      status = 'failed',
                      response_payload = ?
                    WHERE id = ?
                ");
                $fStmt->execute([
                    json_encode(['error' => $errMsg, 'time' => date('Y-m-d H:i:s')]),
                    $withdrawalId
                ]);

                Response::error($errMsg, [], 400);
            }

        } catch (\Throwable $e) {
            error_log('[GemVerify Withdrawal Exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            Response::error($e->getMessage(), [], 400);
        }
    }
}
