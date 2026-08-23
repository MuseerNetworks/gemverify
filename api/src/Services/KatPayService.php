<?php
namespace Services;

use RuntimeException;

/**
 * KatPayService
 *
 * Handles all communication with the KatPay payment gateway API.
 * All credentials are loaded from PHP constants (which come from .env via app.php).
 * This class MUST NEVER be instantiated in frontend-facing code.
 */
class KatPayService {

    private string $apiKey;
    private string $secretKey;
    private string $merchantId;
    private array $bankCodes;
    private string $webhookSecret;
    private string $baseUrl;

    public function __construct() {
        $this->apiKey        = KATPAY_API_KEY;
        $this->secretKey     = KATPAY_SECRET_KEY;
        $this->merchantId    = KATPAY_MERCHANT_ID;
        $this->webhookSecret = KATPAY_WEBHOOK_SECRET;
        $this->baseUrl       = KATPAY_BASE_URL;
        
        $rawCodes = KATPAY_BANK_CODES;
        $this->bankCodes     = !empty($rawCodes) ? array_map('trim', explode(',', $rawCodes)) : ['PALMPAY'];
    }



    // ──────────────────────────────────────────────────────────────────────────
    // PUBLIC METHODS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Initiate a Pay-with-Transfer payment order.
     *
     * @param array $params {
     *   amount, customer_name, customer_email, customer_phone,
     *   merchant_reference, description, callback_url,
     *   success_url, metadata, expires_in
     * }
     * @return array KatPay response data array
     * @throws RuntimeException on HTTP or API errors
     */
    public function initiateTransferPayment(array $params): array {
        $this->assertConfigured();

        $body = [
            'amount'             => (float) $params['amount'],
            'customer_name'      => $params['customer_name'],
            'customer_email'     => $params['customer_email'],
            'callback_url'       => $params['callback_url'],
            'merchant_reference' => $params['merchant_reference'],
            'currency'           => 'NGN',
            'expires_in'         => (int) ($params['expires_in'] ?? 30),
        ];

        if (!empty($params['customer_phone'])) {
            $body['customer_phone'] = $params['customer_phone'];
        }
        if (!empty($params['description'])) {
            $body['description'] = substr($params['description'], 0, 500);
        }
        if (!empty($params['success_url'])) {
            $body['success_url'] = $params['success_url'];
        }
        if (!empty($params['metadata']) && is_array($params['metadata'])) {
            $body['metadata'] = $params['metadata'];
        }

        $response = $this->post('/transfer-payments', $body);

        if (empty($response['success']) || empty($response['data'])) {
            throw new RuntimeException(
                'KatPay initiation failed: ' . ($response['message'] ?? 'Unknown error')
            );
        }

        return $response['data'];
    }

    /**
     * Create a permanent static virtual bank account for a user.
     * This is called once per user at registration — the account never changes.
     *
     * @param array $params {
     *   customer_name, customer_email, customer_phone,
     *   merchant_reference  (unique per user e.g. "GVU_42"),
     *   callback_url,
     *   metadata            (optional)
     * }
     * @return array KatPay response data containing account_number, bank_name, etc.
     * @throws RuntimeException on HTTP or API errors
     */
    public function createVirtualAccount(array $params): array {
        $this->assertConfigured();

        $body = [
            'name'        => $params['customer_name'],
            'email'       => $params['customer_email'],
            'phoneNumber' => $params['customer_phone'] ?? '',
            'bankCode'    => $this->bankCodes,
            'merchantID'  => $this->merchantId,
        ];

        // Undocumented but safe to pass for backward compatibility or future use
        if (!empty($params['merchant_reference'])) {
            $body['merchant_reference'] = $params['merchant_reference'];
        }
        if (!empty($params['metadata']) && is_array($params['metadata'])) {
            $body['metadata'] = $params['metadata'];
        }

        $response = $this->post('/virtual-accounts', $body);

        // Success path — account was freshly created
        if (!empty($response['success']) && !empty($response['data'])) {
            return $response['data'];
        }

        // ── Duplicate / already-exists recovery ──────────────────────────────
        // When a user is deleted from GemVerify but their KatPay account still
        // exists, re-registration triggers a duplicate error. Most payment APIs
        // include the existing account in the error response body, or expose a
        // lookup endpoint. Try three recovery strategies in order.

        $errorMsg = strtolower($response['message'] ?? '');
        $isDuplicate = str_contains($errorMsg, 'exist')
                    || str_contains($errorMsg, 'duplicate')
                    || str_contains($errorMsg, 'already')
                    || str_contains($errorMsg, 'found');

        if ($isDuplicate) {
            // Strategy 1: KatPay included the existing account in the error body
            if (!empty($response['data']) && is_array($response['data'])) {
                error_log('[KatPay] Duplicate VA detected — recovered from error response data for: ' . ($params['customer_email'] ?? ''));
                return $response['data'];
            }

            // Strategy 2: Fetch by customer email
            try {
                $existing = $this->getVirtualAccountByEmail($params['customer_email']);
                error_log('[KatPay] Duplicate VA detected — recovered via email lookup for: ' . ($params['customer_email'] ?? ''));
                return $existing;
            } catch (RuntimeException) {
                // Email lookup failed or not supported — try next strategy
            }

            // Strategy 3: Fetch by merchant reference (works if user_id is unchanged)
            if (!empty($params['merchant_reference'])) {
                try {
                    $existing = $this->getVirtualAccountByMerchantRef($params['merchant_reference']);
                    error_log('[KatPay] Duplicate VA detected — recovered via merchant_ref lookup for: ' . $params['merchant_reference']);
                    return $existing;
                } catch (RuntimeException) {
                    // Merchant ref lookup also failed
                }
            }
        }

        // All recovery strategies exhausted — throw the original error
        throw new RuntimeException(
            'KatPay virtual account creation failed: ' . ($response['message'] ?? 'Unknown error')
        );
    }

    /**
     * Look up an existing virtual account by customer email.
     * Used to recover when a user is deleted and re-registered with the same email.
     *
     * @throws RuntimeException if not found or API does not support this lookup
     */
    public function getVirtualAccountByEmail(string $email): array {
        $this->assertConfigured();

        // Try common KatPay lookup patterns
        $response = $this->get('/virtual-accounts?email=' . urlencode($email));

        if (!empty($response['success']) && !empty($response['data'])) {
            // Response may be paginated — return the first matching record
            $data = $response['data'];
            if (isset($data[0])) {
                return $data[0];
            }
            return $data;
        }

        throw new RuntimeException('KatPay VA email lookup failed: ' . ($response['message'] ?? 'Not found'));
    }

    /**
     * Look up an existing virtual account by our merchant reference (e.g. "GVU_42").
     * Useful when a user's GemVerify record was deleted but KatPay still holds the account.
     *
     * @throws RuntimeException if not found or API does not support this lookup
     */
    public function getVirtualAccountByMerchantRef(string $merchantRef): array {
        $this->assertConfigured();

        // Try the most common path patterns — KatPay may use any of these
        $paths = [
            '/virtual-accounts/' . urlencode($merchantRef),
            '/virtual-accounts/merchant/' . urlencode($merchantRef),
            '/virtual-accounts?merchant_reference=' . urlencode($merchantRef),
        ];

        foreach ($paths as $path) {
            try {
                $response = $this->get($path);
                if (!empty($response['success']) && !empty($response['data'])) {
                    $data = $response['data'];
                    return isset($data[0]) ? $data[0] : $data;
                }
            } catch (RuntimeException) {
                continue;
            }
        }

        throw new RuntimeException('KatPay VA merchant_ref lookup failed for: ' . $merchantRef);
    }

    /**
     * Verify a transfer payment by our own merchant_reference.
     * This is the authoritative check — must be called after every callback.
     *
     * @param string $merchantReference Our reference e.g. "WTU_42_1711000000"
     * @return array KatPay verification data {status, amount, amount_received, ...}
     * @throws RuntimeException on HTTP or API errors
     */
    public function verifyByMerchantRef(string $merchantReference): array {
        $this->assertConfigured();

        $response = $this->get('/transfer-payments/verify/' . urlencode($merchantReference));

        if (empty($response['success']) || empty($response['data'])) {
            throw new RuntimeException(
                'KatPay verification failed: ' . ($response['message'] ?? 'Unknown error')
            );
        }

        return $response['data'];
    }

    /**
     * Verify the HMAC-SHA256 signature on an incoming KatPay callback.
     *
     * Signature formula (from KatPay docs):
     *   HMAC-SHA256( timestamp + "." + rawBody, SECRET_KEY )
     *
     * @param string $rawBody   Raw HTTP request body (before json_decode)
     * @param string $signature Value of X-KatPay-Signature header
     * @param string $timestamp Value of X-KatPay-Timestamp header
     * @return bool  true = authentic KatPay request
     */
    public function verifyWebhookSignature(
        string $rawBody,
        string $signature,
        string $timestamp
    ): bool {
        $secret = !empty($this->webhookSecret) && $this->webhookSecret !== 'your_katpay_webhook_secret_here'
            ? $this->webhookSecret
            : $this->secretKey;

        if (empty($secret)) {
            return false;
        }

        $signedPayload     = $timestamp . '.' . $rawBody;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Check whether the timestamp on a callback is within the allowed window.
     * Prevents replay attacks.
     *
     * @param string $timestamp Unix timestamp string from X-KatPay-Timestamp header
     * @param int    $maxAgeSeconds Maximum age in seconds (default: 300 = 5 minutes)
     * @return bool  true = timestamp is fresh
     */
    public function isTimestampFresh(string $timestamp, int $maxAgeSeconds = 300): bool {
        if (!ctype_digit($timestamp)) {
            return false;
        }
        return abs(time() - (int) $timestamp) <= $maxAgeSeconds;
    }

    /**
     * Get list of supported NUBAN banks for payouts from KatPay.
     * GET /api/bank-list or /v1/bank-list
     */
    public function getBankList(): array {
        $this->assertConfigured();
        try {
            $response = $this->get('/bank-list');
            if (isset($response['data']) && is_array($response['data'])) {
                return $response['data'];
            }
            if (is_array($response) && isset($response[0])) {
                return $response;
            }
            return $response['data'] ?? [];
        } catch (RuntimeException $e) {
            // Try alternative path if base endpoint differs
            try {
                $res = $this->get('/v1/bank-list');
                return $res['data'] ?? $res;
            } catch (RuntimeException) {
                return [];
            }
        }
    }

    /**
     * Create a payout / bank transfer via KatPay.
     * POST /v1/payouts
     *
     * @param array $params { amount, bank_code, account_number, account_name, description, reference }
     */
    public function createPayout(array $params): array {
        $this->assertConfigured();

        $bankCode = (string) $params['bank_code'];
        $acctNo   = (string) $params['account_number'];
        $acctName = (string) $params['account_name'];

        $body = [
            'amount'         => (float) $params['amount'],
            'bank_code'      => $bankCode,
            'bankCode'       => $bankCode,
            'account_number' => $acctNo,
            'accountNumber'  => $acctNo,
            'account_name'   => $acctName,
            'accountName'    => $acctName,
            'description'    => $params['description'] ?? 'Admin Profit Withdrawal',
            'reference'      => $params['reference']   ?? ('WD_' . time() . '_' . rand(100, 999)),
        ];

        $response = $this->post('/payouts', $body);

        $isSuccess = !empty($response['success']) || (!empty($response['status']) && $response['status'] !== 'failed') || isset($response['id']);

        if (!$isSuccess) {
            $msg = $response['message'] ?? $response['error'] ?? 'Unknown KatPay payout failure';
            if (str_contains(strtolower($msg), 'internal error') || str_contains(strtolower($msg), 'failed') || str_contains(strtolower($msg), 'error')) {
                $msg .= ' [Note: Verify that Payouts feature is enabled under KatPay Merchant Settings and your KatPay account balance is funded]';
            }
            throw new RuntimeException('KatPay payout rejected: ' . $msg);
        }

        return $response['data'] ?? $response;
    }

    /**
     * Get live KatPay merchant wallet balance.
     */
    public function getMerchantBalance(): array {
        $this->assertConfigured();
        try {
            $response = $this->get('/merchant/balance');
            return $response['data'] ?? $response;
        } catch (RuntimeException) {
            try {
                $res = $this->get('/balance');
                return $res['data'] ?? $res;
            } catch (RuntimeException) {
                return ['available_balance' => 0, 'currency' => 'NGN'];
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Perform a POST request to the KatPay API.
     */
    private function post(string $path, array $body): array {
        return $this->request('POST', $path, $body);
    }

    /**
     * Perform a GET request to the KatPay API.
     */
    private function get(string $path): array {
        return $this->request('GET', $path, null);
    }

    /**
     * Core HTTP request handler using cURL.
     *
     * @throws RuntimeException on cURL error or non-2xx HTTP status
     */
    private function request(string $method, string $path, ?array $body): array {
        $url  = $this->baseUrl . $path;
        $json = $body !== null ? json_encode($body) : null;

        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'api-key: ' . $this->apiKey,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'GemVerify/1.0',
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $raw      = curl_exec($ch);
        $errno    = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException('KatPay cURL error #' . $errno . ': ' . curl_strerror($errno));
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'KatPay returned non-JSON response (HTTP ' . $httpCode . '): ' . substr($raw, 0, 200)
            );
        }

        // Treat 4xx / 5xx as domain/server errors
        if ($httpCode >= 400) {
            $msg = $decoded['message'] ?? $decoded['error'] ?? ('HTTP ' . $httpCode . ' Error');
            throw new RuntimeException('KatPay API Error (HTTP ' . $httpCode . '): ' . $msg);
        }

        return $decoded;
    }

    /**
     * Guard: refuse to make API calls if the secret key is not configured.
     */
    private function assertConfigured(): void {
        if (empty($this->secretKey) || $this->secretKey === 'your_katpay_secret_key_here') {
            throw new RuntimeException(
                'KatPay is not configured. Please set KATPAY_SECRET_KEY in your .env file.'
            );
        }
        if (empty($this->apiKey) || $this->apiKey === 'your_katpay_api_key_here') {
            throw new RuntimeException(
                'KatPay is not configured. Please set KATPAY_API_KEY in your .env file.'
            );
        }

        if (empty($this->merchantId) || $this->merchantId === 'your_katpay_merchant_id_here') {
            throw new RuntimeException(
                'KatPay is not configured. Please set KATPAY_MERCHANT_ID in your .env file.'
            );
        }
    }

}
