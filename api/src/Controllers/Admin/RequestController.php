<?php

namespace Controllers\Admin;

use Core\Database;
use Helpers\Response;
use Core\Request;
use Exception;
use PDO;

class RequestController
{
    private PDO $db;
    private int $adminId;

    public function __construct()
    {
        $this->db = db();
        // Assumes AdminMiddleware sets the admin ID in Request attributes or $_SERVER
        $this->adminId = $_SERVER['ADMIN_ID'] ?? 1;
    }

    public function getAll(): void
    {
        try {
            $page = (int)($_GET['page'] ?? 1);
            $perPage = (int)($_GET['per_page'] ?? 20);
            $offset = ($page - 1) * $perPage;
            
            $status = $_GET['status'] ?? null;
            $serviceSlug = $_GET['service_slug'] ?? null;
            $category = $_GET['category'] ?? null;
            $userId = $_GET['user_id'] ?? null;
            $reference = $_GET['reference'] ?? null;
            $dateFrom = $_GET['date_from'] ?? null;
            $dateTo = $_GET['date_to'] ?? null;
            $assignedAdminId = $_GET['assigned_admin_id'] ?? null;
            
            $query = "SELECT * FROM (
                SELECT 
                    r.id, 
                    r.reference, 
                    r.status, 
                    r.price_paid, 
                    r.submitted_at, 
                    u.id as user_id,
                    u.business_name, 
                    u.email as user_email, 
                    s.name as service_name, 
                    s.slug as service_slug,
                    c.name as category,
                    a.name as assigned_admin,
                    r.assigned_admin_id,
                    'manual' as request_type
                FROM manual_requests r
                JOIN users u ON r.user_id = u.id
                JOIN services s ON r.service_id = s.id
                JOIN service_categories c ON s.category_id = c.id
                LEFT JOIN admins a ON r.assigned_admin_id = a.id

                UNION ALL

                SELECT 
                    at.id, 
                    at.gv_reference as reference, 
                    at.gv_status as status, 
                    COALESCE(sp.price, t.amount, 0) as price_paid, 
                    at.submitted_at, 
                    u.id as user_id,
                    u.business_name, 
                    u.email as user_email, 
                    s.name as service_name, 
                    s.slug as service_slug,
                    c.name as category,
                    NULL as assigned_admin,
                    NULL as assigned_admin_id,
                    'api' as request_type
                FROM api_transactions at
                JOIN users u ON at.user_id = u.id
                JOIN services s ON at.service_id = s.id
                JOIN service_categories c ON s.category_id = c.id
                LEFT JOIN service_pricing sp ON at.pricing_id = sp.id
                LEFT JOIN transactions t ON at.transaction_id = t.id
            ) unified_reqs
            WHERE 1=1";
            
            $params = [];
            
            if ($status) {
                if ($status === 'rejected') {
                    $query .= " AND status IN ('rejected', 'failed', 'cancelled', 'refunded')";
                } elseif ($status === 'submitted' || $status === 'pending') {
                    $query .= " AND status IN ('submitted', 'pending')";
                } else {
                    $query .= " AND status = ?";
                    $params[] = $status;
                }
            }
            if ($serviceSlug) { $query .= " AND service_slug = ?"; $params[] = $serviceSlug; }
            if ($category) { $query .= " AND category = ?"; $params[] = $category; }
            if ($userId) { $query .= " AND user_id = ?"; $params[] = $userId; }
            if ($reference) { $query .= " AND reference = ?"; $params[] = $reference; }
            if ($dateFrom) { $query .= " AND DATE(submitted_at) >= ?"; $params[] = $dateFrom; }
            if ($dateTo) { $query .= " AND DATE(submitted_at) <= ?"; $params[] = $dateTo; }
            if ($assignedAdminId) { $query .= " AND assigned_admin_id = ?"; $params[] = $assignedAdminId; }
            
            $countQuery = "SELECT COUNT(*) FROM (" . $query . ") count_tbl";
            $stmtCount = $this->db->prepare($countQuery);
            $stmtCount->execute($params);
            $total = (int)$stmtCount->fetchColumn();
            
            $query .= " ORDER BY submitted_at DESC LIMIT ? OFFSET ?";
            $params[] = $perPage;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($query);
            foreach ($params as $i => $param) {
                $type = is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($i + 1, $param, $type);
            }
            $stmt->execute();
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format requests
            $formattedRequests = array_map(function($req) {
                return [
                    'reference' => $req['reference'],
                    'user' => [
                        'business_name' => $req['business_name'],
                        'email' => $req['user_email'],
                    ],
                    'service' => [
                        'name' => $req['service_name'],
                        'category' => $req['category'],
                    ],
                    'status' => $req['status'],
                    'price_paid' => $req['price_paid'],
                    'submitted_at' => $req['submitted_at'],
                    'assigned_admin' => $req['assigned_admin'],
                    'request_type' => $req['request_type']
                ];
            }, $requests);

            Response::success([
                'success' => true,
                'data' => [
                    'requests' => $formattedRequests,
                    'pagination' => [
                        'total' => $total,
                        'page' => $page,
                        'per_page' => $perPage,
                        'total_pages' => ceil($total / $perPage)
                    ]
                ]
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function getDetail(string $reference): void
    {
        try {
            // First check manual_requests
            $stmt = $this->db->prepare("SELECT r.*, u.business_name, u.email as user_email, u.phone as user_phone, 
                                               s.name as service_name, c.name as service_category, s.description as service_desc,
                                               a.name as assigned_admin_name, a.email as assigned_admin_email,
                                               rf.reason as refund_reason, rf.amount as refund_amount, rf.status as refund_status,
                                               t.reference as trx_reference, t.amount as trx_amount
                                        FROM manual_requests r
                                        JOIN users u ON r.user_id = u.id
                                        JOIN services s ON r.service_id = s.id
                                        JOIN service_categories c ON s.category_id = c.id
                                        LEFT JOIN admins a ON r.assigned_admin_id = a.id
                                        LEFT JOIN refunds rf ON rf.request_id = r.id
                                        LEFT JOIN transactions t ON t.related_request_id = r.id
                                        WHERE r.reference = ?");
            $stmt->execute([$reference]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($request) {
                // Get submitted form data
                $stmtForm = $this->db->prepare("SELECT form_data FROM request_form_data WHERE request_id = ?");
                $stmtForm->execute([$request['id']]);
                $formDataRaw = $stmtForm->fetchColumn();
                $formData = $formDataRaw ? json_decode($formDataRaw, true) : null;

                // Get uploaded documents
                $stmtDocs = $this->db->prepare("SELECT id, field_name, original_name, stored_name, mime_type, file_size, uploaded_at FROM request_documents WHERE request_id = ?");
                $stmtDocs->execute([$request['id']]);
                $documents = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
                foreach ($documents as &$doc) {
                    $doc['secure_url'] = "/admin/requests/{$reference}/documents/{$doc['id']}";
                }

                // Get result files
                $stmtRes = $this->db->prepare("SELECT id, version, uploaded_at, is_current FROM result_files WHERE request_id = ? AND is_current = 1");
                $stmtRes->execute([$request['id']]);
                $resultFiles = $stmtRes->fetchAll(PDO::FETCH_ASSOC);

                // Get status history
                $stmtHist = $this->db->prepare("SELECT old_status, new_status, notes, changed_at FROM request_status_history WHERE request_id = ? ORDER BY changed_at DESC");
                $stmtHist->execute([$request['id']]);
                $statusHistory = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

                // Get admin notes
                $stmtNotes = $this->db->prepare("SELECT n.note, n.created_at, a.name as admin_name 
                                                 FROM admin_notes n 
                                                 JOIN admins a ON n.admin_id = a.id 
                                                 WHERE n.request_id = ? ORDER BY n.created_at DESC");
                $stmtNotes->execute([$request['id']]);
                $adminNotes = $stmtNotes->fetchAll(PDO::FETCH_ASSOC);

                $data = [
                    'request' => [
                        'id' => $request['id'],
                        'reference' => $request['reference'],
                        'status' => $request['status'],
                        'price_paid' => $request['price_paid'],
                        'form_data' => $formData,
                        'additional_info_request' => $request['additional_info_request'],
                        'additional_info_response' => $request['additional_info_response'],
                        'rejection_reason' => $request['rejection_reason'],
                        'submitted_at' => $request['submitted_at'],
                        'completed_at' => $request['completed_at']
                    ],
                    'user' => [
                        'business_name' => $request['business_name'],
                        'email' => $request['user_email'],
                        'phone' => $request['user_phone'],
                    ],
                    'service' => [
                        'name' => $request['service_name'],
                        'category' => $request['service_category'],
                        'description' => $request['service_desc'],
                    ],
                    'assigned_admin' => $request['assigned_admin_name'] ? [
                        'name' => $request['assigned_admin_name'],
                        'email' => $request['assigned_admin_email']
                    ] : null,
                    'transaction' => $request['trx_reference'] ? [
                        'reference' => $request['trx_reference'],
                        'amount' => $request['trx_amount']
                    ] : null,
                    'refund' => $request['refund_reason'] ? [
                        'reason' => $request['refund_reason'],
                        'amount' => $request['refund_amount'],
                        'status' => $request['refund_status']
                    ] : null,
                    'documents' => $documents,
                    'result_files' => $resultFiles,
                    'status_history' => $statusHistory,
                    'admin_notes' => $adminNotes
                ];

                Response::success(['success' => true, 'data' => $data]);
                return;
            }

            // If not found in manual_requests, check api_transactions
            $stmtApi = $this->db->prepare("
                SELECT at.*, 
                       u.business_name, u.email as user_email, u.phone as user_phone,
                       s.name as service_name, s.description as service_desc,
                       c.name as service_category,
                       COALESCE(sp.price, t.amount, 0) as price_paid,
                       t.reference as trx_reference, t.amount as trx_amount
                FROM api_transactions at
                JOIN users u ON at.user_id = u.id
                JOIN services s ON at.service_id = s.id
                JOIN service_categories c ON s.category_id = c.id
                LEFT JOIN service_pricing sp ON at.pricing_id = sp.id
                LEFT JOIN transactions t ON at.transaction_id = t.id
                WHERE at.gv_reference = ?
            ");
            $stmtApi->execute([$reference]);
            $apiTx = $stmtApi->fetch(PDO::FETCH_ASSOC);

            if (!$apiTx) {
                Response::error('Request not found', [], 404);
                return;
            }

            $formData = [
                'Input Method'       => $apiTx['input_method'] ?? 'N/A',
                'Input Summary'      => $apiTx['input_summary'] ?? 'N/A',
                'Variant Key'        => $apiTx['variant_key'] ?? 'Standard',
                'Provider'           => $apiTx['provider'] ?? 'techhub',
                'Provider Status'    => $apiTx['provider_status'] ?? 'N/A',
                'Provider Ticket ID' => $apiTx['provider_ticket_id'] ?? 'N/A',
                'Error Code'         => $apiTx['error_code'] ?? 'None',
                'Error Message'      => $apiTx['error_message'] ?? 'None'
            ];

            $statusHistory = [
                [
                    'status' => $apiTx['gv_status'],
                    'notes'  => $apiTx['provider_status'] ? 'Provider status: ' . $apiTx['provider_status'] : 'API request submission',
                    'changed_at' => $apiTx['completed_at'] ?? $apiTx['submitted_at']
                ]
            ];

            $data = [
                'request' => [
                    'id' => $apiTx['id'],
                    'reference' => $apiTx['gv_reference'],
                    'status' => $apiTx['gv_status'],
                    'price_paid' => $apiTx['price_paid'],
                    'form_data' => $formData,
                    'additional_info_request' => null,
                    'additional_info_response' => null,
                    'rejection_reason' => $apiTx['error_message'],
                    'submitted_at' => $apiTx['submitted_at'],
                    'completed_at' => $apiTx['completed_at']
                ],
                'user' => [
                    'business_name' => $apiTx['business_name'],
                    'email' => $apiTx['user_email'],
                    'phone' => $apiTx['user_phone'],
                ],
                'service' => [
                    'name' => $apiTx['service_name'],
                    'category' => $apiTx['service_category'],
                    'description' => $apiTx['service_desc'],
                ],
                'assigned_admin' => null,
                'transaction' => $apiTx['trx_reference'] ? [
                    'reference' => $apiTx['trx_reference'],
                    'amount' => $apiTx['trx_amount']
                ] : null,
                'refund' => $apiTx['refund_issued'] ? [
                    'reason' => 'Refunded via API transaction',
                    'amount' => $apiTx['price_paid'],
                    'status' => 'completed'
                ] : null,
                'documents' => [],
                'result_files' => [],
                'status_history' => $statusHistory,
                'admin_notes' => []
            ];

            Response::success(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function changeStatus(string $reference): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $status = $input['status'] ?? '';
        $notes = $input['notes'] ?? null;

        $validStatuses = ['submitted', 'pending', 'under_review', 'processing', 'completed', 'awaiting_info', 'info_received', 'rejected'];
        if (!in_array($status, $validStatuses)) {
            Response::error('Invalid status', [], 400);
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT id, user_id, status FROM manual_requests WHERE reference = ?");
            $stmt->execute([$reference]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                throw new Exception('Request not found');
            }

            $updateQ = "UPDATE manual_requests SET status = ?";
            if ($status === 'completed') {
                $updateQ .= ", completed_at = CURRENT_TIMESTAMP";
            }
            $updateQ .= " WHERE id = ?";
            
            $stmtUpd = $this->db->prepare($updateQ);
            $stmtUpd->execute([$status, $req['id']]);

            $stmtHist = $this->db->prepare("INSERT INTO request_status_history (request_id, old_status, new_status, changed_by_type, changed_by_id, notes) VALUES (?, ?, ?, 'admin', ?, ?)");
            $stmtHist->execute([$req['id'], $req['status'], $status, $this->adminId, $notes]);

            $audit = new \Services\AuditService($this->db);
            $audit->log('STATUS_CHANGED', $req['id'], 'admin', $this->adminId, ['old_status' => $req['status']], ['new_status' => $status], $notes);

            $notif = new \Services\NotificationService($this->db);
            $notif->notify($req['user_id'], $req['id'], 'status_changed', 'Request Status Update', "Your request $reference is now $status.");

            $this->db->commit();
            Response::success(['status' => $status]);
        } catch (Exception $e) {
            $this->db->rollBack();
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function assignAdmin(string $reference): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $assignedAdminId = $input['admin_id'] ?? null;

        if (!$assignedAdminId) {
            Response::error('admin_id is required', [], 400);
        }

        try {
            $stmt = $this->db->prepare("SELECT id FROM manual_requests WHERE reference = ?");
            $stmt->execute([$reference]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                Response::error('Request not found', [], 404);
            }

            $this->db->beginTransaction();

            $stmtUpd = $this->db->prepare("UPDATE manual_requests SET assigned_admin_id = ? WHERE id = ?");
            $stmtUpd->execute([$assignedAdminId, $req['id']]);

            $stmtAudit = $this->db->prepare("INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)");
            $stmtAudit->execute([$this->adminId, 'ADMIN_ASSIGNED', 'manual_request', $req['id'], json_encode(['assigned_admin_id' => $assignedAdminId])]);

            $this->db->commit();
            Response::success(['success' => true, 'message' => 'Admin assigned successfully']);
        } catch (Exception $e) {
            $this->db->rollBack();
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function addNote(string $reference): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $note = $input['note'] ?? null;

        if (!$note) {
            Response::error('note is required', [], 400);
        }

        try {
            $stmt = $this->db->prepare("SELECT id FROM manual_requests WHERE reference = ?");
            $stmt->execute([$reference]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                Response::error('Request not found', [], 404);
            }

            $this->db->beginTransaction();

            $stmtIns = $this->db->prepare("INSERT INTO admin_notes (request_id, admin_id, note) VALUES (?, ?, ?)");
            $stmtIns->execute([$req['id'], $this->adminId, $note]);
            $noteId = $this->db->lastInsertId();

            $audit = new \Services\AuditService($this->db);
            $audit->log('ADMIN_NOTE_ADDED', $req['id'], 'admin', $this->adminId, null, null, "Note added to request $reference");

            $stmtAdmin = $this->db->prepare("SELECT name FROM admins WHERE id = ?");
            $stmtAdmin->execute([$this->adminId]);
            $adminName = $stmtAdmin->fetchColumn();

            $this->db->commit();

            Response::success([
                'note' => $note,
                'admin_name' => $adminName,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function requestInfo(string $reference): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $message = $input['message'] ?? null;

        if (!$message) {
            Response::error('message is required', [], 400);
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT id, user_id, status FROM manual_requests WHERE reference = ?");
            $stmt->execute([$reference]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                throw new Exception('Request not found');
            }

            $stmtUpd = $this->db->prepare("UPDATE manual_requests SET status = 'awaiting_info', additional_info_request = ? WHERE id = ?");
            $stmtUpd->execute([$message, $req['id']]);

            $stmtHist = $this->db->prepare("INSERT INTO request_status_history (request_id, old_status, new_status, changed_by_type, changed_by_id, notes) VALUES (?, ?, 'awaiting_info', 'admin', ?, ?)");
            $stmtHist->execute([$req['id'], $req['status'], $this->adminId, 'Requested info: ' . $message]);

            $audit = new \Services\AuditService($this->db);
            $audit->log('INFO_REQUESTED', $req['id'], 'admin', $this->adminId, null, null, $message);

            $notif = new \Services\NotificationService($this->db);
            $notif->notify($req['user_id'], $req['id'], 'info_requested', 'Additional Information Required', "Action required for request $reference.");

            $this->db->commit();
            Response::success(['message' => 'Information requested successfully']);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function rejectRequest(string $reference): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $reason = $input['reason'] ?? null;

        if (!$reason) {
            Response::error('reason is required', [], 400);
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT id, user_id, status FROM manual_requests WHERE reference = ?");
            $stmt->execute([$reference]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                throw new Exception('Request not found');
            }

            $stmtUpd = $this->db->prepare("UPDATE manual_requests SET status = 'rejected', rejection_reason = ? WHERE id = ?");
            $stmtUpd->execute([$reason, $req['id']]);

            $stmtHist = $this->db->prepare("INSERT INTO request_status_history (request_id, old_status, new_status, changed_by_type, changed_by_id, notes) VALUES (?, ?, 'rejected', 'admin', ?, ?)");
            $stmtHist->execute([$req['id'], $req['status'], $this->adminId, 'Rejected: ' . $reason]);

            $audit = new \Services\AuditService($this->db);
            $audit->log('REQUEST_REJECTED', $req['id'], 'admin', $this->adminId, ['old_status' => $req['status']], ['new_status' => 'rejected'], $reason);

            $notif = new \Services\NotificationService($this->db);
            $notif->notify($req['user_id'], $req['id'], 'request_rejected', 'Request Rejected', "Your request $reference was rejected.");

            $this->db->commit();
            Response::success(['status' => 'rejected', 'rejection_reason' => $reason]);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function downloadDocument(string $reference, int $docId): void
    {
        try {
            // Verify the document belongs to this request
            $stmt = $this->db->prepare("
                SELECT rd.storage_path, rd.original_name, rd.mime_type
                FROM request_documents rd
                JOIN manual_requests r ON rd.request_id = r.id
                WHERE r.reference = ? AND rd.id = ?
            ");
            $stmt->execute([$reference, $docId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$doc) {
                Response::error('Document not found or does not belong to this request', [], 404);
                return;
            }

            $fullPath = STORAGE_BASE_PATH . DIRECTORY_SEPARATOR . $doc['storage_path'];

            if (!file_exists($fullPath)) {
                Response::error('File not found on disk', [], 404);
                return;
            }

            // Audit the access
            $audit = new \Services\AuditService($this->db);
            $audit->log('DOCUMENT_ACCESSED', $docId, 'admin', $this->adminId, null, ['reference' => $reference, 'doc_id' => $docId]);

            // Stream file securely
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
}






