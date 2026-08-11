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

    private string $secretKey;
    private string $webhookSecret;
    private string $baseUrl;

    public function __construct() {
        $this->secretKey     = KATPAY_SECRET_KEY;
        $this->webhookSecret = KATPAY_WEBHOOK_SECRET;
        $this->baseUrl       = KATPAY_BASE_URL;
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
            'customer_name'      => $params['customer_name'],
            'customer_email'     => $params['customer_email'],
            'callback_url'       => $params['callback_url'],
            'merchant_reference' => $params['merchant_reference'],
            'currency'           => 'NGN',
        ];

        if (!empty($params['customer_phone'])) {
            $body['customer_phone'] = $params['customer_phone'];
        }
        if (!empty($params['metadata']) && is_array($params['metadata'])) {
            $body['metadata'] = $params['metadata'];
        }

        $response = $this->post('/virtual-accounts', $body);

        if (empty($response['success']) || empty($response['data'])) {
            throw new RuntimeException(
                'KatPay virtual account creation failed: ' . ($response['message'] ?? 'Unknown error')
            );
        }

        return $response['data'];
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
        if (empty($this->webhookSecret) || $this->webhookSecret === 'your_katpay_webhook_secret_here') {
            return false; // Misconfigured — refuse all callbacks
        }

        $signedPayload       = $timestamp . '.' . $rawBody;
        $expectedSignature   = hash_hmac('sha256', $signedPayload, $this->webhookSecret);

        // Use timing-safe comparison to prevent timing attacks
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

        // Treat 4xx as domain errors (pass through so callers can inspect the message)
        // Treat 5xx as fatal infrastructure errors
        if ($httpCode >= 500) {
            throw new RuntimeException('KatPay server error (HTTP ' . $httpCode . '): ' . ($decoded['message'] ?? $raw));
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
    }
}
