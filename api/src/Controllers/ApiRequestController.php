<?php
/**
 * GemVerify — API Request Controller
 *
 * Handles user-facing submissions for all TechHub API-backed services.
 * Also handles the mixed-mode routing for self-service variants.
 *
 * Endpoints (registered in routes/user.php):
 *   POST /api-services/submit
 *
 * Supported services (sync):
 *   - nin-verification  (premium, standard, regular, vnin) × (by_nin, by_phone, by_demo)
 *   - bvn-verification  (premium, full)
 *
 * Supported services (async):
 *   - self-service      (Delinking Email → TechHub; Retrieval NIN Details → manual engine)
 *   - personalization
 *   - bvn-retrieval
 *   - ipe-clearance-single
 *
 * @package Controllers
 */

namespace Controllers;

use Core\Database;
use Helpers\Response;
use Services\PricingService;
use Services\WalletService;
use Services\AuditService;
use Services\NotificationService;
use Services\RequestService;
use Services\FileStorageService;
use Services\LocalStorageDriver;
use Services\TechHubService;
use Services\InsufficientBalanceException;
use Exceptions\InsufficientBalanceException as LegacyInsufficientBalanceException;
use Exceptions\DuplicateTransactionException;
use PDO;
use Exception;

class ApiRequestController
{
    private PDO $db;
    private PricingService $pricingService;
    private WalletService $walletService;
    private AuditService $auditService;
    private NotificationService $notificationService;
    private TechHubService $techHubService;
    private int $userId;

    // Variants that must be routed to the manual engine regardless of service is_manual flag
    private const MANUAL_VARIANT_OVERRIDES = [
        'self-service' => ['Retrieval NIN Details'],
    ];

    // Required fields per service + input_method combination
    private const REQUIRED_FIELDS = [
        'nin-verification' => [
            'by_nin'   => ['nin'],
            'by_phone' => ['phone'],
            'by_demo'  => ['firstname', 'lastname', 'dob', 'gender'],
        ],
        'bvn-verification' => [
            null => ['bvn'],
        ],
        'self-service' => [
            'Delinking Email'        => ['nin', 'email'],
            'Retrieval NIN Details'  => [],  // handled by manual engine — it has its own validation
        ],
        'personalization' => [
            null => ['tracking_id'],
        ],
        'bvn-retrieval' => [
            null => ['first_name', 'last_name', 'phone_number'],
        ],
        'ipe-clearance-single' => [
            null => ['tracking_id'],
        ],
    ];

    public function __construct()
    {
        $this->db                  = db();
        $this->pricingService      = new PricingService($this->db);
        $this->walletService       = new WalletService($this->db);
        $this->auditService        = new AuditService($this->db);
        $this->notificationService = new NotificationService($this->db);
        $this->techHubService      = new TechHubService();
        $this->userId              = \Middleware\AuthMiddleware::getUserId();
    }

    // ── Public Endpoint ────────────────────────────────────────────────────

    /**
     * POST /api-services/submit
     *
     * Accepts JSON body:
     * {
     *   "service_slug":    "nin-verification",
     *   "variant_key":     "premium",
     *   "input_method":    "by_nin",
     *   "idempotency_key": "unique-client-key",
     *   "pin":             "1234",           (optional)
     *   "nin":             "12345678901",    (for by_nin)
     *   "phone":           "08012345678",    (for by_phone)
     *   "firstname":       "JOHN",           (for by_demo)
     *   "lastname":        "DOE",            (for by_demo)
     *   "dob":             "01-01-1990",     (for by_demo)
     *   "gender":          "M",              (for by_demo)
     *   "bvn":             "12345678901",    (for bvn-verification)
     *   "tracking_id":     "TRK123456",      (for personalization / ipe-clearance)
     *   "first_name":      "John",           (for bvn-retrieval)
     *   "last_name":       "Doe",            (for bvn-retrieval)
     *   "phone_number":    "08012345678",    (for bvn-retrieval)
     *   "email":           "user@email.com", (for self-service Delinking Email)
     * }
     */
    public function submit(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (isset($input['form_data']) && is_array($input['form_data'])) {
            $input = array_merge($input['form_data'], $input);
            unset($input['form_data']);
        }

        $serviceSlug    = trim($input['service_slug']  ?? '');
        $variantKey     = isset($input['variant_key']) ? trim($input['variant_key']) : null;
        $inputMethod    = isset($input['input_method']) ? trim($input['input_method']) : null;
        $idempotencyKey = trim($input['idempotency_key'] ?? '');
        $pin            = $input['pin'] ?? null;

        // Auto-detect input_method if not explicitly passed
        if (empty($inputMethod)) {
            if (!empty($input['nin'])) {
                $inputMethod = 'by_nin';
            } elseif (!empty($input['phone'])) {
                $inputMethod = 'by_phone';
            } elseif (!empty($input['firstname']) || !empty($input['first_name'])) {
                $inputMethod = 'by_demo';
            }
        }

        // Strip meta-fields from formData passed to provider
        $formData = $input;
        foreach (['service_slug','variant_key','input_method','idempotency_key','pin'] as $k) {
            unset($formData[$k]);
        }

        // ── 1. Basic input validation ──────────────────────────────────────
        if (empty($serviceSlug)) {
            Response::error('service_slug is required', [], 400);
            return;
        }
        if (empty($idempotencyKey)) {
            Response::error('idempotency_key is required', [], 400);
            return;
        }

        // ── 2. Mixed-mode variant routing ─────────────────────────────────
        // Certain variants on API-based services are silently processed by
        // the manual engine (Option B decision for 'Retrieval NIN Details').
        if ($this->isManualVariantOverride($serviceSlug, $variantKey)) {
            $this->delegateToManualEngine($serviceSlug, $variantKey, $formData, $idempotencyKey, $pin);
            return;
        }

        // ── 3. Validate input_method for NIN ──────────────────────────────
        if ($serviceSlug === 'nin-verification') {
            $validMethods = ['by_nin', 'by_phone', 'by_demo'];
            if (!in_array($inputMethod, $validMethods, true)) {
                Response::error('input_method must be one of: by_nin, by_phone, by_demo', [], 400);
                return;
            }
        }

        // ── 4. Validate required form fields ──────────────────────────────
        $fieldErrors = $this->validateRequiredFields($serviceSlug, $variantKey, $inputMethod, $formData);
        if (!empty($fieldErrors)) {
            Response::error('Validation failed: ' . implode(', ', $fieldErrors), [], 400);
            return;
        }

        // ── 5. TechHub endpoint validation ────────────────────────────────
        $mappingCheck = $this->techHubService->validateMapping($serviceSlug, $variantKey, $inputMethod);
        if (!$mappingCheck['valid']) {
            Response::error('Service configuration error: ' . $mappingCheck['error'], [], 422);
            return;
        }

        // ── 6. Pricing check ──────────────────────────────────────────────
        $priceInfo = $this->pricingService->getPrice($serviceSlug, $variantKey);
        if (!$priceInfo || (float)$priceInfo['price'] <= 0) {
            Response::error('This service is not available — price is not configured.', [], 422);
            return;
        }
        $price     = (float)$priceInfo['price'];
        $serviceId = (int)$priceInfo['service_id'];
        $pricingId = (int)$priceInfo['pricing_id'];

        // ── 7. Balance check ──────────────────────────────────────────────
        if (!$this->walletService->hasEnoughBalance($this->userId, $price)) {
            Response::error('Insufficient wallet balance.', ['required' => $price], 402);
            return;
        }

        // ── 8. Idempotency check ──────────────────────────────────────────
        $existingTx = $this->findExistingApiTransaction($idempotencyKey);
        if ($existingTx) {
            Response::success($this->formatExistingResult($existingTx));
            return;
        }

        // ── 9. PIN verification (if provided) ─────────────────────────────
        if ($pin !== null) {
            if (!$this->verifyPin($this->userId, (string)$pin)) {
                Response::error('Invalid PIN.', [], 401);
                return;
            }
        }

        // ── 10. Determine result type ──────────────────────────────────────
        $resultType = $this->techHubService->getResultType($serviceSlug);

        // ── 11. Begin DB transaction ───────────────────────────────────────
        try {
            $this->db->beginTransaction();

            // ── 12. Deduct wallet ──────────────────────────────────────────
            $serviceName = $priceInfo['service_name'] ?? $serviceSlug;
            $txResult    = $this->walletService->deductAtomically(
                $this->userId,
                $price,
                "Payment for {$serviceName}" . ($variantKey ? " ({$variantKey})" : ''),
                null,
                $idempotencyKey
            );

            // ── 13. Create api_transactions row (pending) ──────────────────
            $gvReference   = $this->generateGvReference();
            $inputSummary  = $this->techHubService->buildInputSummary($serviceSlug, $inputMethod, $formData);

            $endpoint = $this->techHubService->resolveEndpoint($serviceSlug, $variantKey, $inputMethod);

            $insertStmt = $this->db->prepare("
                INSERT INTO api_transactions
                (gv_reference, user_id, service_id, pricing_id, variant_key, transaction_id,
                 input_method, input_summary, provider, provider_endpoint, gv_status,
                 result_type, idempotency_key, submitted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'techhub', ?, 'pending', ?, ?, NOW())
            ");
            $insertStmt->execute([
                $gvReference,
                $this->userId,
                $serviceId,
                $pricingId,
                $variantKey,
                $txResult['id'],
                $inputMethod,
                $inputSummary,
                $endpoint,
                $resultType,
                $idempotencyKey,
            ]);
            $apiTxId = (int)$this->db->lastInsertId();

            // ── 14. Update transaction with api_tx reference ───────────────
            $this->db->prepare("UPDATE transactions SET related_request_id = ? WHERE id = ?")
                     ->execute([$apiTxId, $txResult['id']]);

            // ── 15. Call TechHub ───────────────────────────────────────────
            if ($resultType === 'pdf_base64') {
                $providerResult = $this->techHubService->submitSync(
                    $serviceSlug, $variantKey, $inputMethod, $formData
                );
            } else {
                $providerResult = $this->techHubService->submitAsync(
                    $serviceSlug, $variantKey, $formData
                );
            }

            // ── 16. Handle provider hard failure (before any processing) ───
            // A hard failure means TechHub never accepted the request —
            // we rollback so the user is NOT charged.
            if (!$providerResult['success'] && $this->isHardFailure($providerResult)) {
                $this->db->rollBack();
                $this->logProviderError($serviceSlug, $gvReference, $providerResult);
                Response::error(
                    'Provider unavailable — you have not been charged. Please try again.',
                    ['error_code' => $providerResult['error_code'] ?? 'PROVIDER_ERROR'],
                    502
                );
                return;
            }

            // ── 17. Update api_transactions with provider response ─────────
            if ($providerResult['success']) {
                if ($resultType === 'pdf_base64') {
                    $this->db->prepare("
                        UPDATE api_transactions
                        SET gv_status = 'completed',
                            provider_status = 'success',
                            result_data = ?,
                            provider_txn_id = ?,
                            provider_responded_at = NOW(),
                            completed_at = NOW()
                        WHERE id = ?
                    ")->execute([
                        $providerResult['pdf_base64'],
                        $providerResult['provider_txn_id'],
                        $apiTxId,
                    ]);
                } else {
                    // Async: store ticket_id, stay 'processing'
                    $this->db->prepare("
                        UPDATE api_transactions
                        SET gv_status = 'processing',
                            provider_status = ?,
                            provider_ticket_id = ?,
                            provider_txn_id = ?,
                            provider_responded_at = NOW()
                        WHERE id = ?
                    ")->execute([
                        $providerResult['provider_status'] ?? 'pending',
                        $providerResult['ticket_id'],
                        $providerResult['provider_txn_id'],
                        $apiTxId,
                    ]);
                }
            } else {
                // Soft failure: TechHub returned an error but request was received
                // (e.g. NIN not found). User is charged. Record the error.
                $this->db->prepare("
                    UPDATE api_transactions
                    SET gv_status = 'failed',
                        provider_status = 'failed',
                        error_code = ?,
                        error_message = ?,
                        provider_responded_at = NOW()
                    WHERE id = ?
                ")->execute([
                    $providerResult['error_code']    ?? 'PROVIDER_SOFT_FAIL',
                    $providerResult['error_message'] ?? 'Provider returned an error',
                    $apiTxId,
                ]);
            }

            // ── 18. Commit ─────────────────────────────────────────────────
            $this->db->commit();

            // ── 19. Post-commit: notifications + audit ─────────────────────
            if ($providerResult['success']) {
                $notifMsg = $resultType === 'pdf_base64'
                    ? "Your {$serviceName} request ({$gvReference}) completed successfully. PDF ready."
                    : "Your {$serviceName} request ({$gvReference}) has been submitted and is being processed.";

                $this->notificationService->notify(
                    $this->userId,
                    null,
                    'api_request_' . ($resultType === 'pdf_base64' ? 'completed' : 'submitted'),
                    'Request ' . ($resultType === 'pdf_base64' ? 'Completed' : 'Submitted'),
                    $notifMsg
                );
            }

            $this->auditService->log(
                'API_REQUEST_' . strtoupper($providerResult['success'] ? ($resultType === 'pdf_base64' ? 'COMPLETED' : 'PROCESSING') : 'FAILED'),
                $apiTxId,
                'user',
                $this->userId,
                null,
                null,
                "API request {$gvReference} for {$serviceSlug}/{$variantKey} — " . ($providerResult['success'] ? 'OK' : $providerResult['error_message'])
            );

            // ── 20. Return response ────────────────────────────────────────
            $balanceAfter = $this->walletService->getBalance($this->userId);

            if ($providerResult['success']) {
                if ($resultType === 'pdf_base64') {
                    Response::success([
                        'gv_reference'        => $gvReference,
                        'status'              => 'completed',
                        'service_name'        => $serviceName,
                        'variant'             => $variantKey,
                        'price_paid'          => $price,
                        'wallet_balance_after'=> $balanceAfter,
                        'pdf_base64'          => $providerResult['pdf_base64'],
                        'message'             => 'PDF generated successfully.',
                    ]);
                } else {
                    Response::success([
                        'gv_reference'        => $gvReference,
                        'status'              => 'processing',
                        'service_name'        => $serviceName,
                        'variant'             => $variantKey,
                        'price_paid'          => $price,
                        'wallet_balance_after'=> $balanceAfter,
                        'ticket_id'           => $providerResult['ticket_id'],
                        'message'             => 'Request submitted. Use the status endpoint to check progress.',
                    ]);
                }
            } else {
                // Soft failure — user was charged, request failed
                Response::error(
                    $providerResult['error_message'] ?? 'Request failed.',
                    [
                        'gv_reference' => $gvReference,
                        'error_code'   => $providerResult['error_code'] ?? null,
                        'price_paid'   => $price,
                        'note'         => 'A refund review has been flagged for this transaction.',
                    ],
                    422
                );
            }

        } catch (InsufficientBalanceException | LegacyInsufficientBalanceException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::error('Insufficient wallet balance.', [], 402);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::error($e->getMessage(), [], 500);
        }
    }

    // ── Private Helpers ────────────────────────────────────────────────────

    /**
     * Check if this variant should be silently handled by the manual engine
     * instead of TechHub (Option B — Retrieval NIN Details).
     */
    private function isManualVariantOverride(string $slug, ?string $variantKey): bool
    {
        if (!isset(self::MANUAL_VARIANT_OVERRIDES[$slug])) {
            return false;
        }
        return in_array($variantKey, self::MANUAL_VARIANT_OVERRIDES[$slug], true);
    }

    /**
     * Delegate to the existing manual RequestService.
     * This is the Option B path for 'Retrieval NIN Details'.
     */
    private function delegateToManualEngine(
        string $serviceSlug,
        ?string $variantKey,
        array $formData,
        string $idempotencyKey,
        ?string $pin
    ): void {
        try {
            // Build the services the manual engine needs
            $driver  = new LocalStorageDriver(STORAGE_BASE_PATH);
            $storage = new FileStorageService($driver);
            $requestService = new RequestService(
                $this->db,
                $this->pricingService,
                $this->walletService,
                $storage,
                $this->auditService,
                $this->notificationService
            );

            // PIN goes into formData for the manual engine
            if ($pin !== null) {
                $formData['pin'] = $pin;
            }

            $result = $requestService->submit(
                $this->userId,
                $serviceSlug,
                $variantKey,
                $formData,
                [],  // no file uploads through this path
                $idempotencyKey
            );
            Response::success($result);
        } catch (\Exceptions\InsufficientBalanceException $e) {
            Response::error('Insufficient wallet balance.', [], 402);
        } catch (\Exceptions\DuplicateTransactionException $e) {
            Response::success(['success' => true, 'data' => $e->getExistingData()]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    /**
     * Validate required fields for a given service/variant/input_method.
     * Returns array of error strings (empty = valid).
     */
    private function validateRequiredFields(
        string $slug,
        ?string $variantKey,
        ?string $inputMethod,
        array $formData
    ): array {
        $errors   = [];
        $lookupKey = $variantKey ?? $inputMethod ?? null;

        $serviceRules = self::REQUIRED_FIELDS[$slug] ?? null;
        if ($serviceRules === null) {
            return ["Service '{$slug}' is not recognised"];
        }

        // For NIN verification, look up by input_method
        if ($slug === 'nin-verification') {
            $rules = $serviceRules[$inputMethod] ?? [];
        } elseif ($slug === 'self-service') {
            $rules = $serviceRules[$variantKey] ?? [];
        } else {
            // Everything else has only a null key
            $rules = $serviceRules[null] ?? array_values($serviceRules)[0] ?? [];
        }

        foreach ($rules as $field) {
            if (empty($formData[$field])) {
                $errors[] = "'{$field}' is required";
            }
        }

        return $errors;
    }

    /**
     * Check for an existing api_transaction by idempotency_key.
     */
    private function findExistingApiTransaction(string $idempotencyKey): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, gv_reference, gv_status, result_type, result_data,
                   provider_ticket_id, provider_txn_id, error_message
            FROM api_transactions
            WHERE idempotency_key = ? AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$idempotencyKey, $this->userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Format an existing api_transaction row as a response for idempotency returns.
     */
    private function formatExistingResult(array $tx): array
    {
        $base = [
            'gv_reference' => $tx['gv_reference'],
            'status'       => $tx['gv_status'],
            'message'      => 'Request already processed (idempotency).',
        ];
        if ($tx['result_type'] === 'pdf_base64' && !empty($tx['result_data'])) {
            $base['pdf_base64'] = $tx['result_data'];
        }
        if (!empty($tx['provider_ticket_id'])) {
            $base['ticket_id'] = $tx['provider_ticket_id'];
        }
        return $base;
    }

    /**
     * A "hard failure" is when TechHub never processed the request at all —
     * e.g. connection refused, timeout, HTTP 5xx from provider.
     * In these cases we rollback and do NOT charge the user.
     *
     * A "soft failure" is when TechHub received the request but rejected the
     * input (e.g. NIN not found, invalid BVN) — these are business errors
     * where the user IS charged (provider consumed the lookup).
     */
    private function isHardFailure(array $providerResult): bool
    {
        $hardCodes = [
            'CURL_ERROR_6',   // Could not resolve host
            'CURL_ERROR_7',   // Failed to connect
            'CURL_ERROR_28',  // Operation timed out
            'PROVIDER_NOT_CONFIGURED',
            'MALFORMED_RESPONSE',
            'HTTP_500',
            'HTTP_502',
            'HTTP_503',
            'HTTP_504',
        ];
        $code = $providerResult['error_code'] ?? '';
        // Any cURL error is a hard failure
        if (str_starts_with($code, 'CURL_ERROR_')) return true;
        return in_array($code, $hardCodes, true);
    }

    /**
     * Verify user PIN.
     */
    private function verifyPin(int $userId, string $pin): bool
    {
        $stmt = $this->db->prepare("SELECT pin_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || empty($user['pin_hash'])) return false;
        return password_verify($pin, $user['pin_hash']);
    }

    /**
     * Generate a GemVerify API reference: GVA-YYYYMMDD-XXXXXXXX
     */
    private function generateGvReference(): string
    {
        return 'GVA-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    /**
     * Log a provider error without throwing (called before rollback).
     */
    private function logProviderError(string $slug, string $ref, array $result): void
    {
        try {
            $this->auditService->log(
                'API_PROVIDER_HARD_FAIL',
                0,
                'user',
                $this->userId,
                null,
                null,
                "Hard failure for {$slug} ref={$ref}: [{$result['error_code']}] {$result['error_message']}"
            );
        } catch (Exception $e) {
            // Don't let logging break the error response
        }
    }
}
