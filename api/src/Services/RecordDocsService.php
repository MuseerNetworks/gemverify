<?php
declare(strict_types=1);

namespace Services;

use Providers\RecordDocsClient;
use RuntimeException;
use Throwable;

class RecordDocsService
{
    private RecordDocsClient $client;

    public function __construct(?RecordDocsClient $client = null)
    {
        $this->client = $client ?? new RecordDocsClient();
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function validateMapping(string $slug, ?string $variantKey, ?string $inputMethod): array
    {
        $supported = [
            'nin-verification',
            'bvn-verification',
            'ipe-clearance',
            'ipe-clearance-single',
            'nin-modification',
            'modification-ipe',
            'nin-validation',
            'vnin-validation',
            'bvn-retrieval'
        ];

        if (in_array($slug, $supported, true)) {
            return ['valid' => true, 'error' => null];
        }

        return ['valid' => false, 'error' => "RecordDocs does not support service slug '{$slug}'"];
    }

    // ── Synchronous Verifications (NIN & BVN) ────────────────────────────────

    public function submitSync(string $slug, ?string $variantKey, ?string $inputMethod, array $formData): array
    {
        $inputMethod = $inputMethod ?: 'by_nin';

        if ($slug === 'bvn-verification') {
            $bvn = preg_replace('/\D/', '', (string)($formData['bvn'] ?? ''));
            if (strlen($bvn) !== 11) {
                return [
                    'success'                 => false,
                    'provider_accepted'       => false,
                    'provider_charge_state'   => 'not_charged',
                    'safe_to_refund'          => true,
                    'requires_reconciliation' => false,
                    'error_message'           => 'BVN must be exactly 11 digits.',
                    'error_code'              => 'INVALID_BVN_FORMAT',
                ];
            }

            $res = $this->client->post('identity/bvn', ['bvn' => $bvn]);
            return $this->normaliseSyncResult($slug, $res);
        }

        if ($slug === 'nin-verification') {
            if ($inputMethod === 'by_phone') {
                $phone = preg_replace('/\D/', '', (string)($formData['phone'] ?? $formData['phone_number'] ?? $formData['phoneNumber'] ?? ''));
                $res = $this->client->post('identity/nin/phone', ['phone' => $phone]);
                return $this->normaliseSyncResult($slug, $res);
            }

            if ($inputMethod === 'by_demo') {
                $payload = [
                    'firstName' => trim((string)($formData['firstName'] ?? $formData['first_name'] ?? '')),
                    'lastName'  => trim((string)($formData['lastName']  ?? $formData['last_name']  ?? '')),
                    'dob'       => trim((string)($formData['dob'] ?? $formData['dateOfBirth'] ?? $formData['date_of_birth'] ?? '')),
                    'gender'    => strtoupper(trim((string)($formData['gender'] ?? 'M'))),
                ];
                $res = $this->client->post('identity/nin/demographics', $payload);
                return $this->normaliseSyncResult($slug, $res);
            }

            // Default: by_nin
            $nin = preg_replace('/\D/', '', (string)($formData['nin'] ?? ''));
            if (strlen($nin) !== 11) {
                return [
                    'success'                 => false,
                    'provider_accepted'       => false,
                    'provider_charge_state'   => 'not_charged',
                    'safe_to_refund'          => true,
                    'requires_reconciliation' => false,
                    'error_message'           => 'NIN must be exactly 11 digits.',
                    'error_code'              => 'INVALID_NIN_FORMAT',
                ];
            }

            $res = $this->client->post('identity/nin', ['nin' => $nin]);
            return $this->normaliseSyncResult($slug, $res);
        }

        return [
            'success'                 => false,
            'provider_accepted'       => false,
            'provider_charge_state'   => 'not_charged',
            'safe_to_refund'          => true,
            'requires_reconciliation' => false,
            'error_message'           => "Unsupported sync service '{$slug}'",
            'error_code'              => 'UNSUPPORTED_SERVICE',
        ];
    }

    private function normaliseSyncResult(string $slug, array $clientResult): array
    {
        $httpCode = $clientResult['http_code'] ?? 0;
        $data     = $clientResult['data'] ?? [];
        $errMsg   = $clientResult['error_message'] ?? null;
        $errCode  = $clientResult['error_code'] ?? null;

        if (!$clientResult['success']) {
            $isTimeout = ($errCode === 'CURL_ERROR_28');
            $msg = $errMsg ?: ($data['message'] ?? 'Identity lookup failed');

            $isPreChargeReject = (
                in_array($httpCode, [400, 401, 403, 404, 422], true) ||
                stripos($msg, 'not found') !== false ||
                stripos($msg, 'invalid') !== false ||
                stripos($msg, 'balance') !== false ||
                stripos($msg, 'required') !== false
            );

            return [
                'success'                 => false,
                'provider_accepted'       => false,
                'provider_charge_state'   => $isPreChargeReject ? 'not_charged' : 'unknown',
                'provider_status'         => 'failed',
                'safe_to_refund'          => $isPreChargeReject || (!$isTimeout && $httpCode > 0),
                'requires_reconciliation' => $isTimeout,
                'error_message'           => $msg,
                'error_code'              => $errCode ?: 'LOOKUP_FAILED',
                'http_code'               => $httpCode,
            ];
        }

        // RecordDocs returns verified citizen data object
        $citizenData = $data;
        $pdfBase64   = null;
        $fileName    = null;

        // Auto-generate high-res NIN PDF slip for NIN verifications
        if ($slug === 'nin-verification' && is_array($citizenData)) {
            require_once __DIR__ . '/SlipGeneratorService.php';
            $slipRes = SlipGeneratorService::generateNinSlip($citizenData);
            if ($slipRes['success']) {
                $pdfBase64 = $slipRes['pdf_base64'];
                $fileName  = $slipRes['filename'];
            }
        }

        return [
            'success'                 => true,
            'provider_accepted'       => true,
            'provider_charge_state'   => 'charged',
            'safe_to_refund'          => false,
            'requires_reconciliation' => false,
            'pdf_base64'              => $pdfBase64,
            'filename'                => $fileName,
            'user_data'               => $citizenData,
            'provider_txn_id'         => $citizenData['reference'] ?? $citizenData['id'] ?? null,
            'message'                 => 'Identity verified successfully.',
            'error_message'           => null,
            'error_code'              => null,
            'http_code'               => $httpCode,
        ];
    }

    // ── Asynchronous Job Submissions ─────────────────────────────────────────

    public function submitAsync(string $slug, ?string $variantKey, array $formData): array
    {
        $endpoint = match ($slug) {
            'ipe-clearance', 'ipe-clearance-single' => 'identity/job/submit/ipe',
            'nin-modification', 'modification-ipe'  => 'identity/job/submit/modification_ipe',
            'nin-validation', 'vnin-validation'     => 'identity/job/submit/validation',
            'bvn-retrieval'                         => 'identity/job/submit/bvn_retrieval',
            default => null
        };

        if (!$endpoint) {
            return [
                'success'                 => false,
                'provider_accepted'       => false,
                'provider_charge_state'   => 'not_charged',
                'safe_to_refund'          => true,
                'requires_reconciliation' => false,
                'error_message'           => "RecordDocs does not support async service '{$slug}'",
                'error_code'              => 'UNSUPPORTED_SERVICE',
            ];
        }

        $payload = $this->buildAsyncPayload($slug, $formData);
        $res = $this->client->post($endpoint, $payload);

        return $this->normaliseAsyncSubmitResult($res);
    }

    private function buildAsyncPayload(string $slug, array $formData): array
    {
        return match ($slug) {
            'ipe-clearance', 'ipe-clearance-single', 'nin-modification', 'modification-ipe' => [
                'trackingId' => trim((string)($formData['tracking_id'] ?? $formData['trackingId'] ?? $formData['tracking'] ?? ''))
            ],
            'nin-validation', 'vnin-validation' => [
                'nin' => preg_replace('/\D/', '', (string)($formData['nin'] ?? ''))
            ],
            'bvn-retrieval' => [
                'fullName'    => trim((string)($formData['fullName'] ?? (($formData['first_name'] ?? '') . ' ' . ($formData['last_name'] ?? '')))),
                'phoneNumber' => preg_replace('/\D/', '', (string)($formData['phoneNumber'] ?? $formData['phone_number'] ?? $formData['phone'] ?? ''))
            ],
            default => []
        };
    }

    private function normaliseAsyncSubmitResult(array $clientResult): array
    {
        $httpCode = $clientResult['http_code'] ?? 0;
        $data     = $clientResult['data'] ?? [];
        $errMsg   = $clientResult['error_message'] ?? null;
        $errCode  = $clientResult['error_code'] ?? null;

        if (!$clientResult['success']) {
            $isTimeout = ($errCode === 'CURL_ERROR_28');
            $msg = $errMsg ?: ($data['message'] ?? 'Job submission failed');

            $isPreChargeReject = (
                in_array($httpCode, [400, 401, 403, 404, 422], true) ||
                stripos($msg, 'invalid') !== false ||
                stripos($msg, 'balance') !== false ||
                stripos($msg, 'required') !== false
            );

            return [
                'success'                 => false,
                'provider_accepted'       => false,
                'provider_charge_state'   => $isPreChargeReject ? 'not_charged' : 'unknown',
                'provider_status'         => 'failed',
                'safe_to_refund'          => $isPreChargeReject || (!$isTimeout && $httpCode > 0),
                'requires_reconciliation' => $isTimeout,
                'error_message'           => $msg,
                'error_code'              => $errCode ?: 'SUBMISSION_FAILED',
                'http_code'               => $httpCode,
            ];
        }

        $ticketRef = $data['reference'] ?? null;

        return [
            'success'                 => true,
            'provider_accepted'       => true,
            'provider_charge_state'   => 'charged',
            'ticket_id'               => (string)$ticketRef,
            'provider_txn_id'         => (string)$ticketRef,
            'provider_status'         => 'pending',
            'message'                 => $data['message'] ?? 'Job submitted successfully',
            'safe_to_refund'          => false,
            'requires_reconciliation' => empty($ticketRef),
            'http_code'               => $httpCode,
        ];
    }

    // ── Status Polling ───────────────────────────────────────────────────────

    public function checkAsyncStatus(string $slug, ?string $variantKey, string $ticketRef): array
    {
        $cleanRef = trim($ticketRef);
        $res = $this->client->get('identity/jobs/' . urlencode($cleanRef));

        if (!$res['success']) {
            return [
                'success'         => false,
                'provider_status' => 'unknown',
                'is_complete'     => false,
                'is_failed'       => false,
                'is_reversed'     => false,
                'result_data'     => null,
                'error_message'   => $res['error_message'] ?? 'Status check failed',
                'error_code'      => $res['error_code'] ?? 'STATUS_CHECK_FAILED',
            ];
        }

        $job = $res['data'] ?? [];
        $rawStatus = strtoupper(trim((string)($job['status'] ?? 'PENDING')));
        $docLink   = $job['docLink'] ?? null;
        $remark    = $job['remark'] ?? null;

        $isComplete = ($rawStatus === 'SUCCESSFUL' || $rawStatus === 'FAILED' || $rawStatus === 'FAILED_REFUND');
        $isFailed   = ($rawStatus === 'FAILED' || $rawStatus === 'FAILED_REFUND');
        $isReversed = ($rawStatus === 'FAILED_REFUND');

        // If docLink is returned, download or store it
        $pdfBase64 = null;
        if (!empty($docLink) && filter_var($docLink, FILTER_VALIDATE_URL)) {
            try {
                $docContent = @file_get_contents($docLink);
                if ($docContent !== false && strlen($docContent) > 200) {
                    $pdfBase64 = base64_encode($docContent);
                }
            } catch (Throwable $e) {}
        }

        if ($pdfBase64) {
            $job['pdf_base64'] = $pdfBase64;
        }

        return [
            'success'         => true,
            'provider_status' => strtolower($rawStatus),
            'is_complete'     => $isComplete,
            'is_failed'       => $isFailed,
            'is_reversed'     => $isReversed,
            'result_data'     => $job,
            'response_note'   => $remark,
            'doc_link'        => $docLink,
            'pdf_base64'      => $pdfBase64,
            'error_message'   => $isFailed ? ($remark ?: 'Job failed on RecordDocs') : null,
        ];
    }

    // ── Wallet Balance ───────────────────────────────────────────────────────

    public function getBalances(): array
    {
        // Try /balance, fallback to /client/balance
        $res = $this->client->get('balance');
        if (!$res['success']) {
            $res = $this->client->get('client/balance');
        }

        if (!$res['success']) {
            return [
                'success' => false,
                'error'   => $res['error_message'] ?? 'Failed to retrieve RecordDocs balance',
            ];
        }

        $d = $res['data'] ?? [];
        $ninUnits = (float)($d['ninBalance'] ?? 0);
        $bvnUnits = (float)($d['bvnBalance'] ?? 0);
        $jobCash  = (float)($d['jobBalance']['amount'] ?? ($d['jobBalance'] ?? 0));
        $currency = (string)($d['jobBalance']['currency'] ?? 'NGN');

        return [
            'success'     => true,
            'nin_balance' => $ninUnits,
            'bvn_balance' => $bvnUnits,
            'job_balance' => $jobCash,
            'currency'    => $currency,
            'raw'         => $d,
        ];
    }
}
