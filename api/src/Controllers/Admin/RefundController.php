<?php

namespace Controllers\Admin;

use Core\Database;
use Helpers\Response;
use Services\RefundService;
use Exception;
use PDO;

class RefundController
{
    private PDO $db;
    private int $adminId;
    private RefundService $refundService;

    public function __construct()
    {
        $this->db = db();
        $this->adminId = \Middleware\AdminMiddleware::getAdminId();
        
        $wallet = new \Services\WalletService($this->db);
        $audit = new \Services\AuditService($this->db);
        $notif = new \Services\NotificationService($this->db);
        $this->refundService = new RefundService($this->db, $wallet, $audit, $notif);
    }

    public function processRefund(string $reference): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $reason = $input['reason'] ?? null;

        if (!$reason) {
            Response::error('reason is required', [], 400);
        }

        try {
            $stmt = $this->db->prepare("SELECT id FROM manual_requests WHERE reference = ?");
            $stmt->execute([$reference]);
            $requestId = $stmt->fetchColumn();

            if (!$requestId) {
                Response::error('Request not found', [], 404);
            }

            $refundData = $this->refundService->processRefund($requestId, $this->adminId, $reason);

            Response::success([
                'success' => true,
                'data' => $refundData
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function getRefund(string $reference): void
    {
        try {
            $stmt = $this->db->prepare("SELECT rf.* 
                                        FROM refunds rf
                                        JOIN manual_requests r ON rf.request_id = r.id
                                        WHERE r.reference = ?");
            $stmt->execute([$reference]);
            $refund = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$refund) {
                Response::error('Refund not found', [], 404);
            }

            Response::success([
                'success' => true,
                'data' => $refund
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }
}




