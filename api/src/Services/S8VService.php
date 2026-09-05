<?php
namespace Services;

require_once __DIR__ . '/../Contracts/VerificationProviderInterface.php';
require_once __DIR__ . '/../Providers/S8VClient.php';

use Contracts\VerificationProviderInterface;
use Providers\S8VClient;

/**
 * GemVerify — S8V Provider Business Service
 *
 * Normalizes requests, sanitizes inputs, handles S8V state transitions,
 * and standardizes citizen demographic payloads.
 */
class S8VService implements VerificationProviderInterface
{
    private S8VClient $client;

    public function __construct(?S8VClient $client = null)
    {
        $this->client = $client ?? new S8VClient();
    }

    /**
     * Submit an asynchronous verification request to S8V.
     */
    public function submitAsync(string $serviceSlug, ?string $variantKey, array $formData): array
    {
        switch ($serviceSlug) {
            case 'personalization':
            case 'nin-personalization':
                return $this->submitPersonalization($formData, $variantKey);

            case 'ipe-clearance':
            case 'ipe-clearance-single':
                return $this->submitClearance($formData);

            case 'nin-validation':
            case 'vnin-validation':
                return $this->submitValidation($formData);

            default:
                return [
                    'success'                   => false,
                    'provider_accepted'         => false,
                    'ticket_id'                 => null,
                    'provider_status'           => 'rejected',
                    'provider_financial_status' => 'not_charged',
                    'safe_to_refund'            => true,
                    'requires_reconciliation'   => false,
                    'error_message'             => "Service '{$serviceSlug}' is not supported by S8V provider.",
                    'error_code'                => 'UNSUPPORTED_SERVICE',
                    'data'                      => []
                ];
        }
    }

    /**
     * Check status of an ongoing async request.
     */
    public function checkAsyncStatus(string $serviceSlug, ?string $variantKey, string $ticketId, ?string $trackingId = null): array
    {
        switch ($serviceSlug) {
            case 'personalization':
            case 'nin-personalization':
                return $this->checkPersonalizationStatus($ticketId, $trackingId);

            case 'ipe-clearance':
            case 'ipe-clearance-single':
                return $this->checkClearanceStatus($ticketId, $trackingId);

            case 'nin-validation':
            case 'vnin-validation':
                return $this->checkValidationStatus($ticketId, $trackingId);

            default:
                return [
                    'success'         => false,
                    'is_complete'     => false,
                    'is_failed'       => false,
                    'provider_status' => 'unknown',
                    'result_data'     => null,
                    'error_message'   => "Service '{$serviceSlug}' is not supported for status checks.",
                    'error_code'      => 'UNSUPPORTED_SERVICE'
                ];
        }
    }

    // ── Personalization Implementation ───────────────────────────────────────

    /**
     * 1. Submit Personalization
     * POST https://www.s8v.ng/api/personalization
     * Body: { "token": "...", "tracking_id": "...", "type": "info" }
     */
    private function submitPersonalization(array $formData, ?string $variantKey): array
    {
        $rawTracking = $formData['tracking_id'] ?? $formData['tracking'] ?? '';
        $trackingId  = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', (string)$rawTracking)));

        if (empty($trackingId)) {
            return [
                'success'                   => false,
                'provider_accepted'         => false,
                'ticket_id'                 => null,
                'provider_status'           => 'rejected',
                'provider_financial_status' => 'not_charged',
                'safe_to_refund'            => true,
                'requires_reconciliation'   => false,
                'error_message'             => 'Tracking ID is required for Personalization.',
                'error_code'                => 'MISSING_TRACKING_ID',
                'data'                      => []
            ];
        }

        $type = in_array($variantKey, ['info', 'standard', 'premium'], true) ? $variantKey : 'info';

        $payload = [
            'tracking_id' => $trackingId,
            'type'        => $type
        ];

        $res = $this->client->post('personalization', $payload);

        // Pre-flight or HTTP 402 rejection: 100% safe to refund immediately
        if (!$res['success']) {
            return [
                'success'                   => false,
                'provider_accepted'         => false,
                'ticket_id'                 => null,
                'provider_status'           => 'rejected',
                'provider_financial_status' => 'not_charged',
                'safe_to_refund'            => true,
                'requires_reconciliation'   => false,
                'error_message'             => $res['error_message'] ?? 'Personalization submission rejected by gateway.',
                'error_code'                => $res['error_code'] ?? 'SUBMISSION_FAILED',
                'data'                      => $res['data'] ?? []
            ];
        }

        $respData = $res['data'];
        $ticketId = $respData['id'] ?? null;
        $status   = strtolower((string)($respData['status'] ?? 'in-progress'));

        // S8V accepted request -> returns id and status 'In-Progress'
        return [
            'success'                   => true,
            'provider_accepted'         => true,
            'ticket_id'                 => (string)$ticketId,
            'provider_status'           => $status === 'successful' ? 'completed' : 'processing',
            'provider_financial_status' => 'charged',
            'safe_to_refund'            => false, // Cannot auto-refund full fee, job in-flight
            'requires_reconciliation'   => false,
            'error_message'             => null,
            'error_code'                => null,
            'data'                      => $respData
        ];
    }

    /**
     * 2. Check Personalization Status
     * POST https://www.s8v.ng/api/personalization/check
     * Body: { "token": "...", "id": 12345 } or { "token": "...", "tracking_id": "..." }
     */
    private function checkPersonalizationStatus(string $ticketId, ?string $trackingId = null): array
    {
        $payload = [];
        if (!empty($ticketId) && is_numeric($ticketId)) {
            $payload['id'] = (int)$ticketId;
        } elseif (!empty($trackingId)) {
            $payload['tracking_id'] = strtoupper(trim((string)$trackingId));
        } else {
            $payload['id'] = $ticketId;
        }

        $res = $this->client->post('personalization/check', $payload);

        if (!$res['success']) {
            return [
                'success'         => false,
                'is_complete'     => false,
                'is_failed'       => false,
                'provider_status' => 'processing',
                'result_data'     => null,
                'error_message'   => $res['error_message'] ?? 'Unable to retrieve status from gateway',
                'error_code'      => $res['error_code'] ?? null
            ];
        }

        $data   = $res['data'];
        $status = strtolower(trim((string)($data['status'] ?? '')));

        if ($status === 'successful') {
            // Citizen demographic record + photo
            $citizenData = $data['data'] ?? [];
            return [
                'success'         => true,
                'is_complete'     => true,
                'is_failed'       => false,
                'provider_status' => 'completed',
                'result_data'     => $citizenData,
                'error_message'   => null,
                'error_code'      => null
            ];
        }

        if ($status === 'failed') {
            $isIpe = (
                strtoupper((string)($data['tracking_id'] ?? '')) === 'IPE' ||
                strtoupper((string)($data['data']['idNumber'] ?? '')) === 'IPE' ||
                strtoupper((string)($data['data']['tracking_id'] ?? '')) === 'IPE' ||
                stripos(($data['message'] ?? ''), 'IPE') !== false ||
                stripos(($data['message'] ?? ''), 'clearance') !== false
            );

            if ($isIpe) {
                return [
                    'success'         => true,
                    'is_complete'     => true,
                    'is_failed'       => true,
                    'provider_status' => 'failed',
                    'result_data'     => $data,
                    'error_message'   => 'Tracking ID has IPE (Send for clearance).',
                    'error_code'      => 'FAILED_IPE_CLEARANCE_REQUIRED'
                ];
            }

            // Failed: Provider penalty fee applies
            return [
                'success'         => true,
                'is_complete'     => true,
                'is_failed'       => true,
                'provider_status' => 'failed',
                'result_data'     => $data,
                'error_message'   => $data['message'] ?? 'No matching record found for this tracking ID on identity registry.',
                'error_code'      => 'FAILED_RECORD_NOT_FOUND'
            ];
        }

        // In-Progress / Pending
        return [
            'success'         => true,
            'is_complete'     => false,
            'is_failed'       => false,
            'provider_status' => 'processing',
            'result_data'     => null,
            'error_message'   => null,
            'error_code'      => null
        ];
    }

    // ── IPE Clearance Implementation ─────────────────────────────────────────

    private function submitClearance(array $formData): array
    {
        $rawTracking = $formData['tracking_id'] ?? $formData['tracking'] ?? '';
        $trackingId  = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', (string)$rawTracking)));

        $res = $this->client->post('clearance', ['tracking_id' => $trackingId]);
        if (!$res['success']) {
            return [
                'success'                   => false,
                'provider_accepted'         => false,
                'ticket_id'                 => null,
                'provider_status'           => 'rejected',
                'provider_financial_status' => 'not_charged',
                'safe_to_refund'            => true,
                'requires_reconciliation'   => false,
                'error_message'             => $res['error_message'] ?? 'Clearance submission rejected.',
                'error_code'                => $res['error_code'] ?? 'SUBMISSION_FAILED',
                'data'                      => []
            ];
        }

        return [
            'success'                   => true,
            'provider_accepted'         => true,
            'ticket_id'                 => (string)($res['data']['id'] ?? $trackingId),
            'provider_status'           => 'processing',
            'provider_financial_status' => 'charged',
            'safe_to_refund'            => false,
            'requires_reconciliation'   => false,
            'error_message'             => null,
            'error_code'                => null,
            'data'                      => $res['data'] ?? []
        ];
    }

    private function checkClearanceStatus(string $ticketId, ?string $trackingId = null): array
    {
        $cleanTracking = null;
        if (!empty($trackingId)) {
            if (preg_match('/tracking[:=]\s*([a-zA-Z0-9]+)/i', $trackingId, $m)) {
                $cleanTracking = strtoupper($m[1]);
            } else {
                $cleanTracking = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', (string)$trackingId)));
            }
        }

        $payload = [];
        if (!empty($cleanTracking) && $cleanTracking !== 'IPE') {
            $payload['tracking_id'] = $cleanTracking;
        } elseif (!empty($ticketId) && is_numeric($ticketId)) {
            $payload['id'] = (int)$ticketId;
        } else {
            $payload['tracking_id'] = strtoupper(trim($ticketId));
        }

        $res = $this->client->post('clearance/status', $payload);
        if (!$res['success']) {
            return [
                'success'         => false,
                'is_complete'     => false,
                'is_failed'       => false,
                'provider_status' => 'processing',
                'result_data'     => null,
                'error_message'   => $res['error_message'] ?? null,
                'error_code'      => $res['error_code'] ?? null
            ];
        }

        $data   = $res['data'];
        $status = strtolower(trim((string)($data['status'] ?? '')));
        $isSuccess = ($status === 'successful' || $status === 'completed');
        $isFailed  = ($status === 'failed');

        return [
            'success'         => true,
            'is_complete'     => $isSuccess || $isFailed,
            'is_failed'       => $isFailed,
            'provider_status' => $isSuccess ? 'completed' : ($isFailed ? 'failed' : 'processing'),
            'result_data'     => $data['data'] ?? $data,
            'error_message'   => $isFailed ? ($data['message'] ?? 'Clearance unsuccessful') : null,
            'error_code'      => null
        ];
    }

    // ── Validation Implementation ────────────────────────────────────────────

    private function submitValidation(array $formData): array
    {
        $nin = trim((string)($formData['nin'] ?? ''));
        $errorDesc = trim((string)($formData['error'] ?? 'No Record'));

        $res = $this->client->post('validation', [
            'nin'   => $nin,
            'error' => $errorDesc,
            'api'   => defined('S8V_API_TOKEN') ? S8V_API_TOKEN : ''
        ]);

        if (!$res['success']) {
            return [
                'success'                   => false,
                'provider_accepted'         => false,
                'ticket_id'                 => null,
                'provider_status'           => 'rejected',
                'provider_financial_status' => 'not_charged',
                'safe_to_refund'            => true,
                'requires_reconciliation'   => false,
                'error_message'             => $res['error_message'] ?? 'Validation submission failed.',
                'error_code'                => $res['error_code'] ?? null,
                'data'                      => []
            ];
        }

        return [
            'success'                   => true,
            'provider_accepted'         => true,
            'ticket_id'                 => (string)($res['data']['id'] ?? $nin),
            'provider_status'           => 'processing',
            'provider_financial_status' => 'charged',
            'safe_to_refund'            => false,
            'requires_reconciliation'   => false,
            'error_message'             => null,
            'error_code'                => null,
            'data'                      => $res['data'] ?? []
        ];
    }

    private function checkValidationStatus(string $ticketId, ?string $trackingId = null): array
    {
        $nin = $trackingId ?: $ticketId;
        $res = $this->client->post('validation/status', [
            'nin'   => $nin,
            'token' => defined('S8V_API_TOKEN') ? S8V_API_TOKEN : ''
        ]);

        if (!$res['success']) {
            return [
                'success'         => false,
                'is_complete'     => false,
                'is_failed'       => false,
                'provider_status' => 'processing',
                'result_data'     => null,
                'error_message'   => $res['error_message'] ?? null,
                'error_code'      => $res['error_code'] ?? null
            ];
        }

        $data      = $res['data'];
        $status    = strtolower(trim((string)($data['status'] ?? '')));
        $isSuccess = ($status === 'successful');
        $isFailed  = ($status === 'failed');

        return [
            'success'         => true,
            'is_complete'     => $isSuccess || $isFailed,
            'is_failed'       => $isFailed,
            'provider_status' => $isSuccess ? 'completed' : ($isFailed ? 'failed' : 'processing'),
            'result_data'     => $data,
            'error_message'   => $isFailed ? ($data['message'] ?? 'Validation unsuccessful') : null,
            'error_code'      => null
        ];
    }
}