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
                Response::error('Minimum withdrawal amount is ₦100.', 422);
                return;
            }
            if (!$bankCode || !$accountNumber || !$accountName) {
                Response::error('bank_code, account_number, and account_name are required.', 422);
                return;
            }

            // Generate unique reference
            $ref = 'WD_' . date('YmdHis') . '_' . rand(1000, 9999);

            // Log pending withdrawal
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

                $katpayRef = $payoutResult['reference'] ?? $payoutResult['id'] ?? $ref;

                // Update to completed
                $uStmt = $this->db->prepare("
                    UPDATE admin_withdrawals SET
                      status = 'completed',
                      katpay_reference = ?,
                      response_payload = ?
                    WHERE id = ?
                ");
                $uStmt->execute([
                    $katpayRef,
                    json_encode($payoutResult),
                    $withdrawalId
                ]);

                $this->audit->log(
                    'admin',
                    (string)$adminId,
                    'PROFIT_WITHDRAWAL',
                    "Withdrew ₦{$amount} to {$accountName} ({$bankName} - {$accountNumber}). Ref: {$ref}"
                );

                Response::success([
                    'message'          => 'Withdrawal executed successfully via KatPay Payouts API.',
                    'reference'        => $ref,
                    'katpay_reference' => $katpayRef,
                    'amount'           => $amount,
                    'account_number'   => $accountNumber,
                    'bank_name'        => $bankName
                ]);

            } catch (\Throwable $payoutErr) {
                // Mark as failed
                $fStmt = $this->db->prepare("
                    UPDATE admin_withdrawals SET
                      status = 'failed',
                      response_payload = ?
                    WHERE id = ?
                ");
                $fStmt->execute([
                    json_encode(['error' => $payoutErr->getMessage()]),
                    $withdrawalId
                ]);

                Response::error('KatPay Payout Failed: ' . $payoutErr->getMessage(), 502);
            }

        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }
}
