<?php
namespace Services;

use PDO;
use Exception;
use RuntimeException;
use Services\WalletService;
use Services\AuditService;
use Services\NotificationService;

class RefundService {
    private PDO $db;
    private WalletService $walletService;
    private AuditService $auditService;
    private NotificationService $notificationService;

    public function __construct(
        PDO $db,
        WalletService $walletService,
        AuditService $auditService,
        NotificationService $notificationService
    ) {
        $this->db = $db;
        $this->walletService = $walletService;
        $this->auditService = $auditService;
        $this->notificationService = $notificationService;
    }

    public function processRefund(int $requestId, int $adminId, string $reason): array {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                SELECT r.*, s.name as service_name
                FROM manual_requests r
                JOIN services s ON r.service_id = s.id
                WHERE r.id = :id FOR UPDATE
            ");
            $stmt->execute(['id' => $requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new RuntimeException("Request not found.");
            }

            if (!in_array($request['status'], ['rejected', 'cancelled'])) {
                throw new RuntimeException("Request status must be rejected or cancelled to refund.");
            }

            $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM refunds WHERE request_id = :requestId");
            $stmt->execute(['requestId' => $requestId]);
            if ($stmt->fetchColumn() > 0) {
                throw new RuntimeException("Refund already exists for this request.");
            }

            $stmt = $this->db->prepare("SELECT * FROM transactions WHERE related_request_id = :requestId AND type = 'debit' LIMIT 1");
            $stmt->execute(['requestId' => $requestId]);
            $debitTx = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$debitTx) {
                throw new RuntimeException("Original debit transaction not found.");
            }

            $amount = (float)$debitTx['amount'];
            $userId = (int)$request['user_id'];

            $creditTx = $this->walletService->creditAtomically(
                $userId,
                $amount,
                "Refund for request {$request['reference']} - $reason",
                $requestId
            );

            $stmt = $this->db->prepare("UPDATE manual_requests SET status = 'refunded' WHERE id = :id");
            $stmt->execute(['id' => $requestId]);

            $stmt = $this->db->prepare("
                INSERT INTO refunds (request_id, transaction_id, admin_id, amount, reason, refunded_at)
                VALUES (:requestId, :txId, :adminId, :amount, :reason, NOW())
            ");
            $stmt->execute([
                'requestId' => $requestId,
                'txId' => $creditTx['id'],
                'adminId' => $adminId,
                'amount' => $amount,
                'reason' => $reason
            ]);
            $refundId = $this->db->lastInsertId();

            $stmt = $this->db->prepare("
                INSERT INTO request_status_history (request_id, old_status, new_status, changed_by_type, changed_by_id, notes, changed_at)
                VALUES (:requestId, :oldStatus, 'refunded', 'admin', :adminId, :notes, NOW())
            ");
            $stmt->execute([
                'requestId' => $requestId,
                'oldStatus' => $request['status'],
                'adminId' => $adminId,
                'notes' => $reason
            ]);

            $this->auditService->log(
                AuditService::REFUND_ISSUED,
                $requestId,
                'admin',
                $adminId,
                $request['status'],
                'refunded',
                $reason
            );

            $this->notificationService->notifyRefund(
                $userId,
                $requestId,
                $amount,
                $request['service_name']
            );

            $this->db->commit();

            return [
                'refund_id' => (int)$refundId,
                'request_id' => $requestId,
                'amount' => $amount,
                'transaction_id' => $creditTx['id'],
                'status' => 'refunded'
            ];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getRefundForRequest(int $requestId): array|false {
        $stmt = $this->db->prepare("SELECT * FROM refunds WHERE request_id = :requestId");
        $stmt->execute(['requestId' => $requestId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}



