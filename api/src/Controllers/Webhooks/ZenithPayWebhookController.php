<?php
declare(strict_types=1);

namespace Controllers\Webhooks;

use PDO;

/**
 * ZenithPay webhook discovery receiver.
 *
 * This endpoint is deliberately logging-only. ZenithPay's public documentation
 * does not yet define its event schema, verification API, or settlement event
 * contract, so no incoming request can credit a GemVerify wallet at this stage.
 */
final class ZenithPayWebhookController
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?: \db();
    }

    public function handle(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        $remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!$this->isAllowedIp($remoteIp)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Webhook source is not allowed.']);
            return;
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || $rawBody === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Webhook body is required.']);
            return;
        }

        if (strlen($rawBody) > ZENITHPAY_WEBHOOK_MAX_BODY_BYTES) {
            http_response_code(413);
            echo json_encode(['success' => false, 'message' => 'Webhook body is too large.']);
            return;
        }

        $payloadHash = hash('sha256', $rawBody);
        $contentType = substr((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 0, 128);

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                'SELECT id, delivery_count FROM zenithpay_webhook_events WHERE payload_sha256 = ? FOR UPDATE'
            );
            $stmt->execute([$payloadHash]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $this->db->prepare(
                    'UPDATE zenithpay_webhook_events
                     SET delivery_count = delivery_count + 1, last_received_at = NOW()
                     WHERE id = ?'
                )->execute([(int) $existing['id']]);
                $this->db->commit();

                echo json_encode([
                    'success' => true,
                    'received' => true,
                    'duplicate' => true,
                    'message' => 'Webhook event already recorded.',
                ]);
                return;
            }

            $this->db->prepare(
                'INSERT INTO zenithpay_webhook_events
                    (remote_ip, content_type, payload_sha256, payload, processing_status, received_at, last_received_at)
                 VALUES (?, ?, ?, ?, \'received\', NOW(), NOW())'
            )->execute([$remoteIp, $contentType, $payloadHash, $rawBody]);

            $this->db->commit();
            echo json_encode([
                'success' => true,
                'received' => true,
                'duplicate' => false,
                'message' => 'Webhook event recorded for review.',
            ]);
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[ZenithPay Webhook] Recording failed: ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Webhook recording failed.']);
        }
    }

    private function isAllowedIp(string $remoteIp): bool
    {
        if (!filter_var($remoteIp, FILTER_VALIDATE_IP)) {
            return false;
        }

        $allowedIps = array_filter(array_map(
            'trim',
            explode(',', (string) ZENITHPAY_WEBHOOK_ALLOWED_IPS)
        ));

        return in_array($remoteIp, $allowedIps, true);
    }
}
