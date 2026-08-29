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
            Response::error('Failed to fetch bank list: ' . $e->getMessage(), 500);
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
            Response::error('Failed to list withdrawals: ' . $e->getMessage(), 500);
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

            $amount        = (float) ($body['amount'] ?? 0);
            $bankCode      = trim($body['bank_code'] ?? '');
            $bankName      = trim($body['bank_name'] ?? '');
            $accountNumber = trim($body['account_number'] ?? '');
            $accountName   = trim($body['account_name'] ?? '');
            $description   = trim($body['description'] ?? 'Company Profit Withdrawal');

            if ($amount < 100) {
                Response::error('Minimum withdrawal amount is ₦100.00.', 422);
                return;
            }
            if (!$bankCode || !$accountNumber || !$accountName) {
                Response::error('Destination bank, account number, and account holder name are required.', 422);
                return;
            }

            // ── Atomic Server-Side Profit Balance Check ────────────────────────
            // Calculate live withdrawable company earnings (settled customer debits)
            $totalRevenue = (float)$this->db
                ->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'debit' AND status = 'completed'")
                ->fetchColumn();

            $completedWithdrawals = (float)$this->db
                ->query("SELECT COALESCE(SUM(amount), 0) FROM admin_withdrawals WHERE status IN ('completed', 'pending')")
                ->fetchColumn();

            $withdrawableProfit = max(0, ($totalRevenue * 0.25) - $completedWithdrawals);

            if ($amount > $withdrawableProfit) {
                Response::error(
                    'Withdrawal request of ₦' . number_format($amount, 2) . ' exceeds available withdrawable profit (₦' . number_format($withdrawableProfit, 2) . ').',
                    422
                );
                return;
            }

            // Generate unique reference
            $ref = 'WD_' . date('YmdHis') . '_' . rand(1000, 9999);

            // Log pending withdrawal in database
            $stmt = $this->db->prepare("
                INSERT INTO admin_withdrawals
                  (reference, admin_id, amount, bank_code, bank_name, account_number, account_name, description, status, created_at)
                VALUES
                  (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $ref,
                $adminId,
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

                // Accurate financial status mapping
                $finalStatus = 'completed';
                if (in_array($rawStatus, ['pending', 'processing', 'queued'], true)) {
                    $finalStatus = 'pending';
                } elseif (in_array($rawStatus, ['failed', 'rejected'], true)) {
                    $finalStatus = 'failed';
                }

                // Update database
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

                $this->audit->log(
                    'admin',
                    (string)$adminId,
                    'PROFIT_WITHDRAWAL',
                    "Withdrew ₦" . number_format($amount, 2) . " to {$accountName} ({$bankName} - {$accountNumber}). Ref: {$ref} [Status: {$finalStatus}]"
                );

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
                // Mark as failed
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

                Response::error($errMsg, 400);
            }

        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }
}
