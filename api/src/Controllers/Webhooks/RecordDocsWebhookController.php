<?php
declare(strict_types=1);

namespace Controllers\Webhooks;

use Helpers\Response;
use Services\WalletService;
use Services\AuditService;
use PDO;
use Throwable;

/**
 * GemVerify — RecordDocs Real-Time Webhook Controller
 *
 * Receives asynchronous callback events from https://api-service.recorddocs.net/api/v1
 * for IPE, Modification IPE, Validation, and BVN Retrieval.
 *
 * Headers:
 *   x-rd-signature: HMAC-SHA256 of timestamp + '.' + body
 *   x-rd-timestamp: Unix timestamp
 */
class RecordDocsWebhookController
{
    private PDO $db;
    private WalletService $walletService;
    private AuditService $auditService;

    public function __construct(?PDO $db = null)
    {
        $this->db            = $db ?: \db();
        $this->walletService = new WalletService($this->db);
        $this->auditService  = new AuditService($this->db);
    }

    public function handle(): void
    {
        $rawBody   = file_get_contents('php://input') ?: '';
        $headers   = getallheaders();
        $signature = $headers['x-rd-signature'] ?? $headers['X-Rd-Signature'] ?? $headers['X-RD-SIGNATURE'] ?? null;
        $timestamp = $headers['x-rd-timestamp'] ?? $headers['X-Rd-Timestamp'] ?? $headers['X-RD-TIMESTAMP'] ?? null;

        $secret = defined('RECORDDOCS_WEBHOOK_SECRET') ? trim((string)RECORDDOCS_WEBHOOK_SECRET) : '';

        // Validate signature if secret is configured
        if (!empty($secret)) {
            if (empty($signature) || empty($timestamp)) {
                Response::error('Missing webhook signature or timestamp headers', [], 400);
                return;
            }

            $payloadToSign = $timestamp . '.' . $rawBody;
            $expectedSig   = hash_hmac('sha256', $payloadToSign, $secret);

            if (!hash_equals($expectedSig, (string)$signature)) {
                Response::error('Invalid webhook signature', [], 401);
                return;
            }
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            Response::error('Invalid JSON payload', [], 400);
            return;
        }

        $event = $payload['event'] ?? '';
        $data  = $payload['data'] ?? [];

        if ($event !== 'job.updated' || empty($data['reference'])) {
            Response::success(['acknowledged' => true, 'message' => 'Event ignored']);
            return;
        }

        $ticketRef = trim((string)$data['reference']);
        $rawStatus = strtoupper(trim((string)($data['status'] ?? '')));
        $docLink   = $data['docLink'] ?? null;
        $remark    = $data['remark'] ?? null;

        // Locate transaction by provider_ticket_id or provider_txn_id
        $stmt = $this->db->prepare("
            SELECT
                at.*,
                s.name AS service_name,
                s.slug AS service_slug,
                COALESCE(sp.price, t.amount, 0) AS price_paid
            FROM api_transactions at
            JOIN services s ON s.id = at.service_id
            LEFT JOIN service_pricing sp ON sp.id = at.pricing_id
            LEFT JOIN transactions t ON t.id = at.transaction_id
            WHERE at.provider_ticket_id = ? OR at.provider_txn_id = ?
            LIMIT 1
        ");
        $stmt->execute([$ticketRef, $ticketRef]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tx) {
            Response::success(['acknowledged' => true, 'warning' => 'Transaction reference not found: ' . $ticketRef]);
            return;
        }

        $id          = (int)$tx['id'];
        $userId      = (int)$tx['user_id'];
        $pricePaid   = (float)$tx['price_paid'];
        $gvRef       = $tx['gv_reference'];
        $serviceName = $tx['service_name'];

        // If already completed or refunded, return 200 (idempotent)
        if ($tx['gv_status'] === 'completed' && !empty($tx['result_data'])) {
            Response::success(['acknowledged' => true, 'message' => 'Already completed']);
            return;
        }
        if ($tx['gv_status'] === 'refunded' && !empty($tx['refund_issued'])) {
            Response::success(['acknowledged' => true, 'message' => 'Already refunded']);
            return;
        }

        if ($rawStatus === 'SUCCESSFUL') {
            $pdfBase64 = null;
            if (!empty($docLink) && filter_var($docLink, FILTER_VALIDATE_URL)) {
                try {
                    $content = @file_get_contents($docLink);
                    if ($content !== false && strlen($content) > 200) {
                        $pdfBase64 = base64_encode($content);
                        $data['pdf_base64'] = $pdfBase64;
                    }
                } catch (Throwable $e) {}
            }

            $resType = $pdfBase64 ? 'pdf_base64' : ($tx['result_type'] ?: 'ticket');

            $this->db->prepare("
                UPDATE api_transactions
                SET gv_status = 'completed',
                    provider_status = 'completed',
                    provider_financial_status = 'charged',
                    result_type = ?,
                    result_data = ?,
                    synced_at = NOW(),
                    completed_at = COALESCE(completed_at, NOW()),
                    reconciliation_notes = 'Completed via RecordDocs webhook'
                WHERE id = ?
            ")->execute([
                $resType,
                json_encode($data),
                $id
            ]);

            $this->auditService->log(
                'API_WEBHOOK_COMPLETED',
                $id,
                'system',
                null,
                ['status' => $tx['gv_status']],
                ['status' => 'completed', 'docLink' => $docLink],
                "RecordDocs webhook completed job {$ticketRef}"
            );

            Response::success(['acknowledged' => true, 'action' => 'completed']);
            return;

        } elseif ($rawStatus === 'FAILED_REFUND') {
            // Provider confirmed failure and returned balance to our wallet -> safe to auto-refund user
            if (empty($tx['refund_issued']) && $pricePaid > 0) {
                $desc = "Refund: {$serviceName} — Ref: {$gvRef}";
                $this->walletService->creditAtomically($userId, $pricePaid, $desc, $id);
            }

            $this->db->prepare("
                UPDATE api_transactions
                SET gv_status = 'refunded',
                    provider_status = 'failed',
                    provider_financial_status = 'reversed',
                    refund_issued = 1,
                    refund_amount = ?,
                    completed_at = COALESCE(completed_at, NOW()),
                    synced_at = NOW(),
                    reconciliation_notes = ?
                WHERE id = ?
            ")->execute([
                $pricePaid,
                'Auto-refunded via RecordDocs webhook (FAILED_REFUND): ' . ($remark ?: 'Provider failed'),
                $id
            ]);

            $this->auditService->log(
                'API_WEBHOOK_REFUND',
                $id,
                'system',
                null,
                ['status' => $tx['gv_status']],
                ['status' => 'refunded', 'amount' => $pricePaid],
                "RecordDocs webhook FAILED_REFUND for job {$ticketRef}"
            );

            Response::success(['acknowledged' => true, 'action' => 'refunded']);
            return;

        } elseif ($rawStatus === 'FAILED') {
            $this->db->prepare("
                UPDATE api_transactions
                SET gv_status = 'failed',
                    provider_status = 'failed',
                    error_message = ?,
                    synced_at = NOW(),
                    reconciliation_notes = 'Failed via RecordDocs webhook'
                WHERE id = ?
            ")->execute([
                $remark ?: 'Provider processing failed',
                $id
            ]);

            Response::success(['acknowledged' => true, 'action' => 'marked_failed']);
            return;
        }

        Response::success(['acknowledged' => true, 'status' => $rawStatus]);
    }
}
