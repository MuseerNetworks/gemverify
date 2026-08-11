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
        if ($slug === 'ipe-clearance-single') {
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
            'ipe-clearance-single' => [
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
        $pdf  = $data['pdf_base64'] ?? null;

        if (empty($pdf)) {
            return [
                'success'       => false,
                'error_message' => $data['message'] ?? 'Provider returned success but no PDF',
                'error_code'    => 'NO_PDF_IN_RESPONSE',
                'http_code'     => $clientResult['http_code'],
            ];
        }

        return [
            'success'         => true,
            'pdf_base64'      => $pdf,
            'user_data'       => $data['user_data'] ?? [],
            'message'         => $data['message']   ?? 'PDF generated successfully',
            'provider_txn_id' => null,  // TechHub slip endpoints do not return a transaction_id
            'error_message'   => null,
            'error_code'      => null,
        ];
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
    private function normaliseAsyncSubmitResult(array $clientResult): array
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

        // ticket_id is required — without it we cannot poll status
        if (empty($data['ticket_id'])) {
            return [
                'success'       => false,
                'error_message' => 'Provider did not return a ticket_id',
                'error_code'    => 'NO_TICKET_ID',
                'http_code'     => $clientResult['http_code'],
            ];
        }

        return [
            'success'         => true,
            'ticket_id'       => $data['ticket_id']       ?? null,
            'provider_txn_id' => $data['transaction_id']  ?? null,
            'provider_status' => $data['status']          ?? 'pending',
            'amount_charged'  => (float)($data['amount_charged'] ?? 0),
            'message'         => $data['message']         ?? 'Request submitted successfully',
            'error_message'   => null,
            'error_code'      => null,
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
     * Masks sensitive digits — e.g. "12345678901" → "123****901"
     */
    public function buildInputSummary(string $slug, ?string $inputMethod, array $formData): string
    {
        $parts = ["service={$slug}"];

        if (!empty($formData['nin'])) {
            $nin = preg_replace('/\D/', '', $formData['nin']);
            $parts[] = 'NIN:' . substr($nin, 0, 3) . '****' . substr($nin, -3);
        }
        if (!empty($formData['phone'])) {
            $ph = preg_replace('/\D/', '', $formData['phone']);
            $parts[] = 'phone:' . substr($ph, 0, 4) . '***' . substr($ph, -3);
        }
        if (!empty($formData['phone_number'])) {
            $ph = preg_replace('/\D/', '', $formData['phone_number']);
            $parts[] = 'phone:' . substr($ph, 0, 4) . '***' . substr($ph, -3);
        }
        if (!empty($formData['bvn'])) {
            $bvn = preg_replace('/\D/', '', $formData['bvn']);
            $parts[] = 'BVN:' . substr($bvn, 0, 3) . '****' . substr($bvn, -3);
        }
        if (!empty($formData['tracking_id'])) {
            $parts[] = 'tracking:' . $formData['tracking_id'];
        }
        if (!empty($formData['firstname'])) {
            $parts[] = 'name:' . $formData['firstname'] . ' ' . ($formData['lastname'] ?? '');
        }
        if (!empty($inputMethod)) {
            $parts[] = "via={$inputMethod}";
        }

        return implode(' | ', $parts);
    }
}
