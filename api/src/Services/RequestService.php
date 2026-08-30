<?php
namespace Services;

use PDO;
use Exception;
use InvalidArgumentException;

class RequestService {
    private PDO $db;
    private PricingService $pricingService;
    private WalletService $walletService;
    private FileStorageService $fileStorageService;
    private AuditService $auditService;
    private NotificationService $notificationService;
    
    public function __construct(
        PDO $db,
        PricingService $pricingService,
        WalletService $walletService,
        FileStorageService $fileStorageService,
        AuditService $auditService,
        NotificationService $notificationService
    ) {
        $this->db = $db;
        $this->pricingService = $pricingService;
        $this->walletService = $walletService;
        $this->fileStorageService = $fileStorageService;
        $this->auditService = $auditService;
        $this->notificationService = $notificationService;
    }

    public function submit(int $userId, string $serviceSlug, ?string $variantKey, array $formData, array $files, string $idempotencyKey): array {
        // 1 & 2. Validate service and get price
        $priceInfo = $this->pricingService->getPrice($serviceSlug, $variantKey);
        if (!$priceInfo) {
            throw new Exception("Invalid service or pricing variant.");
        }
        $price = (float) $priceInfo['price'];
        $serviceId = $priceInfo['service_id'];
        $serviceName = $priceInfo['service_name'];
        $estTime = $priceInfo['est_time'] ?? null;
        
        // 3. Validate idempotency via transactions
        $stmt = $this->db->prepare("SELECT t.id, r.reference, r.status FROM transactions t LEFT JOIN manual_requests r ON t.related_request_id = r.id WHERE t.idempotency_key = ? AND t.user_id = ?");
        $stmt->execute([$idempotencyKey, $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing && $existing['reference']) {
            return [
                'reference' => $existing['reference'],
                'status' => $existing['status'] ?? 'submitted',
                'service_name' => $serviceName,
                'message' => 'Request already processed.'
            ];
        }

        // 4. Verify PIN if provided/required (simplified here, assuming required for all forms passing it)
        if (isset($formData['pin'])) {
            $pin = $formData['pin'];
            $uStmt = $this->db->prepare("SELECT pin_hash FROM users WHERE id = ?");
            $uStmt->execute([$userId]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || !password_verify($pin, $user['pin_hash'])) {
                throw new Exception("Invalid PIN.");
            }
            unset($formData['pin']); // Remove PIN before saving
        }

        // 6. Validate form data
        $validator = $this->getFormValidator($serviceSlug, $variantKey);
        $errors = $validator($formData);
        if (!empty($errors)) {
            throw new Exception("Validation failed: " . json_encode($errors));
        }

        try {
            // 7. Begin Transaction
            $this->db->beginTransaction();

            // 5 & 8. Deduct wallet
            $transactionRef = 'TXN-' . strtoupper(uniqid());
            $txResult = $this->walletService->deductAtomically($userId, $price, "Payment for $serviceName", null, $idempotencyKey);

            // 9. Generate reference
            $requestRef = 'REQ-' . strtoupper(uniqid());

            // 10. Create manual_request record
            $reqStmt = $this->db->prepare("
                INSERT INTO manual_requests 
                (user_id, service_id, pricing_id, variant_key, reference, status, price_paid, transaction_id, submitted_at) 
                VALUES (?, ?, ?, ?, ?, 'submitted', ?, ?, NOW())
            ");
            $reqStmt->execute([$userId, $serviceId, $priceInfo['pricing_id'], $variantKey, $requestRef, $price, $txResult['id']]);
            $requestId = $this->db->lastInsertId();

            // 11. Update transaction with request_id
            $updTxn = $this->db->prepare("UPDATE transactions SET related_request_id = ? WHERE id = ?");
            $updTxn->execute([$requestId, $txResult['id']]);

            // 12. Save form data
            $formStmt = $this->db->prepare("INSERT INTO request_form_data (request_id, form_data) VALUES (?, ?)");
            $formStmt->execute([$requestId, json_encode($formData)]);

            // 13 & 14. Process files
            if (!empty($files)) {
                foreach ($files as $field => $file) {
                    if (is_array($file) && isset($file['tmp_name']) && $file['error'] === UPLOAD_ERR_OK) {
                        $docData = $this->fileStorageService->storeDocument($file, $field, $requestRef);
                        $docStmt = $this->db->prepare("
                            INSERT INTO request_documents (request_id, field_name, original_name, stored_name, mime_type, file_size, storage_path, uploaded_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $docStmt->execute([
                            $requestId,
                            $docData['field_name'],
                            $docData['original_name'],
                            $docData['stored_name'],
                            $docData['mime_type'],
                            $docData['file_size'],
                            $docData['storage_path']
                        ]);
                    }
                }
            }

            // 15. Create status history
            $histStmt = $this->db->prepare("INSERT INTO request_status_history (request_id, old_status, new_status, changed_by_type, changed_by_id, notes) VALUES (?, NULL, 'submitted', 'user', ?, 'Request submitted by user')");
            $histStmt->execute([$requestId, $userId]);

            // 16. Write audit log
            $this->auditService->log('REQUEST_CREATED', $requestId, 'user', $userId, null, null, "Request $requestRef created for service $serviceName");

            // 17. Create notification
            $this->notificationService->notify($userId, $requestId, 'request_submitted', 'Request Submitted', "Your request ($requestRef) for $serviceName has been submitted successfully.");

            // 18. Commit Transaction
            $this->db->commit();
            
            // Get balance after
            $balStmt = $this->db->prepare("SELECT balance FROM wallets WHERE user_id = ?");
            $balStmt->execute([$userId]);
            $balanceAfter = (float) $balStmt->fetchColumn();

            // 19. Return
            return [
                'reference' => $requestRef,
                'status' => 'submitted',
                'service_name' => $serviceName,
                'price_paid' => $price,
                'wallet_balance_after' => $balanceAfter,
                'estimated_time' => $estTime
            ];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function submitBulk(int $userId, string $serviceSlug, ?string $variantKey, string $idempotencyKey, ?string $pin, array $items): array {
        if (empty($items)) {
            throw new Exception("Bulk submission requires at least one item.");
        }
        
        $count = count($items);
        
        $priceInfo = $this->pricingService->getPrice($serviceSlug, $variantKey);
        if (!$priceInfo) {
            throw new Exception("Invalid service or pricing variant.");
        }
        $unitPrice = (float) $priceInfo['price'];
        $totalPrice = $unitPrice * $count;
        $serviceId = (int) $priceInfo['service_id'];
        $pricingId = (int) $priceInfo['pricing_id'];
        $serviceName = $priceInfo['service_name'];
        $estTime = $priceInfo['est_time'] ?? null;
        
        // 1. Verify PIN if provided
        if ($pin) {
            $uStmt = $this->db->prepare("SELECT pin_hash FROM users WHERE id = ?");
            $uStmt->execute([$userId]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || !$user['pin_hash'] || !password_verify($pin, $user['pin_hash'])) {
                throw new Exception("Invalid transaction PIN.");
            }
        }

        // 2. Validate idempotency
        $stmt = $this->db->prepare("SELECT t.id, r.reference, r.status FROM transactions t LEFT JOIN manual_requests r ON t.related_request_id = r.id WHERE t.idempotency_key = ? AND t.user_id = ?");
        $stmt->execute([$idempotencyKey, $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing && $existing['reference']) {
            return [
                'reference' => $existing['reference'],
                'status' => $existing['status'] ?? 'submitted',
                'service_name' => $serviceName,
                'message' => 'Request already processed.'
            ];
        }

        try {
            $this->db->beginTransaction();

            // Deduct wallet atomically using WalletService
            $txResult = $this->walletService->deductAtomically(
                $userId, 
                $totalPrice, 
                "Bulk Payment for $serviceName ($count items)", 
                null, 
                $idempotencyKey
            );

            // Generate Request Reference: GV-2026-XXXXXXXX
            $cnt = (int) $this->db->query("SELECT COUNT(*) FROM manual_requests")->fetchColumn();
            $requestRef = 'GV-' . date('Y') . '-' . str_pad((string)($cnt + 1), 8, '0', STR_PAD_LEFT);

            // Insert into manual_requests
            $reqStmt = $this->db->prepare("
                INSERT INTO manual_requests 
                (user_id, service_id, pricing_id, variant_key, reference, status, price_paid, transaction_id, submitted_at) 
                VALUES (?, ?, ?, ?, ?, 'submitted', ?, ?, NOW())
            ");
            $reqStmt->execute([$userId, $serviceId, $pricingId, $variantKey, $requestRef, $totalPrice, $txResult['id']]);
            $requestId = (int) $this->db->lastInsertId();

            // Update transaction with related_request_id
            $updTxn = $this->db->prepare("UPDATE transactions SET related_request_id = ? WHERE id = ?");
            $updTxn->execute([$requestId, $txResult['id']]);

            // Save request_form_data
            $formData = [
                'type' => 'bulk',
                'service_slug' => $serviceSlug,
                'count' => $count,
                'items' => $items
            ];
            $formStmt = $this->db->prepare("INSERT INTO request_form_data (request_id, form_data) VALUES (?, ?)");
            $formStmt->execute([$requestId, json_encode($formData)]);

            // Status history
            $histStmt = $this->db->prepare("INSERT INTO request_status_history (request_id, old_status, new_status, changed_by_type, changed_by_id, notes) VALUES (?, NULL, 'submitted', 'user', ?, 'Bulk request submitted by user')");
            $histStmt->execute([$requestId, $userId]);

            // Audit Log
            $this->auditService->log('BULK_REQUEST_CREATED', $requestId, 'user', $userId, null, null, "Bulk request $requestRef created for service $serviceName with $count items");

            // Notification
            $this->notificationService->notify($userId, $requestId, 'request_submitted', 'Bulk Request Submitted', "Your bulk request ($requestRef) for $serviceName ($count items) has been submitted successfully.");

            $this->db->commit();
            
            $balStmt = $this->db->prepare("SELECT balance FROM wallets WHERE user_id = ?");
            $balStmt->execute([$userId]);
            $balanceAfter = (float) $balStmt->fetchColumn();

            return [
                'reference' => $requestRef,
                'status' => 'submitted',
                'service_name' => $serviceName,
                'price_paid' => $totalPrice,
                'item_count' => $count,
                'wallet_balance_after' => $balanceAfter,
                'estimated_time' => $estTime
            ];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getFormValidator(string $serviceSlug, ?string $variantKey): callable {
        return function(array $data) use ($serviceSlug, $variantKey) {
            $errors = [];
            
            // Basic generic validation rules example
            if ($serviceSlug === 'nin-validation' || $serviceSlug === 'nin-validation-single' || $serviceSlug === 'nin-validation-bulk') {
                if (empty($data['nin'])) $errors['nin'] = 'NIN is required';
            } elseif ($serviceSlug === 'bvn-verification') {
                if (empty($data['bvn'])) $errors['bvn'] = 'BVN is required';
            }
            
            // Add more specific rules as needed per service
            
            return $errors;
        };
    }
}



