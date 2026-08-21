<?php

namespace Controllers;

use Core\Database;
use Helpers\Response;
use Services\RequestService;
use Services\FileStorageService;
use Exceptions\InsufficientBalanceException;
use Exceptions\DuplicateTransactionException;
use Exception;
use PDO;

class ManualRequestController
{
    private PDO $db;
    private RequestService $requestService;
    private int $userId;

    public function __construct()
    {
        $this->db = db();
        $pricing = new \Services\PricingService($this->db);
        $wallet = new \Services\WalletService($this->db);
        $driver = new \Services\LocalStorageDriver(STORAGE_BASE_PATH);
        $storage = new \Services\FileStorageService($driver);
        $audit = new \Services\AuditService($this->db);
        $notif = new \Services\NotificationService($this->db);
        
        $this->requestService = new RequestService($this->db, $pricing, $wallet, $storage, $audit, $notif);
        $this->userId = \Middleware\AuthMiddleware::getUserId();
    }

    public function submit(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $serviceSlug = $_POST['service_slug'] ?? $input['service_slug'] ?? null;
        $variantKey = $_POST['variant_key'] ?? $input['variant_key'] ?? null;
        $idempotencyKey = $_POST['idempotency_key'] ?? $input['idempotency_key'] ?? null;
        $pin = $_POST['pin'] ?? $input['pin'] ?? null;
        
        $formData = $_POST['form_data'] ?? $input['form_data'] ?? [];
        if (is_string($formData)) {
            $formData = json_decode($formData, true) ?? [];
        }
        
        if (!$idempotencyKey) {
            Response::error('idempotency_key is required', [], 400);
        }

        try {
            $result = $this->requestService->submit(
                $this->userId,
                $serviceSlug,
                $variantKey,
                $formData,
                $_FILES,
                $idempotencyKey
            );

            Response::success($result);
        } catch (InsufficientBalanceException $e) {
            Response::error('Insufficient wallet balance', [], 402);
        } catch (DuplicateTransactionException $e) {
            Response::success(['success' => true, 'data' => $e->getExistingData()]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function submitBulk(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $serviceSlug = $_POST['service_slug'] ?? $input['service_slug'] ?? null;
        $variantKey = $_POST['variant_key'] ?? $input['variant_key'] ?? null;
        $idempotencyKey = $_POST['idempotency_key'] ?? $input['idempotency_key'] ?? bin2hex(random_bytes(16));
        $pin = $_POST['pin'] ?? $input['pin'] ?? null;
        
        $items = $_POST['items'] ?? $input['items'] ?? $input['nins'] ?? $input['tracking_ids'] ?? [];
        if (is_string($items)) {
            $items = json_decode($items, true) ?? array_filter(array_map('trim', explode("\n", $items)));
        }

        if (!$serviceSlug) {
            Response::error('service_slug is required', [], 400);
            return;
        }

        try {
            $result = $this->requestService->submitBulk(
                $this->userId,
                $serviceSlug,
                $variantKey,
                $idempotencyKey,
                $pin,
                $items
            );
            
            Response::success($result, 'Bulk request submitted successfully');
        } catch (InsufficientBalanceException $e) {
            Response::error('Insufficient wallet balance', [], 402);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 400);
        }
    }

    public function getRequests(): void
    {
        try {
            $page = (int)($_GET['page'] ?? 1);
            $perPage = 50;
            $offset = ($page - 1) * $perPage;
            $status = $_GET['status'] ?? null;

            $statusClause1 = $status ? " AND r.status = :status1" : "";
            $statusClause2 = $status ? " AND t.gv_status = :status2" : "";

            $query = "
                SELECT 
                    r.reference, 
                    r.status, 
                    r.price_paid, 
                    r.submitted_at, 
                    s.name as service_name, 
                    c.name as category, 
                    s.est_time as est_time, 
                    s.slug as service_slug,
                    CASE WHEN r.result_file_id IS NOT NULL THEN 1 ELSE 0 END as has_result,
                    'manual' as request_type,
                    NULL as provider_ticket_id
                FROM manual_requests r
                JOIN services s ON r.service_id = s.id
                JOIN service_categories c ON s.category_id = c.id
                WHERE r.user_id = :userId1 {$statusClause1}

                UNION ALL

                SELECT 
                    t.gv_reference as reference, 
                    t.gv_status as status, 
                    COALESCE(tx.amount, 0) as price_paid, 
                    t.submitted_at as submitted_at, 
                    s.name as service_name, 
                    c.name as category, 
                    COALESCE(s.est_time, 'Instant') as est_time, 
                    s.slug as service_slug,
                    CASE WHEN t.result_data IS NOT NULL THEN 1 ELSE 0 END as has_result,
                    'api' as request_type,
                    t.provider_ticket_id
                FROM api_transactions t
                JOIN services s ON t.service_id = s.id
                JOIN service_categories c ON s.category_id = c.id
                LEFT JOIN transactions tx ON t.transaction_id = tx.id
                WHERE t.user_id = :userId2 {$statusClause2}

                ORDER BY submitted_at DESC 
                LIMIT :perPage OFFSET :offset
            ";

            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':userId1', $this->userId, PDO::PARAM_INT);
            $stmt->bindValue(':userId2', $this->userId, PDO::PARAM_INT);
            if ($status) {
                $stmt->bindValue(':status1', $status, PDO::PARAM_STR);
                $stmt->bindValue(':status2', $status, PDO::PARAM_STR);
            }
            $stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success($requests);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function getRequest(string $reference): void
    {
        try {
            $stmt = $this->db->prepare("SELECT r.*, s.name as service_name, c.name as category, s.description, s.est_time as est_time 
                                        FROM manual_requests r
                                        JOIN services s ON r.service_id = s.id
                                        JOIN service_categories c ON s.category_id = c.id
                                        WHERE r.reference = ? AND r.user_id = ?");
            $stmt->execute([$reference, $this->userId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                Response::error('Request not found', [], 404);
            }

            $stmtDocs = $this->db->prepare("SELECT id, field_name, original_name, stored_name, mime_type, file_size FROM request_documents WHERE request_id = ?");
            $stmtDocs->execute([$request['id']]);
            $documents = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
            foreach ($documents as &$doc) {
                $doc['download_url'] = "/manual/requests/{$reference}/documents/{$doc['id']}";
            }

            $stmtHist = $this->db->prepare("SELECT old_status, new_status, notes, changed_at FROM request_status_history WHERE request_id = ? ORDER BY changed_at DESC");
            $stmtHist->execute([$request['id']]);
            $statusHistory = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

            $stmtNotif = $this->db->prepare("SELECT title, body, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
            $stmtNotif->execute([$this->userId]);
            $notifications = $stmtNotif->fetchAll(PDO::FETCH_ASSOC);

            $data = [
                'reference' => $request['reference'],
                'status' => $request['status'],
                'price_paid' => $request['price_paid'],
                'submitted_at' => $request['submitted_at'],
                'completed_at' => $request['completed_at'],
                'additional_info_request' => $request['additional_info_request'],
                'rejection_reason' => $request['rejection_reason'],
                'form_data' => json_decode($request['form_data'], true),
                'service' => [
                    'name' => $request['service_name'],
                    'category' => $request['category'],
                    'description' => $request['description'],
                    'est_time' => $request['est_time'],
                ],
                'result_available' => $request['result_file_id'] !== null,
                'documents' => $documents,
                'status_history' => $statusHistory,
                'notifications' => $notifications
            ];

            Response::success(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function submitAdditionalInfo(string $reference): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $response = $input['response'] ?? null;

        if (!$response) {
            Response::error('response is required', [], 400);
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT id, status FROM manual_requests WHERE reference = ? AND user_id = ?");
            $stmt->execute([$reference, $this->userId]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req || $req['status'] !== 'awaiting_info') {
                throw new Exception('Request not found or not awaiting info');
            }

            $stmtUpd = $this->db->prepare("UPDATE manual_requests SET status = 'info_received', additional_info_response = ? WHERE id = ?");
            $stmtUpd->execute([$response, $req['id']]);

            $stmtHist = $this->db->prepare("INSERT INTO request_status_history (request_id, status, notes) VALUES (?, 'info_received', 'User submitted additional info')");
            $stmtHist->execute([$req['id']]);

            $stmtAudit = $this->db->prepare("INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, details) VALUES (NULL, 'INFO_RECEIVED', 'manual_request', ?, ?)");
            $stmtAudit->execute([$req['id'], json_encode(['response' => $response])]);

            $this->db->commit();
            Response::success(['success' => true, 'message' => 'Information submitted successfully', 'data' => ['status' => 'info_received']]);
        } catch (Exception $e) {
            $this->db->rollBack();
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function downloadDocument(string $reference, int $docId): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT rd.storage_path, rd.original_name, rd.mime_type
                FROM request_documents rd
                JOIN manual_requests r ON rd.request_id = r.id
                WHERE r.reference = ? AND rd.id = ? AND r.user_id = ?
            ");
            $stmt->execute([$reference, $docId, $this->userId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$doc) {
                Response::error('Document not found', [], 404);
                return;
            }

            $fullPath = STORAGE_BASE_PATH . DIRECTORY_SEPARATOR . $doc['storage_path'];
            if (!file_exists($fullPath)) {
                Response::error('File not found on disk', [], 404);
                return;
            }

            header('Content-Type: ' . $doc['mime_type']);
            header('Content-Disposition: inline; filename="' . addslashes($doc['original_name']) . '"');
            header('Content-Length: ' . filesize($fullPath));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            readfile($fullPath);
            exit;
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function downloadResult(string $reference): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT rf.storage_path, rf.original_name, rf.mime_type
                FROM manual_requests r
                JOIN result_files rf ON r.result_file_id = rf.id
                WHERE r.reference = ? AND r.user_id = ? AND r.status = 'completed'
            ");
            $stmt->execute([$reference, $this->userId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$file) {
                Response::error('Result not available or request not yet completed', [], 404);
                return;
            }

            $fullPath = STORAGE_BASE_PATH . DIRECTORY_SEPARATOR . $file['storage_path'];
            if (!file_exists($fullPath)) {
                Response::error('File not found on disk', [], 404);
                return;
            }

            header('Content-Type: ' . $file['mime_type']);
            header('Content-Disposition: attachment; filename="' . addslashes($file['original_name']) . '"');
            header('Content-Length: ' . filesize($fullPath));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            readfile($fullPath);
            exit;
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }
}





