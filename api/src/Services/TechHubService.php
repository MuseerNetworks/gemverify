<?php
/**
 * GemVerify — TechHub Business Logic Service
 *
 * Sits between GemVerify controllers and the TechHubClient.
 * Handles all mapping, payload construction, and response normalisation.
 *
 * Responsibilities:
 *   - Map GemVerify (slug + variant + input_method) → correct TechHub endpoint
 *   - Transform GemVerify form fields → TechHub request payload
 *   - Call TechHubClient (never directly calling cURL/HTTP)
 *   - Transform TechHub response → GemVerify result structure
 *   - Determine what type of result a service returns (pdf_base64 | ticket)
 *
 * Does NOT:
 *   - Know about wallets, users, or transactions
 *   - Do any database operations
 *   - Expose TECHHUB_API_KEY or endpoint URLs to controllers
 *
 * @package Services
 */

namespace Services;

use Providers\TechHubClient;

class TechHubService
{
    private TechHubClient $client;

    // ── Endpoint Map ───────────────────────────────────────────────────────
    //
    // Sync (slip) services — keyed by [slug][variant][input_method]
    // Async services       — keyed by [slug][variant]
    //
    // 'input_method' values: 'by_nin' | 'by_phone' | 'by_demo'
    // For async, input_method is NULL — not used for endpoint selection.

    private const SYNC_ENDPOINT_MAP = [
        'nin-verification' => [
            'premium' => [
                'by_nin'   => 'nin_by_nin.php',
                'by_phone' => 'nin_by_phone_premium.php',
                'by_demo'  => 'nin_by_demo.php',
            ],
            'standard' => [
                'by_nin'   => 'nin_standard_slip.php',
                'by_phone' => 'nin_by_phone_standard.php',
                'by_demo'  => 'nin_standard_slip.php',
            ],
            'regular' => [
                'by_nin'   => 'nin_regular_slip.php',
                'by_phone' => 'nin_by_phone_regular.php',
                'by_demo'  => 'nin_regular_slip.php',
            ],
            'vnin' => [
                'by_nin'   => 'vnin_slip.php',
                'by_phone' => 'vnin_slip.php',
                'by_demo'  => 'vnin_slip.php',
            ],
        ],
        'bvn-verification' => [
            'premium' => 'bvn_premium_slip.php',
            'full'    => 'bvn_full_details_slip.php',
        ],
    ];

    private const ASYNC_ENDPOINT_MAP = [
        'self-service'        => ['Delinking Email' => 'delinking.php'],
        'personalization'     => [null              => 'personalization.php'],
        'bvn-retrieval'       => [null              => 'bvn_retrieval.php'],
        'ipe-clearance-single'=> [null              => 'ipe_clearance.php'],
    ];

    // Services that return a PDF (base64) vs services that return a ticket
    private const RESULT_TYPE_MAP = [
        'nin-verification'     => 'pdf_base64',
        'bvn-verification'     => 'pdf_base64',
        'self-service'         => 'ticket',
        'personalization'      => 'ticket',
        'bvn-retrieval'        => 'ticket',
        'ipe-clearance-single' => 'ticket',
    ];

    public function __construct()
    {
        $this->client = new TechHubClient();
    }

    // ── Public Interface ───────────────────────────────────────────────────

    /**
     * Determine the result type for a given service slug.
     * Returns 'pdf_base64' for slip services, 'ticket' for async services.
     */
    public function getResultType(string $slug): string
    {
        return self::RESULT_TYPE_MAP[$slug] ?? 'ticket';
    }

    /**
     * Check whether a slug/variant/input_method combination is supported.
     * Returns [valid => bool, error => string|null].
     */
    public function validateMapping(string $slug, ?string $variantKey, ?string $inputMethod): array
    {
        try {
            $this->resolveEndpoint($slug, $variantKey, $inputMethod);
            return ['valid' => true, 'error' => null];
        } catch (\RuntimeException $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Submit a SYNC slip request to TechHub.
     * Used for: nin-verification, bvn-verification.
     *
     * @param string $slug         GemVerify service slug
     * @param string $variantKey   Pricing variant key
     * @param string $inputMethod  'by_nin' | 'by_phone' | 'by_demo' (for NIN); NULL for BVN
     * @param array  $formData     Validated user-supplied form fields
     * @return array               Normalised result (see below)
     */
    public function submitSync(string $slug, ?string $variantKey, ?string $inputMethod, array $formData): array
    {
        $endpoint = $this->resolveEndpoint($slug, $variantKey, $inputMethod);
        $payload  = $this->buildSyncPayload($slug, $variantKey, $inputMethod, $formData);

        $result = $this->client->post($endpoint, $payload);

        return $this->normaliseSyncResult($result);
    }

    /**
     * Submit an ASYNC service request to TechHub.
     * Used for: self-service (Delinking Email), personalization, bvn-retrieval, ipe-clearance-single.
     *
     * @param string $slug       GemVerify service slug
     * @param string $variantKey Pricing variant key (or null)
     * @param array  $formData   Validated user-supplied form fields
     * @return array             Normalised result
     */
    public function submitAsync(string $slug, ?string $variantKey, array $formData): array
    {
        $endpoint = $this->resolveEndpoint($slug, $variantKey, null);
        $payload  = $this->buildAsyncPayload($slug, $formData);

        $result = $this->client->post($endpoint, $payload);

        return $this->normaliseAsyncSubmitResult($result);
    }

    /**
     * Poll TechHub for the status of an async request.
     *
     * @param string $slug     GemVerify service slug (to resolve endpoint)
     * @param string $variantKey
     * @param string $ticketId TechHub ticket_id stored at submission
     * @return array           Normalised status result
     */
    public function checkAsyncStatus(string $slug, ?string $variantKey, string $ticketId): array
    {
        $endpoint = $this->resolveEndpoint($slug, $variantKey, null);
        $result   = $this->client->get($endpoint, $ticketId);

        return $this->normaliseAsyncStatusResult($result);
    }

    // ── Endpoint Resolution ────────────────────────────────────────────────

    /**
     * Resolve the correct TechHub endpoint filename for a given service combination.
     * Throws \RuntimeException if combination is not mapped.
     */
    public function resolveEndpoint(string $slug, ?string $variantKey, ?string $inputMethod): string
    {
        // ── Sync: NIN Verification ────────────────────────────────────────
        if ($slug === 'nin-verification') {
            if (!isset(self::SYNC_ENDPOINT_MAP[$slug][$variantKey])) {
                throw new \RuntimeException("No provider endpoint for NIN verification variant: '{$variantKey}'");
            }
            $variantMap = self::SYNC_ENDPOINT_MAP[$slug][$variantKey];
            $method = $inputMethod ?? 'by_nin';
            if (!isset($variantMap[$method])) {
                throw new \RuntimeException("No provider endpoint for NIN verification variant='{$variantKey}' input_method='{$method}'");
            }
            return $variantMap[$method];
        }

        // ── Sync: BVN Verification ────────────────────────────────────────
        if ($slug === 'bvn-verification') {
            if (!isset(self::SYNC_ENDPOINT_MAP[$slug][$variantKey])) {
                throw new \RuntimeException("No provider endpoint for BVN verification variant: '{$variantKey}'");
            }
            return self::SYNC_ENDPOINT_MAP[$slug][$variantKey];
        }

        // ── Async: Self-Service (Delinking Email only) ────────────────────
        if ($slug === 'self-service') {
            if ($variantKey === 'Delinking Email') {
                return 'delinking.php';
            }
            // 'Retrieval NIN Details' is routed to manual engine — never reaches here
            throw new \RuntimeException("self-service variant '{$variantKey}' is not a provider service");
        }

        // ── Async: Personalization ────────────────────────────────────────
        if ($slug === 'personalization') {
            return 'personalization.php';
        }

        // ── Async: BVN Retrieval ──────────────────────────────────────────
        if ($slug === 'bvn-retrieval') {
            return 'bvn_retrieval.php';
        }

        // ── Async: IPE Clearance Single ───────────────────────────────────
        if ($slug === 'ipe-clearance-single' || $slug === 'ipe-clearance') {
            return 'ipe_clearance.php';
        }

        throw new \RuntimeException("No provider endpoint mapping for service slug: '{$slug}'");
    }

    // ── Payload Builders ───────────────────────────────────────────────────

    /**
     * Build the TechHub request payload for sync (slip) services.
     * api_key is injected by TechHubClient — NOT included here.
     */
    private function buildSyncPayload(string $slug, ?string $variantKey, ?string $inputMethod, array $formData): array
    {
        $method = $inputMethod ?? 'by_nin';

        if ($slug === 'nin-verification') {
            return match ($method) {
                'by_nin'   => ['nin'   => $this->sanitiseNin($formData['nin'] ?? '')],
                'by_phone' => ['phone' => $this->sanitisePhone($formData['phone'] ?? '')],
                'by_demo'  => [
                    'firstname' => strtoupper(trim($formData['firstname'] ?? '')),
                    'lastname'  => strtoupper(trim($formData['lastname']  ?? '')),
                    'dob'       => $this->formatDob($formData['dob'] ?? ''),
                    'gender'    => strtoupper(trim($formData['gender'] ?? '')),
                ],
                default => throw new \RuntimeException("Unknown input_method: {$method}"),
            };
        }

        if ($slug === 'bvn-verification') {
            return ['bvn' => $this->sanitiseBvn($formData['bvn'] ?? '')];
        }

        throw new \RuntimeException("buildSyncPayload: unsupported slug '{$slug}'");
    }

    /**
     * Build the TechHub request payload for async services.
     * api_key is injected by TechHubClient — NOT included here.
     */
    private function buildAsyncPayload(string $slug, array $formData): array
    {
        return match ($slug) {
            'self-service' => [
                'nin'   => $this->sanitiseNin($formData['nin']   ?? ''),
                'email' => trim($formData['email'] ?? ''),
            ],
            'personalization' => [
                'tracking_id' => substr(trim($formData['tracking_id'] ?? ''), 0, 50),
            ],
            'bvn-retrieval' => [
                'first_name'   => trim($formData['first_name']   ?? ''),
                'last_name'    => trim($formData['last_name']    ?? ''),
                'phone_number' => $this->sanitisePhone($formData['phone_number'] ?? ''),
            ],
            'ipe-clearance-single', 'ipe-clearance' => [
                'tracking_id' => substr(preg_replace('/[^a-zA-Z0-9]/', '', $formData['tracking_id'] ?? ''), 0, 20),
            ],
            default => throw new \RuntimeException("buildAsyncPayload: unsupported slug '{$slug}'"),
        };
    }

    // ── Response Normalisers ───────────────────────────────────────────────

    /**
     * Normalise a sync (slip) TechHub response into GemVerify's internal format.
     *
     * On success returns:
     * [
     *   'success'         => true,
     *   'pdf_base64'      => string,
     *   'user_data'       => array,
     *   'provider_txn_id' => null,   // slip responses have no transaction_id
     * ]
     */
    private function normaliseSyncResult(array $clientResult): array
    {
        if (!$clientResult['success']) {
            return [
                'success'       => false,
                'error_message' => $clientResult['error_message'] ?? 'Provider request failed',
                'error_code'    => $clientResult['error_code']    ?? null,
                'http_code'     => $clientResult['http_code'],
            ];
        }

        $data = $clientResult['data'];
        $pdf  = $this->extractPdfFromData($data);

        if (empty($pdf)) {
            error_log("[TechHub Sync Result] No PDF found in response. Keys: " . json_encode(is_array($data) ? array_keys($data) : gettype($data)));
            
            // Check if there's an explicit error status in response
            if (isset($data['status']) && strtolower((string)$data['status']) === 'error') {
                return [
                    'success'       => false,
                    'error_message' => $data['message'] ?? 'Provider returned an error',
                    'error_code'    => $data['error_code'] ?? 'PROVIDER_ERROR',
                    'http_code'     => $clientResult['http_code'],
                ];
            }

            return [
                'success'       => false,
                'error_message' => $data['message'] ?? 'Provider returned success but no PDF',
                'error_code'    => 'NO_PDF_IN_RESPONSE',
                'http_code'     => $clientResult['http_code'],
            ];
        }

        $fileName = $data['filename'] ?? $data['file_name'] ?? $data['slip_name'] ?? $data['name'] ?? null;
        if (is_string($fileName)) {
            $fileName = trim($fileName);
            if (!empty($fileName) && !str_ends_with(strtolower($fileName), '.pdf')) {
                $fileName .= '.pdf';
            }
        } else {
            $fileName = null;
        }

        return [
            'success'         => true,
            'pdf_base64'      => $pdf,
            'filename'        => $fileName,
            'user_data'       => $data['user_data'] ?? $data['data'] ?? $data,
            'message'         => $data['message']   ?? 'PDF generated successfully',
            'provider_txn_id' => $data['transaction_id'] ?? $data['reference'] ?? null,
            'error_message'   => null,
            'error_code'      => null,
        ];
    }

    /**
     * Resiliently extract PDF base64 or download URL from TechHub response data.
     */
    private function extractPdfFromData(mixed $data): ?string
    {
        if (empty($data)) {
            return null;
        }

        if (is_string($data)) {
            $trimmed = trim($data);
            if (str_starts_with($trimmed, 'JVBER') || str_starts_with($trimmed, 'data:application/pdf') || (strlen($trimmed) > 500 && base64_decode($trimmed, true) !== false)) {
                return $trimmed;
            }
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        // Direct candidate keys check
        $candidateKeys = [
            'pdf_base64', 'slip', 'pdf', 'base64', 'file', 'document', 'image',
            'slip_base64', 'nin_slip', 'bvn_slip', 'slip_data', 'base64_data',
            'base64_pdf', 'result_pdf', 'pdf_data', 'slip_url', 'download_url', 'url', 'link'
        ];

        foreach ($candidateKeys as $k) {
            if (!empty($data[$k]) && is_string($data[$k])) {
                $val = trim($data[$k]);
                if (preg_match('#^https?://#i', $val)) {
                    try {
                        $remote = @file_get_contents($val);
                        if ($remote !== false && strlen($remote) > 100) {
                            return base64_encode($remote);
                        }
                    } catch (\Throwable $e) {}
                    return $val;
                }
                if (str_starts_with($val, 'JVBER') || str_starts_with($val, 'data:application/pdf') || strlen($val) > 200) {
                    return $val;
                }
            }
        }

        // Sub-array keys
        $subKeys = ['data', 'response', 'response_data', 'result', 'payload', 'slip_details', 'user_data'];
        foreach ($subKeys as $sub) {
            if (isset($data[$sub]) && is_array($data[$sub])) {
                $found = $this->extractPdfFromData($data[$sub]);
                if ($found) {
                    return $found;
                }
            }
        }

        // Recursive fallback across all array values
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $val = trim($v);
                if (str_starts_with($val, 'JVBER') || str_starts_with($val, 'data:application/pdf;base64,')) {
                    return $val;
                }
                if (strlen($val) > 1000 && !preg_match('/\s/', $val)) {
                    return $val;
                }
            } elseif (is_array($v)) {
                $found = $this->extractPdfFromData($v);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Normalise an async submission response.
     *
     * On success returns:
     * [
     *   'success'         => true,
     *   'ticket_id'       => string,
     *   'provider_txn_id' => string,
     *   'provider_status' => 'pending',
     *   'amount_charged'  => float,   // provider's reported charge (for logging only)
     * ]
     */
    /**
     * Resiliently extract a ticket, tracking ID, or transaction reference
     * from any possible key or nested data object in the provider response.
     */
    public function extractTicketId(mixed $data): ?string
    {
        if (!is_array($data)) return null;

        $candidateKeys = [
            'ticket_id', 'ticket', 'transaction_id',
            'reference', 'ref', 'job_id', 'request_id', 'trans_id', 'order_id'
            // NOTE: 'tracking_id' and 'id' intentionally excluded:
            // - 'tracking_id' in a poll response is the user's original NIMC input, NOT TechHub's ticket ref
            // - 'id' is too generic and risks matching unrelated fields
        ];

        foreach ($candidateKeys as $k) {
            if (!empty($data[$k]) && (is_string($data[$k]) || is_numeric($data[$k]))) {
                $val = trim((string)$data[$k]);
                if ($val !== '' && strtolower($val) !== 'null') {
                    return $val;
                }
            }
        }

        // Check sub-arrays (data, payload, response)
        foreach (['data', 'payload', 'response', 'result'] as $sub) {
            if (!empty($data[$sub]) && is_array($data[$sub])) {
                $found = $this->extractTicketId($data[$sub]);
                if ($found !== null) return $found;
            }
        }

        return null;
    }

    /**
     * Normalise an async submission response into the 7-Layer Contract.
     */
    private function normaliseAsyncSubmitResult(array $clientResult): array
    {
        $httpCode = $clientResult['http_code'] ?? 0;
        $data     = $clientResult['data'] ?? [];
        $raw      = $clientResult['raw'] ?? '';
        $errCode  = $clientResult['error_code'] ?? null;
        $errMsg   = $clientResult['error_message'] ?? null;

        // 1. Check for transport-level failure (cURL errors before server response)
        if ($httpCode === 0 || str_starts_with((string)$errCode, 'CURL_ERROR_')) {
            $isTimeout = in_array($errCode, ['CURL_ERROR_28'], true);
            return [
                'success'                 => false,
                'transport_state'         => $isTimeout ? 'unknown' : 'not_sent',
                'provider_accepted'        => $isTimeout ? null : false,
                'provider_charge_state'    => $isTimeout ? 'unknown' : 'not_charged',
                'provider_processing_state'=> $isTimeout ? 'unknown' : 'failed',
                'ticket_id'               => null,
                'provider_txn_id'         => null,
                'provider_status'         => 'failed',
                'result_available'        => false,
                'safe_to_refund'          => !$isTimeout, // Timeout is NOT safe to auto-refund
                'requires_reconciliation' => $isTimeout,  // Timeout requires reconciliation
                'error_message'           => $errMsg ?: 'Connection to provider failed',
                'error_code'              => $errCode ?: 'TRANSPORT_ERROR',
                'http_code'               => $httpCode,
            ];
        }

        // 2. Extract ticket / tracking reference resiliently
        $ticketId = $this->extractTicketId($data);
        $txnId    = $data['transaction_id'] ?? $data['reference'] ?? null;
        $msg      = $data['message'] ?? $errMsg ?? '';
        $rawStatus= strtolower((string)($data['status'] ?? $clientResult['provider_status'] ?? ''));

        // Check if provider accepted the request
        $isAccepted = (
            $clientResult['success'] === true ||
            $rawStatus === 'success' ||
            $rawStatus === 'pending' ||
            $rawStatus === 'processing' ||
            $rawStatus === 'true' ||
            (!empty($data['success']) && $data['success'] === true) ||
            stripos($msg, 'submitted successfully') !== false ||
            stripos($msg, 'request received') !== false ||
            stripos($msg, 'processing') !== false ||
            !empty($ticketId)
        );

        if ($isAccepted) {
            return [
                'success'                 => true,
                'transport_state'         => 'sent',
                'provider_accepted'        => true,
                'provider_charge_state'    => 'charged',
                'provider_processing_state'=> 'processing',
                'ticket_id'               => $ticketId,
                'provider_txn_id'         => $txnId,
                'provider_status'         => 'pending',
                'amount_charged'          => (float)($data['amount_charged'] ?? 0),
                'message'                 => $msg ?: 'Request submitted successfully',
                'result_available'        => false,
                'safe_to_refund'          => false, // NEVER refund when provider accepted
                'requires_reconciliation' => empty($ticketId), // If accepted but ticket unparsed, flag for reconcile
                'error_message'           => null,
                'error_code'              => null,
                'http_code'               => $httpCode,
            ];
        }

        // 3. Provider explicitly rejected the request before transaction creation (e.g. Insufficient Balance, Invalid Input)
        $isPreChargeReject = (
            $httpCode === 400 ||
            $httpCode === 422 ||
            stripos($msg, 'insufficient balance') !== false ||
            stripos($msg, 'invalid') !== false ||
            stripos($msg, 'not found') !== false ||
            stripos($msg, 'unauthorized') !== false
        );

        return [
            'success'                 => false,
            'transport_state'         => 'sent',
            'provider_accepted'        => false,
            'provider_charge_state'    => $isPreChargeReject ? 'not_charged' : 'unknown',
            'provider_processing_state'=> 'failed',
            'ticket_id'               => null,
            'provider_txn_id'         => null,
            'provider_status'         => 'failed',
            'result_available'        => false,
            'safe_to_refund'          => $isPreChargeReject,
            'requires_reconciliation' => !$isPreChargeReject,
            'error_message'           => $msg ?: 'Provider rejected request',
            'error_code'              => $errCode ?: ($data['error_code'] ?? 'PROVIDER_REJECT'),
            'http_code'               => $httpCode,
        ];
    }

    /**
     * Normalise an async status-poll response.
     *
     * Returns:
     * [
     *   'success'         => bool,
     *   'provider_status' => 'pending'|'success'|'failed',
     *   'is_complete'     => bool,    // true when provider_status is success or failed
     *   'is_failed'       => bool,
     *   'result_data'     => array,   // full provider response for storage
     *   'response_note'   => string|null,  // admin's note from provider
     * ]
     */
    private function normaliseAsyncStatusResult(array $clientResult): array
    {
        if (!$clientResult['success']) {
            return [
                'success'         => false,
                'provider_status' => null,
                'is_complete'     => false,
                'is_failed'       => false,
                'result_data'     => [],
                'response_note'   => null,
                'error_message'   => $clientResult['error_message'] ?? 'Status check failed',
                'error_code'      => $clientResult['error_code']    ?? null,
            ];
        }

        $data           = $clientResult['data'];
        $providerStatus = strtolower((string)($data['status'] ?? 'pending'));
        $isSuccess      = $providerStatus === 'success';
        $isFailed       = $providerStatus === 'failed';

        return [
            'success'         => true,
            'provider_status' => $providerStatus,
            'is_complete'     => $isSuccess || $isFailed,
            'is_failed'       => $isFailed,
            'result_data'     => $data,
            'response_note'   => $data['response'] ?? $data['note'] ?? null,
            'error_message'   => null,
            'error_code'      => null,
        ];
    }

    // ── Input Sanitisers ───────────────────────────────────────────────────
    // Strip non-digits, enforce correct lengths. No business rules — just format.

    private function sanitiseNin(string $nin): string
    {
        return preg_replace('/\D/', '', $nin);
    }

    private function sanitiseBvn(string $bvn): string
    {
        return preg_replace('/\D/', '', $bvn);
    }

    private function sanitisePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }

    /**
     * Ensure DOB is in TechHub's required format: DD-MM-YYYY.
     * Accepts: DD-MM-YYYY (passthrough), YYYY-MM-DD (convert), DD/MM/YYYY (convert).
     */
    private function formatDob(string $dob): string
    {
        $dob = trim($dob);

        // Already in DD-MM-YYYY
        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $dob)) {
            return $dob;
        }

        // YYYY-MM-DD → DD-MM-YYYY
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dob, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        // DD/MM/YYYY → DD-MM-YYYY
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dob, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        // Return as-is and let TechHub reject if wrong
        return $dob;
    }

    /**
     * Generate a masked summary of input data for audit logging.
     * Stores full identifiers for all GemVerify service types (no masking).
     */
    public function buildInputSummary(string $slug, ?string $inputMethod, array $formData): string
    {
        $parts = ["service={$slug}"];

        // NIN — used by NIN Verification, Enrollment, Modification, Validation, Self-Service etc.
        if (!empty($formData['nin'])) {
            $parts[] = 'NIN:' . preg_replace('/\D/', '', $formData['nin']);
        }
        // BVN — used by BVN Verification, Retrieval, Modification, Risk etc.
        if (!empty($formData['bvn'])) {
            $parts[] = 'BVN:' . preg_replace('/\D/', '', $formData['bvn']);
        }
        // Phone numbers
        if (!empty($formData['phone'])) {
            $parts[] = 'Phone:' . preg_replace('/\D/', '', $formData['phone']);
        }
        if (!empty($formData['phone_number'])) {
            $parts[] = 'Phone:' . preg_replace('/\D/', '', $formData['phone_number']);
        }
        // Tracking ID — used by IPE Clearance, IPE Modification, async services
        if (!empty($formData['tracking_id'])) {
            $parts[] = 'Tracking:' . $formData['tracking_id'];
        }
        // Email — used by Self-Service Delinking
        if (!empty($formData['email'])) {
            $parts[] = 'Email:' . $formData['email'];
        }
        // Name fields — used by Enrollment, Modification, Attestation
        if (!empty($formData['first_name'])) {
            $parts[] = 'Name:' . $formData['first_name'] . ' ' . ($formData['last_name'] ?? '');
        } elseif (!empty($formData['firstname'])) {
            $parts[] = 'Name:' . $formData['firstname'] . ' ' . ($formData['lastname'] ?? '');
        }
        // TIN — used by TIN Registration
        if (!empty($formData['tin'])) {
            $parts[] = 'TIN:' . $formData['tin'];
        }
        // JAMB Reg No — used by JAMB services
        if (!empty($formData['jamb_reg_no'])) {
            $parts[] = 'JAMB Reg:' . $formData['jamb_reg_no'];
        }
        if (!empty($formData['reg_number'])) {
            $parts[] = 'Reg No:' . $formData['reg_number'];
        }
        // CAC RC Number — used by CAC services
        if (!empty($formData['rc_number'])) {
            $parts[] = 'RC:' . $formData['rc_number'];
        }
        if (!empty($formData['business_name'])) {
            $parts[] = 'Biz:' . $formData['business_name'];
        }
        // Enrollment ID — used by BVN Non-Appearance, BVN License
        if (!empty($formData['enrollment_id'])) {
            $parts[] = 'Enrollment:' . $formData['enrollment_id'];
        }
        // Date of Birth — used by DOB Correction
        if (!empty($formData['dob'])) {
            $parts[] = 'DOB:' . $formData['dob'];
        }
        // Input method (by_nin, by_phone, etc.)
        if (!empty($inputMethod)) {
            $parts[] = "via={$inputMethod}";
        }

        return implode(' | ', $parts);
    }
}

