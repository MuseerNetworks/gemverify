<?php
namespace Controllers\Webhooks;

use Helpers\Response;
use Services\WalletService;
use Services\AuditService;
use PDO;

/**
 * GemVerify — S8V Real-Time Webhook Controller
 *
 * Receives asynchronous callback events from https://www.s8v.ng
 * for Personalization, IPE Clearance, and Validation.
 *
 * Endpoint: POST /api/webhooks/s8v?secret=...
 */
class S8VWebhookController
{
    private PDO $db;
    private WalletService $walletService;
    private AuditService $auditService;

    public function __construct()
    {
        $this->db            = db();
        $this->walletService = new WalletService($this->db);
        $this->auditService  = new AuditService($this->db);
    }

    /**
     * Handle incoming webhook payload from S8V.
     */
    public function handle(): void
    {
        // 1. Verify Secret Token
        $expectedSecret = defined('S8V_WEBHOOK_SECRET') ? S8V_WEBHOOK_SECRET : '';
        $providedSecret = $_GET['secret'] ?? $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';

        if (empty($expectedSecret) || !hash_equals($expectedSecret, (string)$providedSecret)) {
            Response::error('Unauthorized webhook request.', [], 401);
            return;
        }

        $rawBody = file_get_contents('php://input');
        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            Response::error('Invalid JSON payload.', [], 400);
            return;
        }

        $trackingId = strtoupper(trim((string)($payload['tracking_id'] ?? '')));
        $nin        = trim((string)($payload['nin'] ?? ''));
        $ticketId   = trim((string)($payload['id'] ?? $payload['ticket_id'] ?? ''));
        $status     = strtolower(trim((string)($payload['status'] ?? '')));

        \Helpers\SchemaHelper::ensureProviderColumns($this->db);
        $cols = $this->db->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
        $penaltyCol = in_array('failure_penalty_fee', $cols, true) ? 's.failure_penalty_fee' : '0.00 AS failure_penalty_fee';

        // 2. Locate matching pending/processing api_transaction
        $tx = null;
        if (!empty($ticketId)) {
            $stmt = $this->db->prepare("
                SELECT at.*, s.name AS service_name, {$penaltyCol}, sp.price AS price_paid
                FROM api_transactions at
                JOIN services s ON s.id = at.service_id
                JOIN service_pricing sp ON sp.id = at.pricing_id
                WHERE at.provider_ticket_id = ?
                LIMIT 1
            ");
            $stmt->execute([$ticketId]);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$tx && !empty($trackingId)) {
            $stmt = $this->db->prepare("
                SELECT at.*, s.name AS service_name, {$penaltyCol}, sp.price AS price_paid
                FROM api_transactions at
                JOIN services s ON s.id = at.service_id
                JOIN service_pricing sp ON sp.id = at.pricing_id
                WHERE at.input_summary LIKE ?
                ORDER BY at.id DESC
                LIMIT 1
            ");
            $stmt->execute(['%' . $trackingId . '%']);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$tx && !empty($nin)) {
            $stmt = $this->db->prepare("
                SELECT at.*, s.name AS service_name, {$penaltyCol}, sp.price AS price_paid
                FROM api_transactions at
                JOIN services s ON s.id = at.service_id
                JOIN service_pricing sp ON sp.id = at.pricing_id
                WHERE at.input_summary LIKE ?
                ORDER BY at.id DESC
                LIMIT 1
            ");
            $stmt->execute(['%' . $nin . '%']);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$tx) {
            // Acknowledge receipt even if not found to prevent webhook retry storms
            Response::success(['received' => true, 'matched' => false, 'message' => 'No active transaction matched.']);
            return;
        }

        // 3. Idempotency check: if already terminal state, return immediately
        if (in_array($tx['gv_status'], ['completed', 'failed', 'refunded'], true)) {
            Response::success(['received' => true, 'already_processed' => true, 'status' => $tx['gv_status']]);
            return;
        }

        $txId = (int)$tx['id'];

        // 4. Handle State Transitions
        if ($status === 'successful' || $status === 'completed') {
            $resultData = !empty($payload['data']) ? json_encode($payload['data']) : json_encode($payload);

            $this->db->prepare("
                UPDATE api_transactions
                SET gv_status = 'completed',
                    provider_status = 'completed',
                    result_data = ?,
                    completed_at = NOW()
                WHERE id = ?
            ")->execute([$resultData, $txId]);

            $this->auditService->log(
                'S8V_WEBHOOK_COMPLETED',
                $txId,
                'webhook',
                null,
                null,
                ['status' => 'completed'],
                "S8V webhook delivered successful completion for {$tx['gv_reference']}"
            );

        } elseif ($status === 'failed') {
            // Apply failure processing fee and partial refund
            $pricePaid  = (float)($tx['price_paid'] ?? 0.00);
            $penaltyFee = (float)($tx['failure_penalty_fee'] ?? 0.00);
            $refundAmount = max(0.00, $pricePaid - $penaltyFee);

            if ((int)($tx['refund_issued'] ?? 0) === 0 && $refundAmount > 0) {
                $serviceName = $tx['service_name'] ?? 'Service';
                $penaltyText = $penaltyFee > 0 ? " (₦" . number_format($refundAmount, 2) . " refunded, ₦" . number_format($penaltyFee, 2) . " processing fee applied)" : "";
                $desc = "Refund: {$serviceName}{$penaltyText} — Ref: {$tx['gv_reference']}";

                $this->walletService->creditAtomically(
                    (int)$tx['user_id'],
                    $refundAmount,
                    $desc,
                    $txId
                );
            }

            $userReason = $payload['message'] ?? 'No record found on identity registry for this request.';
            $feeNotice  = $penaltyFee > 0 ? " ₦" . number_format($penaltyFee, 2) . " processing fee applied. ₦" . number_format($refundAmount, 2) . " returned to wallet." : " Full fee refunded to wallet.";

            $txCols = $this->db->query("SHOW COLUMNS FROM api_transactions")->fetchAll(PDO::FETCH_COLUMN);
            $hasPenaltyCol = in_array('penalty_deducted', $txCols, true);
            $hasRefundCol  = in_array('refund_amount', $txCols, true);

            $updateSets = [
                "gv_status        = 'failed'",
                "provider_status  = 'failed'",
                "refund_issued    = 1",
            ];
            $params = [];

            if ($hasPenaltyCol) {
                $updateSets[] = "penalty_deducted = ?";
                $params[] = $penaltyFee;
            }
            if ($hasRefundCol) {
                $updateSets[] = "refund_amount = ?";
                $params[] = $refundAmount;
            }

            $updateSets[] = "error_message    = ?";
            $params[] = $userReason . $feeNotice;

            $updateSets[] = "completed_at     = NOW()";

            $params[] = $txId;

            $sql = "UPDATE api_transactions SET " . implode(", ", $updateSets) . " WHERE id = ?";
            $this->db->prepare($sql)->execute($params);

            $this->auditService->log(
                'S8V_WEBHOOK_FAILED',
                $txId,
                'webhook',
                null,
                null,
                ['status' => 'failed', 'refund' => $refundAmount, 'penalty' => $penaltyFee],
                "S8V webhook delivered failure for {$tx['gv_reference']}"
            );
        }

        Response::success([
            'received'     => true,
            'gv_reference' => $tx['gv_reference'],
            'status'       => ($status === 'successful' ? 'completed' : 'failed')
        ]);
    }
}