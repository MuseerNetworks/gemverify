<?php
declare(strict_types=1);
namespace Controllers\Webhooks;
use PDO;
use Services\WalletService;

/** Validated ZenithPay Dedicated Virtual Account webhook processor. */
final class ZenithPayWebhookController {
    private PDO $db;
    public function __construct(?PDO $db = null) { $this->db = $db ?: \db(); }
    public function handle(): void {
        header('Content-Type: application/json; charset=UTF-8');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!$this->allowed($ip)) { $this->respond(403, false, 'Webhook source is not allowed.'); return; }
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') { $this->respond(400, false, 'Webhook body is required.'); return; }
        if (strlen($raw) > ZENITHPAY_WEBHOOK_MAX_BODY_BYTES) { $this->respond(413, false, 'Webhook body is too large.'); return; }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) { $this->respond(400, false, 'Webhook must contain JSON.'); return; }
        $eventId = $this->recordEvent($ip, (string) ($_SERVER['CONTENT_TYPE'] ?? ''), $raw);
        $transactionId = trim((string) ($payload['transaction_id'] ?? $payload['orderId'] ?? ''));
        if ($transactionId === '') { $this->markEvent($eventId, 'rejected'); $this->respond(400, false, 'Missing transaction ID.'); return; }
        $details = is_array($payload['paymentDetails'] ?? null) ? $payload['paymentDetails'] : [];
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        $reference = trim((string) ($details['accountReference'] ?? $payload['accountReference'] ?? ''));
        $number = trim((string) ($details['account_number'] ?? $payload['accountNumber'] ?? ''));
        $gross = $this->amount($details['amountPaid'] ?? $payload['amountPaid'] ?? null);
        $settlement = $this->amount($details['settlementAmount'] ?? $payload['settlementAmount'] ?? null);
        try {
            $this->db->beginTransaction();
            $insert = $this->db->prepare("INSERT INTO zenithpay_deposits (transaction_id,order_id,account_reference,account_number,gross_amount,settlement_amount,payment_status,session_id,processing_status,received_at) VALUES (?,?,?,?,?,?,?,?, 'received',NOW())");
            try { $insert->execute([$transactionId, $payload['orderId'] ?? $transactionId, $reference ?: null, $number ?: null, $gross, $settlement, $status ?: 'unknown', $payload['sessionId'] ?? null]); }
            catch (\PDOException $e) { if (($e->errorInfo[1] ?? 0) !== 1062) throw $e; $this->db->rollBack(); $this->markEvent($eventId, 'mapped'); $this->respond(200, true, 'Transaction already processed.', true); return; }
            $depositId = (int) $this->db->lastInsertId();
            if ($status !== 'success' || $gross === null || $gross <= 0 || ($reference === '' && $number === '')) {
                $this->db->prepare("UPDATE zenithpay_deposits SET processing_status='ignored' WHERE id=?")->execute([$depositId]);
                $this->db->commit(); $this->markEvent($eventId, 'mapped'); $this->respond(200, true, 'Webhook recorded without wallet credit.'); return;
            }
            $stmt = $this->db->prepare("SELECT * FROM payment_virtual_accounts WHERE provider_key='zenithpay' AND ((account_reference = ? AND ? <> '') OR (account_number = ? AND ? <> '')) LIMIT 1 FOR UPDATE");
            $stmt->execute([$reference, $reference, $number, $number]); $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$account || $account['status'] !== 'active') {
                $this->db->prepare("UPDATE zenithpay_deposits SET processing_status='unmatched' WHERE id=?")->execute([$depositId]);
                $this->db->commit(); $this->markEvent($eventId, 'rejected'); $this->respond(200, true, 'Webhook recorded for account review.'); return;
            }
            $credit = (new WalletService($this->db))->creditAtomically((int) $account['user_id'], (float) $gross, 'Wallet funding via ZenithPay (Ref: ' . $transactionId . ')', null);
            $this->db->prepare("UPDATE zenithpay_deposits SET user_id=?,zenithpay_virtual_account_id=?,processing_status='credited',credited_tx_id=?,completed_at=NOW() WHERE id=?")->execute([(int)$account['user_id'], (int)$account['id'], (int)$credit['id'], $depositId]);
            $this->db->prepare('UPDATE payment_virtual_accounts SET last_credit_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$account['id']]);
            $this->db->prepare('UPDATE zenithpay_virtual_accounts SET last_credit_at=NOW(),updated_at=NOW() WHERE user_id=?')->execute([(int)$account['user_id']]);
            $this->db->commit(); $this->markEvent($eventId, 'mapped'); $this->respond(200, true, 'Wallet credited.');
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); $this->markEvent($eventId, 'rejected'); error_log('[ZenithPay Webhook] ' . $e->getMessage()); $this->respond(500, false, 'Webhook processing failed.'); }
    }
    private function recordEvent(string $ip, string $type, string $raw): int {
        $hash = hash('sha256', $raw); $this->db->beginTransaction();
        try { $stmt=$this->db->prepare('SELECT id FROM zenithpay_webhook_events WHERE payload_sha256=? FOR UPDATE'); $stmt->execute([$hash]); $id=$stmt->fetchColumn(); if ($id) $this->db->prepare('UPDATE zenithpay_webhook_events SET delivery_count=delivery_count+1,last_received_at=NOW() WHERE id=?')->execute([$id]); else { $this->db->prepare("INSERT INTO zenithpay_webhook_events (remote_ip,content_type,payload_sha256,payload,processing_status,received_at,last_received_at) VALUES (?,?,?,?, 'received',NOW(),NOW())")->execute([$ip,substr($type,0,128),$hash,$raw]); $id=$this->db->lastInsertId(); } $this->db->commit(); return (int)$id; }
        catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    private function markEvent(int $id, string $status): void { try { $this->db->prepare('UPDATE zenithpay_webhook_events SET processing_status=? WHERE id=?')->execute([$status,$id]); } catch (\Throwable) {} }
    private function amount(mixed $v): ?float { return is_numeric($v) && (float)$v >= 0 ? round((float)$v,2) : null; }
    private function allowed(string $ip): bool { return filter_var($ip,FILTER_VALIDATE_IP) && in_array($ip,array_filter(array_map('trim',explode(',',(string)ZENITHPAY_WEBHOOK_ALLOWED_IPS))),true); }
    private function respond(int $code, bool $success, string $message, bool $duplicate=false): void { http_response_code($code); echo json_encode(['success'=>$success,'received'=>$success,'duplicate'=>$duplicate,'message'=>$message]); }
}
