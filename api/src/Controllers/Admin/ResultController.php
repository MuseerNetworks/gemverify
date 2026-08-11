<?php

namespace Controllers\Admin;

use Core\Database;
use Helpers\Response;
use Services\FileStorageService;
use Exception;
use PDO;

class ResultController
{
    private PDO $db;
    private int $adminId;

    public function __construct()
    {
        $this->db = db();
        $this->adminId = $_SERVER['ADMIN_ID'] ?? 1;
    }

    public function uploadResult(string $reference): void
    {
        if (!isset($_FILES['result_file']) || $_FILES['result_file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('result_file is required and must be valid', [], 400);
        }

        $file = $_FILES['result_file'];
        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
        
        if (!in_array($file['type'], $allowedMimes)) {
            Response::error('Invalid file type. Only PDF, JPEG, PNG allowed.', [], 400);
        }
        
        if ($file['size'] > 10 * 1024 * 1024) {
            Response::error('File size exceeds 10MB limit', [], 400);
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT id FROM manual_requests WHERE reference = ?");
            $stmt->execute([$reference]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                throw new Exception('Request not found');
            }
            $requestId = $req['id'];

            $stmtVersion = $this->db->prepare("SELECT MAX(version) as max_v FROM result_files WHERE request_id = ?");
            $stmtVersion->execute([$requestId]);
            $version = (int)$stmtVersion->fetchColumn() + 1;

            $driver = new \Services\LocalStorageDriver(STORAGE_BASE_PATH);
            $fileStorage = new \Services\FileStorageService($driver);
            $resData = $fileStorage->storeResult($file, $reference, $version);

            $stmtUpd = $this->db->prepare("UPDATE result_files SET is_current = 0 WHERE request_id = ?");
            $stmtUpd->execute([$requestId]);

            $stmtIns = $this->db->prepare("INSERT INTO result_files (request_id, uploaded_by, original_name, stored_name, mime_type, file_size, storage_path, version, is_current, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
            $stmtIns->execute([
                $requestId,
                \Middleware\AdminMiddleware::getAdminId(),
                $resData['original_name'],
                $resData['stored_name'],
                $resData['mime_type'],
                $resData['file_size'],
                $resData['storage_path'],
                $version
            ]);
            $resultFileId = $this->db->lastInsertId();

            $stmtReqUpd = $this->db->prepare("UPDATE manual_requests SET result_file_id = ?, status = 'completed', completed_at = NOW() WHERE id = ?");
            $stmtReqUpd->execute([$resultFileId, $requestId]);

            // Record status history
            $stmtStatusHist = $this->db->prepare("INSERT INTO request_status_history (request_id, old_status, new_status, changed_by_type, changed_by_id, notes) VALUES (?, 'processing', 'completed', 'admin', ?, 'Result file uploaded by admin')");
            $stmtStatusHist->execute([$requestId, \Middleware\AdminMiddleware::getAdminId()]);

            // Fetch user_id for notification
            $stmtUser = $this->db->prepare("SELECT user_id FROM manual_requests WHERE id = ?");
            $stmtUser->execute([$requestId]);
            $userId = (int)$stmtUser->fetchColumn();

            // Notify user
            if ($userId) {
                $notif = new \Services\NotificationService($this->db);
                $notif->notify($userId, $requestId, 'result_ready', 'Result Ready', "Your request ($reference) has been completed. Your result document is now available for download.");
            }

            $audit = new \Services\AuditService($this->db);
            $action = $version > 1 ? 'RESULT_REPLACED' : 'RESULT_UPLOADED';
            $audit->log($action, $requestId, 'admin', \Middleware\AdminMiddleware::getAdminId(), null, ['result_file_id' => $resultFileId, 'version' => $version]);

            $this->db->commit();

            Response::success([
                'id' => $resultFileId,
                'version' => $version,
                'is_current' => 1
            ]);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function getResult(string $reference): void
    {
        try {
            $stmt = $this->db->prepare("SELECT rf.id, rf.version, rf.uploaded_at, rf.is_current 
                                        FROM result_files rf
                                        JOIN manual_requests r ON rf.request_id = r.id
                                        WHERE r.reference = ? AND rf.is_current = 1");
            $stmt->execute([$reference]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                Response::error('No result found for this request', [], 404);
            }

            Response::success(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function downloadResultAdmin(string $id): void
    {
        if (!isset($this->adminId)) {
            Response::error('Unauthorized', [], 401);
        }

        try {
            $stmt = $this->db->prepare("SELECT storage_path, original_name, mime_type FROM result_files WHERE id = ?");
            $stmt->execute([$id]);
            $fileRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $path = $fileRow['storage_path'] ?? null;

            if (!$path) {
                Response::error('File not found', [], 404);
            }

            $driver = new \Services\LocalStorageDriver(STORAGE_BASE_PATH);
            $fileStorage = new FileStorageService($driver);
            $fileStorage->serveFile($path, $fileRow['original_name'] ?? 'result', $fileRow['mime_type'] ?? 'application/octet-stream');
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }
}




