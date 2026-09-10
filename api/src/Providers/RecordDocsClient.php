<?php
declare(strict_types=1);

namespace Providers;

use RuntimeException;

/**
 * RecordDocsClient
 *
 * Low-level HTTP transport client for RecordDocs API (https://api-service.recorddocs.net/api/v1).
 * Authenticates via HTTP Bearer token.
 */
class RecordDocsClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;
    private string $logFile;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null, ?int $timeout = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (defined('RECORDDOCS_BASE_URL') ? RECORDDOCS_BASE_URL : 'https://api-service.recorddocs.net/api/v1'), '/');
        $this->apiKey  = trim($apiKey  ?? (defined('RECORDDOCS_API_KEY') ? RECORDDOCS_API_KEY : ''));
        $this->timeout = $timeout ?? (defined('RECORDDOCS_TIMEOUT') ? (int)RECORDDOCS_TIMEOUT : 30);
        $this->logFile = __DIR__ . '/../../logs/recorddocs_api.log';
    }

    public function isConfigured(): bool
    {
        return !empty($this->baseUrl) && !empty($this->apiKey);
    }

    /**
     * Send GET request
     */
    public function get(string $endpoint, array $queryParams = []): array
    {
        $url = $this->buildUrl($endpoint);
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $this->logRequest('GET', $url, $queryParams);
        $res = $this->executeCurl($url, 'GET', null);
        $this->logResponse('GET', $url, $res);

        return $res;
    }

    /**
     * Send POST request
     */
    public function post(string $endpoint, array $payload = []): array
    {
        $url  = $this->buildUrl($endpoint);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->logRequest('POST', $url, $payload);
        $res = $this->executeCurl($url, 'POST', $body);
        $this->logResponse('POST', $url, $res);

        return $res;
    }

    private function buildUrl(string $endpoint): string
    {
        return $this->baseUrl . '/' . ltrim($endpoint, '/');
    }

    private function executeCurl(string $url, string $method, ?string $body): array
    {
        if (!$this->isConfigured()) {
            return $this->errorResult(0, 'RecordDocs provider is not configured. Missing API key in .env', 'PROVIDER_NOT_CONFIGURED', '');
        }

        $ch = curl_init();
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'Connection: keep-alive',
            'Expect:',
        ];

        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
        }

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_TCP_NODELAY    => true,
            CURLOPT_ENCODING       => 'gzip,deflate',
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '{}';
        } else {
            $options[CURLOPT_HTTPGET]    = true;
        }

        curl_setopt_array($ch, $options);

        $raw       = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr   = curl_error($ch);
        $curlErrNo = curl_errno($ch);
        curl_close($ch);

        // cURL transport error
        if ($raw === false || $curlErrNo !== 0) {
            $errorMsg = 'RecordDocs connection failed';
            if ($curlErrNo === CURLE_OPERATION_TIMEOUTED) {
                $errorMsg = 'RecordDocs request timed out after ' . $this->timeout . ' seconds';
            }
            return $this->errorResult($httpCode, $errorMsg . ': ' . $curlErr, 'CURL_ERROR_' . $curlErrNo, '');
        }

        // Parse JSON
        $parsed = json_decode((string)$raw, true);
        if ($parsed === null && $raw !== 'null') {
            return $this->errorResult($httpCode, 'RecordDocs returned malformed JSON response', 'MALFORMED_RESPONSE', (string)$raw);
        }

        // Check HTTP level failures (e.g. 401 Unauthorized, 404, 500)
        if ($httpCode >= 400) {
            $msg = $parsed['message'] ?? $parsed['error'] ?? ('HTTP ' . $httpCode . ' from RecordDocs');
            $errCode = $parsed['error_code'] ?? ('HTTP_' . $httpCode);
            return $this->errorResult($httpCode, (string)$msg, (string)$errCode, (string)$raw, is_array($parsed) ? $parsed : []);
        }

        // Standard RecordDocs response uses {"success": true|false, "message": "...", "data": {...}}
        $success = !empty($parsed['success']);
        $msg     = $parsed['message'] ?? null;
        $data    = $parsed['data'] ?? $parsed;

        return [
            'success'       => $success,
            'http_code'     => $httpCode,
            'data'          => is_array($data) ? $data : [],
            'error_message' => $success ? null : ($msg ?: 'RecordDocs request failed'),
            'error_code'    => $success ? null : ($parsed['error_code'] ?? 'PROVIDER_ERROR'),
            'raw'           => (string)$raw,
        ];
    }

    private function errorResult(int $httpCode, string $message, ?string $errorCode, string $raw, array $data = []): array
    {
        return [
            'success'       => false,
            'http_code'     => $httpCode,
            'data'          => $data,
            'error_message' => $message,
            'error_code'    => $errorCode,
            'raw'           => $raw,
        ];
    }

    private function logRequest(string $method, string $url, array $payload): void
    {
        $this->writeLog(sprintf("[%s] -> %s %s | payload: %s\n", date('Y-m-d H:i:s'), $method, $url, json_encode($payload)));
    }

    private function logResponse(string $method, string $url, array $result): void
    {
        $status = $result['success'] ? 'SUCCESS' : 'FAILED';
        $this->writeLog(sprintf("[%s] <- %s %s [%s HTTP %d] | error: %s\n", date('Y-m-d H:i:s'), $method, $url, $status, $result['http_code'], $result['error_message'] ?? 'none'));
    }

    private function writeLog(string $message): void
    {
        if (!is_dir(dirname($this->logFile))) {
            @mkdir(dirname($this->logFile), 0755, true);
        }
        @file_put_contents($this->logFile, $message, FILE_APPEND | LOCK_EX);
    }
}
