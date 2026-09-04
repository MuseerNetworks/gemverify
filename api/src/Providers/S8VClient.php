<?php
namespace Providers;

/**
 * GemVerify — S8V API HTTP Client
 *
 * Low-level transport layer for communicating with https://www.s8v.ng/api.
 * Responsibilities:
 *   - Injects S8V_API_TOKEN securely
 *   - Performs cURL POST requests with timeout & JSON headers
 *   - Traps HTTP 402 ("Insufficient Provider Balance")
 *   - Logs outgoing and incoming traffic for audit trail
 */
class S8VClient
{
    private string $baseUrl;
    private string $apiToken;
    private int    $timeout;
    private string $logPath;

    public function __construct()
    {
        $this->baseUrl  = defined('S8V_API_BASE')  ? S8V_API_BASE  : 'https://www.s8v.ng/api';
        $this->apiToken = defined('S8V_API_TOKEN') ? S8V_API_TOKEN : '';
        $this->timeout  = defined('S8V_TIMEOUT')   ? S8V_TIMEOUT   : 30;
        $this->logPath  = defined('LOG_PATH') ? LOG_PATH . '/s8v_api.log' : __DIR__ . '/../../../logs/s8v_api.log';
    }

    /**
     * Send a POST request to an S8V endpoint.
     * Automatically injects "token" into the request JSON payload.
     *
     * @param string $endpoint e.g. 'personalization', 'personalization/check', 'clearance', 'validation'
     * @param array  $payload  Request parameters (without token)
     * @return array Standardized raw response:
     *   [
     *     'success'       => bool,
     *     'http_code'     => int,
     *     'data'          => array,
     *     'error_message' => string|null,
     *     'error_code'    => int|string|null,
     *     'raw'           => string
     *   ]
     */
    public function post(string $endpoint, array $payload): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        // Inject authentication token
        $payloadWithAuth = array_merge(['token' => $this->apiToken], $payload);
        $jsonBody        = json_encode($payloadWithAuth, JSON_UNESCAPED_SLASHES);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($jsonBody),
            ],
        ]);

        $startTime = microtime(true);
        $rawResponse = curl_exec($ch);
        $elapsed     = round((microtime(true) - $startTime) * 1000);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        $this->logTransaction($endpoint, $payload, $httpCode, $rawResponse, $elapsed, $curlError);

        // Network / cURL level failure
        if ($rawResponse === false || !empty($curlError)) {
            return [
                'success'       => false,
                'http_code'     => 0,
                'data'          => [],
                'error_message' => 'Connection to verification service timed out. Please try again.',
                'error_code'    => 'CURL_ERROR',
                'raw'           => (string)$curlError,
            ];
        }

        // Provider Account Depletion Trap (HTTP 402)
        if ($httpCode === 402) {
            return [
                'success'       => false,
                'http_code'     => 402,
                'data'          => [],
                'error_message' => 'Service gateway is temporarily undergoing maintenance (Provider Balance).',
                'error_code'    => 'PROVIDER_INSUFFICIENT_BALANCE',
                'raw'           => (string)$rawResponse,
            ];
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            return [
                'success'       => false,
                'http_code'     => $httpCode,
                'data'          => [],
                'error_message' => 'Invalid gateway response format.',
                'error_code'    => 'INVALID_JSON',
                'raw'           => (string)$rawResponse,
            ];
        }

        $isSuccess = ($httpCode >= 200 && $httpCode < 300) && (!isset($decoded['success']) || $decoded['success'] === true);

        return [
            'success'       => $isSuccess,
            'http_code'     => $httpCode,
            'data'          => $decoded,
            'error_message' => $decoded['message'] ?? ($isSuccess ? null : 'Gateway request unsuccessful'),
            'error_code'    => $decoded['error'] ?? $httpCode,
            'raw'           => (string)$rawResponse,
        ];
    }

    private function logTransaction(string $endpoint, array $payload, int $httpCode, $response, float $ms, string $error): void
    {
        try {
            $dir = dirname($this->logPath);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);

            // Redact token if logged
            $safePayload = $payload;
            unset($safePayload['token'], $safePayload['pin']);

            $logEntry = sprintf(
                "[%s] S8V POST %s | HTTP %d | %dms%s\nRequest: %s\nResponse: %s\n---\n",
                date('Y-m-d H:i:s'),
                $endpoint,
                $httpCode,
                $ms,
                $error ? " | Error: {$error}" : '',
                json_encode($safePayload),
                is_string($response) ? substr($response, 0, 1000) : ''
            );
            @file_put_contents($this->logPath, $logEntry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {}
    }
}