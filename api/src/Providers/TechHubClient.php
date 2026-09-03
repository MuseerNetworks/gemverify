<?php
/**
 * GemVerify — TechHub API HTTP Client
 *
 * Pure provider communication layer.
 * Handles ONLY the transport to/from TechHub's API.
 *
 * Responsibilities:
 *   - Build JSON payload with api_key (from PHP constant — never from caller)
 *   - Execute cURL POST (submissions) and GET (status checks)
 *   - Enforce timeout
 *   - Parse JSON response
 *   - Normalise result into a consistent structure
 *   - Log every request + response to logs/techhub_api.log
 *
 * Does NOT:
 *   - Contain any business logic
 *   - Know about GemVerify services, wallets, or users
 *   - Throw exceptions — always returns a structured result array
 *   - Expose api_key to callers (it injects it internally)
 *
 * Return structure (always):
 * [
 *   'success'       => bool,    // true only if HTTP 200 AND provider signals success
 *   'http_code'     => int,     // raw HTTP status code
 *   'provider_status' => string|null,  // raw "status" field from provider JSON
 *   'data'          => array,   // parsed provider response body (or [] on failure)
 *   'error_message' => string|null,    // human-readable error description
 *   'error_code'    => string|null,    // provider error_code if present
 *   'raw'           => string,  // raw response body (for logging/debugging only)
 * ]
 *
 * @package Providers
 */

namespace Providers;

class TechHubClient
{
    private string $baseUrl;
    private string $apiKey;
    private int    $timeout;
    private string $logPath;

    public function __construct()
    {
        $this->baseUrl = defined('TECHHUB_BASE_URL') ? TECHHUB_BASE_URL : '';
        $this->apiKey  = defined('TECHHUB_API_KEY')  ? TECHHUB_API_KEY  : '';
        $this->timeout = defined('TECHHUB_TIMEOUT')  ? TECHHUB_TIMEOUT  : 30;
        $this->logPath = defined('LOG_PATH') ? LOG_PATH . '/techhub_api.log' : __DIR__ . '/../../../logs/techhub_api.log';
    }

    // ── Public Interface ───────────────────────────────────────────────────

    /**
     * Send a POST request to a TechHub endpoint.
     * Used for: all slip-generation requests + async service submissions.
     *
     * @param string $endpoint  e.g. 'nin_by_nin.php'
     * @param array  $payload   Fields to include in JSON body (WITHOUT api_key — injected here)
     */
    public function post(string $endpoint, array $payload): array
    {
        $url  = $this->buildUrl($endpoint);
        $body = $this->buildPayload($payload);

        $this->logRequest('POST', $url, $payload);

        $result = $this->executeCurl($url, 'POST', $body);

        $this->logResponse('POST', $url, $result);

        return $result;
    }

    /**
     * Send a GET request to a TechHub endpoint.
     * Used for: async service status checks (ticket_id polls).
     *
     * @param string $endpoint  e.g. 'delinking.php'
     * @param string $ticketId  The ticket_id returned at submission
     */
    public function get(string $endpoint, string $ticketId): array
    {
        $url = $this->buildUrl($endpoint) . '?api_key=' . urlencode($this->apiKey) . '&ticket_id=' . urlencode($ticketId);

        $this->logRequest('GET', $url, ['ticket_id' => $ticketId]);

        $result = $this->executeCurl($url, 'GET', null);

        $this->logResponse('GET', $url, $result);

        return $result;
    }

    // ── Configuration guard ────────────────────────────────────────────────

    /**
     * Check that the client is properly configured.
     * Call this before use if you want a meaningful error early.
     */
    public function isConfigured(): bool
    {
        return !empty($this->baseUrl) && !empty($this->apiKey);
    }

    // ── Private Helpers ────────────────────────────────────────────────────

    private function buildUrl(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
    }

    /**
     * Inject api_key into payload. api_key is always the first field.
     * Caller payload fields are merged after, so caller cannot override api_key.
     */
    private function buildPayload(array $callerPayload): string
    {
        $payload = array_merge(
            ['api_key' => $this->apiKey],
            $callerPayload
        );
        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function executeCurl(string $url, string $method, ?string $body): array
    {
        // Guard: must be configured
        if (!$this->isConfigured()) {
            return $this->errorResult(
                0,
                'External provider is not configured. Check config in .env.',
                'PROVIDER_NOT_CONFIGURED',
                ''
            );
        }

        $ch = curl_init();

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Connection: keep-alive',
                'Expect:'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_TCP_NODELAY    => true,
            CURLOPT_TCP_KEEPALIVE  => 1,
            CURLOPT_ENCODING       => 'gzip,deflate',
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($ch, $options);

        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        $curlErrNo = curl_errno($ch);
        curl_close($ch);

        // cURL connection-level error
        if ($raw === false || $curlErrNo !== 0) {
            $errorMsg = 'Provider connection failed';
            if ($curlErrNo === CURLE_OPERATION_TIMEOUTED) {
                $errorMsg = 'Provider request timed out after ' . $this->timeout . ' seconds';
            }
            return $this->errorResult($httpCode, $errorMsg . ': ' . $curlErr, 'CURL_ERROR_' . $curlErrNo, '');
        }

        // Parse JSON
        $parsed = json_decode($raw, true);
        if ($parsed === null && $raw !== 'null') {
            return $this->errorResult($httpCode, 'Provider returned malformed JSON', 'MALFORMED_RESPONSE', $raw);
        }

        // Non-200 HTTP status
        if ($httpCode !== 200) {
            $msg = $parsed['message'] ?? ('HTTP ' . $httpCode . ' from provider');
            return $this->errorResult($httpCode, $msg, 'HTTP_' . $httpCode, $raw);
        }

        // HTTP 200 — check for business-level failure in response body.
        // TechHub slip endpoints use:  "status": "success" | "error"
        // TechHub async endpoints use: "success": true | false
        return $this->parseSuccess($parsed, $httpCode, $raw);
    }

    /**
     * Determine success from parsed TechHub response.
     *
     * TechHub uses two different conventions (documented):
     *   - Slip endpoints: { "status": "success"|"error", "response_code": "00"|"01" }
     *   - Async endpoints: { "success": true|false }
     */
    private function parseSuccess(array $parsed, int $httpCode, string $raw): array
    {
        // Convention 1: Async endpoints returning boolean "success": true | false
        if (isset($parsed['success'])) {
            if ($parsed['success'] === true) {
                return [
                    'success'         => true,
                    'http_code'       => $httpCode,
                    'provider_status' => strtolower((string)($parsed['status'] ?? 'pending')),
                    'data'            => $parsed,
                    'error_message'   => null,
                    'error_code'      => null,
                    'raw'             => $raw,
                ];
            }
            $msg  = $parsed['message'] ?? 'Provider returned success=false';
            $code = $parsed['error_code'] ?? null;
            return $this->errorResult($httpCode, $msg, $code, $raw);
        }

        // Convention 2: Slip endpoints returning string "status": "success" | "error"
        if (isset($parsed['status'])) {
            $status = strtolower((string)$parsed['status']);
            if (in_array($status, ['success', 'completed'], true)) {
                return [
                    'success'         => true,
                    'http_code'       => $httpCode,
                    'provider_status' => $status,
                    'data'            => $parsed,
                    'error_message'   => null,
                    'error_code'      => null,
                    'raw'             => $raw,
                ];
            }
            // "status": "error"
            $msg  = $parsed['message'] ?? 'Provider returned an error';
            $code = $parsed['error_code'] ?? $parsed['response_code'] ?? null;
            return $this->errorResult($httpCode, $msg, $code, $raw);
        }

        // Unknown format — treat as failure
        return $this->errorResult(
            $httpCode,
            'Provider returned an unrecognised response format',
            'UNKNOWN_FORMAT',
            $raw
        );
    }

    private function errorResult(int $httpCode, string $message, ?string $errorCode, string $raw): array
    {
        return [
            'success'         => false,
            'http_code'       => $httpCode,
            'provider_status' => null,
            'data'            => [],
            'error_message'   => $message,
            'error_code'      => $errorCode,
            'raw'             => $raw,
        ];
    }

    // ── Logging ────────────────────────────────────────────────────────────

    private function logRequest(string $method, string $url, array $payload): void
    {
        // Mask the api_key in logs — only show first 8 and last 6 characters
        $safePayload = $payload;
        if (isset($safePayload['api_key'])) {
            $key = $safePayload['api_key'];
            $safePayload['api_key'] = strlen($key) > 14
                ? substr($key, 0, 8) . '••••••••' . substr($key, -6)
                : '••••••••••••••';
        }

        $entry = sprintf(
            "[%s] REQUEST  %s %s | payload=%s\n",
            date('Y-m-d H:i:s'),
            $method,
            $url,
            json_encode($safePayload, JSON_UNESCAPED_UNICODE)
        );
        $this->writeLog($entry);
    }

    private function logResponse(string $method, string $url, array $result): void
    {
        // Never log raw PDF base64 — truncate result_data
        $logData = $result['data'];
        if (isset($logData['pdf_base64']) && strlen((string)$logData['pdf_base64']) > 100) {
            $logData['pdf_base64'] = '[BASE64_PDF_' . strlen((string)$logData['pdf_base64']) . '_BYTES_TRUNCATED]';
        }

        $entry = sprintf(
            "[%s] RESPONSE %s %s | http=%d success=%s status=%s error=%s\n",
            date('Y-m-d H:i:s'),
            $method,
            $url,
            $result['http_code'],
            $result['success'] ? 'true' : 'false',
            $result['provider_status'] ?? 'NULL',
            $result['error_message'] ?? 'none'
        );
        $this->writeLog($entry);
    }

    private function writeLog(string $entry): void
    {
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}
