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
     * GET https://api.katpay.co/api/bank-list
     */
    public function getBankList(): array {
        try {
            $ch = curl_init('https://api.katpay.co/api/bank-list');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'User-Agent: GemVerify/1.0',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            ]);
            $raw = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200 && $raw) {
                $decoded = json_decode($raw, true);
                $rawBanks = $decoded['banks'] ?? $decoded['data'] ?? [];
                if (is_array($rawBanks) && !empty($rawBanks)) {
                    $banks = [];
                    foreach ($rawBanks as $b) {
                        $c = (string) ($b['bankCode'] ?? $b['bank_code'] ?? $b['code'] ?? '');
                        $n = (string) ($b['bankName'] ?? $b['bank_name'] ?? $b['name'] ?? '');
                        if ($c !== '' && $n !== '') {
                            $banks[$c] = [
                                'code'      => $c,
                                'bank_code' => $c,
                                'name'      => $n,
                                'bank_name' => $n,
                            ];
                        }
                    }
                    uasort($banks, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
                    return array_values($banks);
                }
            }
        } catch (\Throwable $e) {
            // Fall through to verified KatPay 6-digit bank list
        }

        // Verified KatPay NIP 6-digit routing codes
        return [
            ['code' => '100004', 'bank_code' => '100004', 'name' => 'OPay', 'bank_name' => 'OPay'],
            ['code' => '100033', 'bank_code' => '100033', 'name' => 'PalmPay', 'bank_name' => 'PalmPay'],
            ['code' => '090405', 'bank_code' => '090405', 'name' => 'Moniepoint Microfinance Bank', 'bank_name' => 'Moniepoint Microfinance Bank'],
            ['code' => '090267', 'bank_code' => '090267', 'name' => 'Kuda MFB', 'bank_name' => 'Kuda MFB'],
            ['code' => '000013', 'bank_code' => '000013', 'name' => 'Guaranty Trust Bank (GTBank)', 'bank_name' => 'Guaranty Trust Bank (GTBank)'],
            ['code' => '000014', 'bank_code' => '000014', 'name' => 'Access Bank', 'bank_name' => 'Access Bank'],
            ['code' => '000015', 'bank_code' => '000015', 'name' => 'Zenith Bank', 'bank_name' => 'Zenith Bank'],
            ['code' => '000016', 'bank_code' => '000016', 'name' => 'First Bank Of Nigeria', 'bank_name' => 'First Bank Of Nigeria'],
            ['code' => '000004', 'bank_code' => '000004', 'name' => 'United Bank For Africa (UBA)', 'bank_name' => 'United Bank For Africa (UBA)'],
            ['code' => '000007', 'bank_code' => '000007', 'name' => 'Fidelity Bank', 'bank_name' => 'Fidelity Bank'],
            ['code' => '000003', 'bank_code' => '000003', 'name' => 'First City Monument Bank (FCMB)', 'bank_name' => 'First City Monument Bank (FCMB)'],
            ['code' => '000012', 'bank_code' => '000012', 'name' => 'Stanbic IBTC Bank', 'bank_name' => 'Stanbic IBTC Bank'],
            ['code' => '000001', 'bank_code' => '000001', 'name' => 'Sterling Bank', 'bank_name' => 'Sterling Bank'],
            ['code' => '000018', 'bank_code' => '000018', 'name' => 'Union Bank of Nigeria', 'bank_name' => 'Union Bank of Nigeria'],
            ['code' => '000017', 'bank_code' => '000017', 'name' => 'Wema Bank (ALAT)', 'bank_name' => 'Wema Bank (ALAT)'],
            ['code' => '000006', 'bank_code' => '000006', 'name' => 'Polaris Bank', 'bank_name' => 'Polaris Bank'],
            ['code' => '000010', 'bank_code' => '000010', 'name' => 'Ecobank Nigeria', 'bank_name' => 'Ecobank Nigeria'],
            ['code' => '000011', 'bank_code' => '000011', 'name' => 'Unity Bank', 'bank_name' => 'Unity Bank'],
            ['code' => '000019', 'bank_code' => '000019', 'name' => 'Jaiz Bank', 'bank_name' => 'Jaiz Bank'],
            ['code' => '000026', 'bank_code' => '000026', 'name' => 'TAJ Bank', 'bank_name' => 'TAJ Bank'],
            ['code' => '090110', 'bank_code' => '090110', 'name' => 'VFD Microfinance Bank', 'bank_name' => 'VFD Microfinance Bank'],
        ];
    }

    /**
     * Create a payout / bank transfer via KatPay.
     * POST /v1/payouts
     *
     * @param array $params { amount, bank_code, account_number, account_name, description, reference }
     */
    public function createPayout(array $params): array {
        $this->assertConfigured();

        $amount   = round((float) ($params['amount'] ?? 0), 2);
        $bankCode = trim((string) ($params['bank_code'] ?? $params['bankCode'] ?? ''));
        $acctNo   = preg_replace('/\D+/', '', (string) ($params['account_number'] ?? $params['accountNumber'] ?? '')) ?? '';
        $acctName = trim((string) ($params['account_name'] ?? $params['accountName'] ?? ''));
        $ref      = trim((string) ($params['reference'] ?? ('WD_' . date('YmdHis') . '_' . rand(1000, 9999))));
        $desc     = trim((string) ($params['description'] ?? 'GemVerify Admin Profit Withdrawal'));

        if ($amount < 100) {
            throw new RuntimeException('KatPay payout minimum amount is ₦100.00.');
        }
        if (!preg_match('/^\d{10}$/', $acctNo)) {
            throw new RuntimeException('KatPay payout requires a valid 10-digit NUBAN account number.');
        }
        if ($bankCode === '' || $acctName === '') {
            throw new RuntimeException('KatPay payout requires destination bank and account name.');
        }

        // Clean payload matching GemData's exact working structure
        $body = [
            'amount'         => $amount,
            'bank_code'      => $bankCode,
            'account_number' => $acctNo,
            'account_name'   => $acctName,
            'description'    => $desc,
            'reference'      => $ref,
        ];

        $response = $this->post('/payouts', $body);

        $status = strtolower(trim((string) (
            $response['status']
            ?? $response['data']['status']
            ?? $response['data']['payout_status']
            ?? $response['code']
            ?? ($response['success'] ?? false ? 'successful' : '')
        )));

        if (is_bool($response['status'] ?? null)) {
            $status = $response['status'] === true ? 'successful' : 'failed';
        }

        if (in_array($status, ['failed', 'failure', 'error', 'reversed', 'rejected'], true)) {
            $msg = $response['message'] ?? $response['error'] ?? 'KatPay payout rejected by payment gateway.';
            throw new RuntimeException($msg);
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
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_TCP_NODELAY    => true,
            CURLOPT_TCP_KEEPALIVE  => 1,
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
