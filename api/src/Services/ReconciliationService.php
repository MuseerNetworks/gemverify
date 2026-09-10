<?php
declare(strict_types=1);

namespace Services;

use PDO;
use Throwable;

class ReconciliationService
{
    private PDO $db;
    private WalletService $walletService;
    private AuditService $auditService;
    private TechHubService $techHubService;
    private S8VService $s8vService;
    private \Services\RecordDocsService $recordDocsService;
    private static ?array $cachedColumns = null;

    public function __construct(?PDO $db = null)
    {
        $this->db               = $db ?: \db();
        $this->walletService    = new WalletService($this->db);
        $this->auditService     = new AuditService($this->db);
        $this->techHubService   = new TechHubService();
        $this->s8vService       = new S8VService();
        $this->recordDocsService = new \Services\RecordDocsService();
    }

    /**
     * Get existing columns in api_transactions once per process.
     */
    private function getColumns(): array
    {
        if (self::$cachedColumns === null) {
            try {
                $cols = $this->db->query("SHOW COLUMNS FROM api_transactions")->fetchAll(PDO::FETCH_COLUMN);
                self::$cachedColumns = is_array($cols) ? $cols : [];
            } catch (Throwable $e) {
                self::$cachedColumns = [];
            }
        }
        return self::$cachedColumns;
    }

    /**
     * Run batch reconciliation across pending/flagged transactions.
     *
     * @param array $options [
     *   'limit'             => int (default 50),
     *   'sla_minutes'       => int (default 15),
     *   'actor'             => string (default 'system_cron'),
     *   'include_processing'=> bool (default true),
     *   'dry_run'           => bool (default false),
     * ]
     * @return array
     */
    public function runBatch(array $options = []): array
    {
        $limit             = (int)($options['limit'] ?? 50);
        $slaMinutes        = (int)($options['sla_minutes'] ?? 15);
        $actor             = (string)($options['actor'] ?? 'system_cron');
        $includeProcessing = !empty($options['include_processing']);
        $dryRun            = !empty($options['dry_run']);

        // Check which optional columns exist in services table
        $serviceCols = [];
        try {
            $sCols = $this->db->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
            $serviceCols = is_array($sCols) ? $sCols : [];
        } catch (Throwable $e) {}

        $penaltyCol = in_array('failure_penalty_fee', $serviceCols, true)
            ? 's.failure_penalty_fee'
            : '0.00 AS failure_penalty_fee';

        $providerCol = in_array('provider_name', $serviceCols, true)
            ? 's.provider_name'
            : "'' AS provider_name";

        $whereClauses = [
            "at.gv_status = 'reconciliation_required'",
            "(at.gv_status = 'failed' AND (at.refund_issued = 0 OR at.refund_issued IS NULL))"
        ];

        if ($includeProcessing) {
            $whereClauses[] = "(at.gv_status = 'processing' AND at.submitted_at < NOW() - INTERVAL 10 MINUTE)";
        }

        $whereSql = '(' . implode(' OR ', $whereClauses) . ')';

        $sql = "
            SELECT
                at.*,
                s.name AS service_name,
                s.slug AS service_slug,
                {$penaltyCol},
                {$providerCol},
                COALESCE(sp.price, t.amount, 0) AS price_paid,
                u.business_name AS user_name,
                u.email AS user_email
            FROM api_transactions at
            JOIN services s ON s.id = at.service_id
            LEFT JOIN service_pricing sp ON sp.id = at.pricing_id
            LEFT JOIN transactions t ON t.id = at.transaction_id
            LEFT JOIN users u ON u.id = at.user_id
            WHERE {$whereSql}
            ORDER BY at.submitted_at ASC
            LIMIT {$limit}
        ";

        $stmt = $this->db->query($sql);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [
            'total_found'   => count($candidates),
            'completed'     => 0,
            'refunded'      => 0,
            'still_pending' => 0,
            'skipped'       => 0,
            'errors'        => 0,
            'items'         => []
        ];

        foreach ($candidates as $tx) {
            $ref = $tx['gv_reference'];
            try {
                if ($dryRun) {
                    $results['items'][] = [
                        'gv_reference' => $ref,
                        'service'      => $tx['service_name'],
                        'status'       => $tx['gv_status'],
                        'dry_run'      => true,
                    ];
                    continue;
                }

                $res = $this->reconcileRecord($tx, $actor, $slaMinutes);
                $action = $res['action'] ?? 'none';

                if (str_starts_with($action, 'refunded')) {
                    $results['refunded']++;
                } elseif ($action === 'completed') {
                    $results['completed']++;
                } elseif ($action === 'still_pending' || $action === 'pending_sla_window') {
                    $results['still_pending']++;
                } else {
                    $results['skipped']++;
                }

                $results['items'][] = array_merge(['gv_reference' => $ref], $res);

            } catch (Throwable $e) {
                $results['errors']++;
                $results['items'][] = [
                    'gv_reference' => $ref,
                    'action'       => 'error',
                    'error'        => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Reconcile a single transaction by reference or ID.
     */
    public function reconcileSingle(string|int $refOrId, string $actor = 'admin', int $slaMinutes = 15): array
    {
        $serviceCols = [];
        try {
            $sCols = $this->db->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
            $serviceCols = is_array($sCols) ? $sCols : [];
        } catch (Throwable $e) {}

        $penaltyCol = in_array('failure_penalty_fee', $serviceCols, true)
            ? 's.failure_penalty_fee'
            : '0.00 AS failure_penalty_fee';

        $providerCol = in_array('provider_name', $serviceCols, true)
            ? 's.provider_name'
            : "'' AS provider_name";

        $field = is_numeric($refOrId) ? 'at.id' : 'at.gv_reference';

        $stmt = $this->db->prepare("
            SELECT
                at.*,
                s.name AS service_name,
                s.slug AS service_slug,
                {$penaltyCol},
                {$providerCol},
                COALESCE(sp.price, t.amount, 0) AS price_paid,
                u.business_name AS user_name,
                u.email AS user_email
            FROM api_transactions at
            JOIN services s ON s.id = at.service_id
            LEFT JOIN service_pricing sp ON sp.id = at.pricing_id
            LEFT JOIN transactions t ON t.id = at.transaction_id
            LEFT JOIN users u ON u.id = at.user_id
            WHERE {$field} = ?
            LIMIT 1
        ");
        $stmt->execute([$refOrId]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tx) {
            return ['success' => false, 'error' => 'Transaction not found'];
        }

        return $this->reconcileRecord($tx, $actor, $slaMinutes);
    }

    /**
     * Core reconciliation logic for a single row.
     */
    public function reconcileRecord(array $tx, string $actor = 'system_cron', int $slaMinutes = 15): array
    {
        $id          = (int)$tx['id'];
        $ref         = $tx['gv_reference'];
        $userId      = (int)$tx['user_id'];
        $serviceSlug = $tx['service_slug'] ?? '';
        $serviceName = $tx['service_name'] ?? 'Verification';
        $variantKey  = $tx['variant_key'] ?? null;
        $pricePaid   = (float)($tx['price_paid'] ?? 0.00);
        $resultType  = $tx['result_type'] ?? '';
        $currentGv   = $tx['gv_status'];
        $activeProvider = !empty($tx['provider']) ? strtolower(trim($tx['provider'])) : (!empty($tx['provider_name']) ? strtolower(trim($tx['provider_name'])) : 'techhub');
        $providerLabel  = match ($activeProvider) {
            's8v'         => 'S8V.ng',
            'recorddocs'  => 'RecordDocs',
            default       => 'TechHub',
        };

        // ── IDEMPOTENCY SAFETY GUARDS ───────────────────────────────────────
        if ($currentGv === 'refunded' && !empty($tx['refund_issued'])) {
            return ['action' => 'skipped', 'message' => 'Transaction has already been refunded.'];
        }
        if ($currentGv === 'completed' && !empty($tx['result_data'])) {
            return ['action' => 'skipped', 'message' => 'Transaction is already completed with results.'];
        }

        $ticketId = !empty($tx['provider_ticket_id']) ? trim($tx['provider_ticket_id']) : null;
        if (!$ticketId && !empty($tx['input_summary'])) {
            if (preg_match('/tracking[:=]\s*([a-zA-Z0-9_-]+)/i', $tx['input_summary'], $m)) {
                $ticketId = $m[1];
            }
        }

        $submittedTs = !empty($tx['submitted_at']) ? strtotime($tx['submitted_at']) : time();
        $ageMinutes  = max(0, (time() - $submittedTs) / 60);

        // ── BRANCH 1: SYNCHRONOUS SLIP GENERATION (NIN/BVN Slip) ───────────
        if ($resultType === 'pdf_base64' || in_array($serviceSlug, ['nin-verification', 'bvn-verification'], true)) {
            // If it already has result data, complete it immediately
            if (!empty($tx['result_data'])) {
                $this->db->prepare("
                    UPDATE api_transactions
                    SET gv_status = 'completed',
                        provider_status = 'completed',
                        completed_at = COALESCE(completed_at, NOW()),
                        synced_at = NOW(),
                        synced_by = ?
                    WHERE id = ?
                ")->execute([$actor, $id]);

                return [
                    'action'  => 'completed',
                    'message' => 'Slip result was already stored locally. Status marked completed.'
                ];
            }

            // Sync slip has no remote ticket to poll.
            // If SLA window has passed (default 15 minutes) and slip is unfulfilled:
            if ($ageMinutes >= $slaMinutes || $currentGv === 'reconciliation_required') {
                return $this->executeSafeRefund(
                    $tx,
                    $pricePaid,
                    0.00,
                    $actor,
                    "Auto-reconciled: Slip generation timed out (SLA exceeded). Safe wallet refund issued."
                );
            }

            return [
                'action'      => 'pending_sla_window',
                'age_minutes' => round($ageMinutes, 1),
                'message'     => "Transaction within SLA window ({$slaMinutes}m). Will be auto-refunded if unfulfilled."
            ];
        }

        // ── BRANCH 2: ASYNC TICKET WITH PROVIDER TICKET ID ──────────────────
        if ($ticketId) {
            try {
                if ($activeProvider === 's8v') {
                    $statusResult = $this->s8vService->checkAsyncStatus($serviceSlug, $variantKey, $ticketId, $tx['input_summary'] ?? null);
                } elseif ($activeProvider === 'recorddocs') {
                    $statusResult = $this->recordDocsService->checkAsyncStatus($serviceSlug, $variantKey, $ticketId);
                } else {
                    $statusResult = $this->techHubService->checkAsyncStatus($serviceSlug, $variantKey, $ticketId);
                }
            } catch (Throwable $e) {
                return [
                    'action'  => 'error',
                    'message' => "Provider status query failed: " . $e->getMessage()
                ];
            }

            // Handle provider query failure / ticket not found
            if (!$statusResult['success']) {
                $rawErrMsg = $statusResult['error_message'] ?? 'Provider error';
                if (stripos($rawErrMsg, 'ticket') !== false && (stripos($rawErrMsg, 'not found') !== false || stripos($rawErrMsg, 'invalid') !== false)) {
                    // Ticket does not exist on provider -> safe to refund
                    return $this->executeSafeRefund(
                        $tx,
                        $pricePaid,
                        0.00,
                        $actor,
                        "Auto-reconciled: {$providerLabel} ticket '{$ticketId}' not found. Safely refunded to wallet."
                    );
                }

                // If ambiguous provider error and older than SLA window:
                if ($ageMinutes >= $slaMinutes) {
                    return $this->executeSafeRefund(
                        $tx,
                        $pricePaid,
                        0.00,
                        $actor,
                        "Auto-reconciled: Provider query failed after SLA timeout. Safely refunded to wallet."
                    );
                }

                return [
                    'action'  => 'still_pending',
                    'message' => "{$providerLabel} query returned: " . $rawErrMsg
                ];
            }

            // Provider answered successfully
            $pStatus   = strtolower((string)($statusResult['provider_status'] ?? 'pending'));
            $isComplete= !empty($statusResult['is_complete']);
            $isFailed  = !empty($statusResult['is_failed']) || $pStatus === 'failed';

            $isSuccess = in_array($pStatus, ['success', 'completed', 'successful'], true) ||
                         ($isComplete && !$isFailed) ||
                         !empty($statusResult['result_data']['pdf_base64']) ||
                         !empty($statusResult['result_data']['slip']);

            if ($isSuccess) {
                $rawResultData = $statusResult['result_data'] ?? [];
                $resType       = $resultType ?: 'ticket';

                // Auto-generate slip for personalization if needed
                if (in_array($serviceSlug, ['personalization', 'nin-personalization'], true) && is_array($rawResultData)) {
                    if (empty($rawResultData['pdf_base64'])) {
                        require_once __DIR__ . '/SlipGeneratorService.php';
                        $slipRes = SlipGeneratorService::generateNinSlip($rawResultData);
                        if ($slipRes['success']) {
                            $rawResultData['pdf_base64']    = $slipRes['pdf_base64'];
                            $rawResultData['file_name']     = $slipRes['filename'];
                            $rawResultData['formatted_nin'] = $slipRes['nin'];
                            $resType = 'pdf_base64';
                        }
                    } else {
                        $resType = 'pdf_base64';
                    }
                }

                $this->db->prepare("
                    UPDATE api_transactions
                    SET gv_status = 'completed',
                        provider_status = 'completed',
                        provider_financial_status = 'charged',
                        result_type = ?,
                        result_data = ?,
                        synced_at = NOW(),
                        synced_by = ?,
                        completed_at = COALESCE(completed_at, NOW()),
                        reconciliation_notes = ?
                    WHERE id = ?
                ")->execute([
                    $resType,
                    json_encode($rawResultData),
                    $actor,
                    "Auto-reconciled: {$providerLabel} confirmed completion.",
                    $id
                ]);

                return [
                    'action'  => 'completed',
                    'message' => "Successfully completed via {$providerLabel}."
                ];

            } elseif ($isFailed) {
                // Provider confirmed request failed
                $penaltyFee = 0.00;
                if ($activeProvider === 's8v') {
                    $penaltyFee = (float)($tx['failure_penalty_fee'] ?? 100.00);
                    if ($penaltyFee <= 0 && $serviceSlug === 'nin-personalization') {
                        $penaltyFee = 100.00;
                    }
                }

                $note = "Auto-reconciled: {$providerLabel} reported failure (" . ($statusResult['error_message'] ?? 'Failed') . ").";
                return $this->executeSafeRefund($tx, $pricePaid, $penaltyFee, $actor, $note);

            } else {
                // Still pending on provider
                $this->db->prepare("
                    UPDATE api_transactions
                    SET last_checked_at = NOW(),
                        synced_at = NOW(),
                        synced_by = ?
                    WHERE id = ?
                ")->execute([$actor, $id]);

                return [
                    'action'  => 'still_pending',
                    'message' => "Still processing on {$providerLabel} (ticket={$ticketId})."
                ];
            }
        }

        // ── BRANCH 3: NO TICKET ID ASSOCIATED ───────────────────────────────
        if ($ageMinutes >= $slaMinutes || $currentGv === 'reconciliation_required') {
            return $this->executeSafeRefund(
                $tx,
                $pricePaid,
                0.00,
                $actor,
                "Auto-reconciled: No remote ticket found after SLA window. Automatically refunded to wallet."
            );
        }

        return [
            'action'      => 'pending_sla_window',
            'age_minutes' => round($ageMinutes, 1),
            'message'     => 'Awaiting SLA window before auto-refund.'
        ];
    }

    /**
     * Atomically credit user wallet and update transaction to refunded.
     */
    private function executeSafeRefund(
        array $tx,
        float $pricePaid,
        float $penaltyFee,
        string $actor,
        string $reconcileNote
    ): array {
        $id          = (int)$tx['id'];
        $ref         = $tx['gv_reference'];
        $userId      = (int)$tx['user_id'];
        $serviceName = $tx['service_name'] ?? 'Verification';

        $refundAmount = max(0.00, $pricePaid - $penaltyFee);

        // Guard: check if refund was already issued
        if (!empty($tx['refund_issued'])) {
            return [
                'action'  => 'skipped',
                'message' => 'Refund has already been credited previously.'
            ];
        }

        // Issue atomic credit if amount > 0
        if ($refundAmount > 0) {
            $penaltyText = $penaltyFee > 0
                ? " (₦" . number_format($refundAmount, 2) . " refunded, ₦" . number_format($penaltyFee, 2) . " fee retained)"
                : "";
            $desc = "Auto-Refund: {$serviceName}{$penaltyText} — Ref: {$ref}";

            $this->walletService->creditAtomically(
                $userId,
                $refundAmount,
                $desc,
                $id
            );
        }

        $cols = $this->getColumns();
        $hasPenaltyCol = in_array('penalty_deducted', $cols, true);
        $hasRefundCol  = in_array('refund_amount', $cols, true);

        $updateSets = [
            "gv_status = 'refunded'",
            "provider_status = 'failed'",
            "provider_financial_status = 'reversed'",
            "refund_issued = 1",
            "synced_at = NOW()",
            "synced_by = ?",
            "completed_at = COALESCE(completed_at, NOW())",
            "reconciliation_notes = ?"
        ];
        $params = [$actor, $reconcileNote];

        if ($hasPenaltyCol) {
            $updateSets[] = "penalty_deducted = ?";
            $params[] = $penaltyFee;
        }
        if ($hasRefundCol) {
            $updateSets[] = "refund_amount = ?";
            $params[] = $refundAmount;
        }

        $params[] = $id;

        $sql = "UPDATE api_transactions SET " . implode(", ", $updateSets) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($params);

        $this->auditService->log(
            'API_AUTO_RECONCILE_REFUND',
            $id,
            str_starts_with($actor, 'admin') ? 'admin' : 'system',
            str_starts_with($actor, 'admin_') ? (int)str_replace('admin_', '', $actor) : null,
            ['gv_status' => $tx['gv_status'], 'refund_issued' => 0],
            ['gv_status' => 'refunded', 'refund_issued' => 1, 'refund_amount' => $refundAmount],
            $reconcileNote
        );

        return [
            'action'        => 'refunded',
            'refund_amount' => $refundAmount,
            'penalty_fee'   => $penaltyFee,
            'message'       => $reconcileNote
        ];
    }
}
