<?php
/**
 * GemVerify — API Status Controller
 *
 * Handles status checking, history listing, and result download for all
 * TechHub API-backed service requests (stored in api_transactions).
 *
 * Endpoints (registered in routes/user.php):
 *   GET  /api-services/requests           — paginated history of all API requests
 *   GET  /api-services/requests/{ref}     — single request detail + current status
 *   POST /api-services/requests/{ref}/poll — poll TechHub for latest status (async only)
 *   GET  /api-services/requests/{ref}/pdf  — stream / return PDF for completed slip requests
 *
 * Security:
 *   - All endpoints are authenticated (AuthMiddleware already applied in router)
 *   - Users can only access their own api_transactions (user_id = auth user)
 *   - Result data (PDF base64) is never exposed in list; only in /pdf endpoint
 *
 * @package Controllers
 */

namespace Controllers;

use Core\Database;
use Helpers\Response;
use Services\TechHubService;
use Services\S8VService;
use Services\WalletService;
use Services\AuditService;
use PDO;
use Exception;

class ApiStatusController
{
    private PDO $db;
    private TechHubService $techHubService;
    private S8VService $s8vService;
    private WalletService $walletService;
    private AuditService $auditService;
    private int $userId;

    // Maximum seconds between provider polls on async requests
    private const MIN_POLL_INTERVAL_SECONDS = 30;

    // Maximum rows per page for history list
    private const PAGE_SIZE = 20;

    public function __construct()
    {
        $this->db             = db();
        $this->techHubService = new TechHubService();
        $this->s8vService     = new S8VService();
        $this->walletService  = new WalletService($this->db);
        $this->auditService   = new AuditService($this->db);
        $this->userId         = \Middleware\AuthMiddleware::getUserId();
    }


    // ── Public Endpoints ───────────────────────────────────────────────────

    /**
     * GET /api-services/requests
     *
     * Returns a paginated list of all API requests for the authenticated user.
     * Does NOT include result_data (PDF) to keep responses lean.
     *
     * Query params:
     *   page   int   Page number (1-based, default 1)
     *   status string Filter by gv_status (optional)
     *   slug   string Filter by service slug (optional, via service name join)
     */
    public function listRequests(): void
    {
        $page   = max(1, (int)($_GET['page']   ?? 1));
        $status = trim($_GET['status'] ?? '');
        $slug   = trim($_GET['slug']   ?? '');
        $offset = ($page - 1) * self::PAGE_SIZE;

        $where   = ['at.user_id = ?'];
        $params  = [$this->userId];

        if ($status !== '') {
            $allowed = ['pending', 'processing', 'completed', 'failed', 'refunded'];
            if (in_array($status, $allowed, true)) {
                $where[]  = 'at.gv_status = ?';
                $params[] = $status;
            }
        }

        if ($slug !== '') {
            $where[]  = 's.slug = ?';
            $params[] = $slug;
        }

        $whereClause = implode(' AND ', $where);

        try {
            // Total count
            $countParams = $params;
            $totalStmt = $this->db->prepare("
                SELECT COUNT(*)
                FROM api_transactions at
                JOIN services s ON s.id = at.service_id
                WHERE {$whereClause}
            ");
            $totalStmt->execute($countParams);
            $total = (int)$totalStmt->fetchColumn();

            // Paginated results
            $listParams   = $params;
            $listParams[] = self::PAGE_SIZE;
            $listParams[] = $offset;

            $stmt = $this->db->prepare("
                SELECT
                    at.gv_reference,
                    at.gv_status,
                    at.provider_status,
                    at.result_type,
                    at.variant_key,
                    at.input_method,
                    at.input_summary,
                    at.error_code,
                    at.error_message,
                    at.provider_ticket_id,
                    at.submitted_at,
                    at.completed_at,
                    at.last_checked_at,
                    s.name   AS service_name,
                    s.slug   AS service_slug,
                    sp.price AS price_paid
                FROM api_transactions at
                JOIN services s  ON s.id  = at.service_id
                JOIN service_pricing sp ON sp.id = at.pricing_id
                WHERE {$whereClause}
                ORDER BY at.submitted_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute($listParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format rows — never expose result_data here
            $items = array_map([$this, 'formatListRow'], $rows);

            Response::success([
                'items'       => $items,
                'total'       => $total,
                'page'        => $page,
                'page_size'   => self::PAGE_SIZE,
                'total_pages' => (int)ceil($total / self::PAGE_SIZE),
            ]);

        } catch (Exception $e) {
            Response::error('Failed to load requests: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * GET /api-services/requests/{ref}
     *
     * Returns full detail for a single api_transaction.
     * Includes all fields EXCEPT result_data (PDF).
     * Client should call /pdf endpoint for the actual PDF.
     */
    public function getRequest(string $ref): void
    {
        $tx = $this->findTransaction($ref);
        if (!$tx) {
            Response::error('Request not found.', [], 404);
            return;
        }

        Response::success($this->formatDetailRow($tx));
    }

    /**
     * POST /api-services/requests/{ref}/poll
     *
     * Polls TechHub for the latest status of an async (ticket) request.
     * Rate-limited to once per MIN_POLL_INTERVAL_SECONDS.
     *
     * On status change to 'success', updates gv_status → 'completed'
     * and stores result_data. On 'failed', updates gv_status → 'failed'.
     */
    public function pollStatus(string $ref): void
    {
        $tx = $this->findTransaction($ref);
        if (!$tx) {
            Response::error('Request not found.', [], 404);
            return;
        }

        // Only async (ticket) requests can be polled
        if ($tx['result_type'] !== 'ticket') {
            Response::error('This request is not an async ticket — cannot poll status.', [], 400);
            return;
        }

        // Already in a terminal state
        if (in_array($tx['gv_status'], ['completed', 'failed', 'refunded'], true)) {
            Response::success($this->formatDetailRow($tx));
            return;
        }

        // Rate limit: prevent hammering the provider
        if (!empty($tx['last_checked_at'])) {
            $lastChecked = strtotime($tx['last_checked_at']);
            $elapsed     = time() - $lastChecked;
            if ($elapsed < self::MIN_POLL_INTERVAL_SECONDS) {
                $waitSecs = self::MIN_POLL_INTERVAL_SECONDS - $elapsed;
                Response::error(
                    "Please wait {$waitSecs} seconds before polling again.",
                    ['retry_after' => $waitSecs],
                    429
                );
                return;
            }
        }

        // No ticket ID — can't poll
        if (empty($tx['provider_ticket_id'])) {
            Response::error('No ticket ID available for this request.', [], 422);
            return;
        }

        try {
            // Update last_checked_at before calling provider
            $this->db->prepare("UPDATE api_transactions SET last_checked_at = NOW() WHERE id = ?")
                     ->execute([$tx['id']]);

            $providerName = !empty($tx['provider']) ? strtolower(trim($tx['provider'])) : (!empty($tx['provider_name']) ? strtolower(trim($tx['provider_name'])) : 'techhub');

            // Call appropriate provider
            if ($providerName === 's8v') {
                $statusResult = $this->s8vService->checkAsyncStatus(
                    $tx['service_slug'],
                    $tx['variant_key'],
                    $tx['provider_ticket_id'],
                    $tx['input_summary'] // passed for tracking ID fallback lookup
                );
            } else {
                $statusResult = $this->techHubService->checkAsyncStatus(
                    $tx['service_slug'],
                    $tx['variant_key'],
                    $tx['provider_ticket_id']
                );
            }

            if (!$statusResult['success']) {
                Response::error(
                    'Status check failed: ' . ($statusResult['error_message'] ?? 'Unknown error'),
                    ['error_code' => $statusResult['error_code'] ?? null],
                    502
                );
                return;
            }

            // Update DB based on new status
            $this->applyStatusUpdate($tx, $statusResult);

            // Re-fetch updated row
            $updated = $this->findTransactionById($tx['id']);

            $this->auditService->log(
                'API_POLL_STATUS',
                $tx['id'],
                'user',
                $this->userId,
                null, null,
                "Polled status for {$ref}: provider_status={$statusResult['provider_status']}"
            );

            Response::success($this->formatDetailRow($updated));

        } catch (Exception $e) {
            Response::error('Status poll error: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * GET /api-services/requests/{ref}/pdf
     *
     * Returns the base64-encoded PDF for a completed sync (pdf_base64) request.
     * The PDF is served as JSON {pdf_base64: "..."} so the frontend can render
     * it inline or trigger a download without a separate streaming endpoint.
     *
     * For simplicity and security (no separate file serving), we return base64.
     * The frontend already handles this pattern from the initial submit response.
     */
    public function downloadPdf(string $ref): void
    {
        $tx = $this->findTransaction($ref);
        if (!$tx) {
            Response::error('Request not found.', [], 404);
            return;
        }

        if ($tx['result_type'] !== 'pdf_base64') {
            Response::error('This request does not have a PDF result.', [], 400);
            return;
        }

        if ($tx['gv_status'] !== 'completed') {
            Response::error('PDF is not available — request status is: ' . $tx['gv_status'], [], 422);
            return;
        }

        if (empty($tx['result_data'])) {
            Response::error('PDF data not found for this request.', [], 404);
            return;
        }

        $this->auditService->log(
            'API_PDF_DOWNLOAD',
            $tx['id'],
            'user',
            $this->userId,
            null, null,
            "PDF download for {$ref}"
        );

        Response::success([
            'gv_reference' => $ref,
            'service_name' => $tx['service_name'],
            'pdf_base64'   => $tx['result_data'],
        ]);
    }

    // ── Private Helpers ────────────────────────────────────────────────────

    /**
     * Find a single api_transaction by GV reference for the current user.
     * Joins services and service_pricing to get names/prices.
     */
    private function findTransaction(string $ref): ?array
    {
        \Helpers\SchemaHelper::ensureProviderColumns($this->db);
        $cols = $this->db->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
        $penaltyCol  = in_array('failure_penalty_fee', $cols, true) ? 's.failure_penalty_fee' : '0.00 AS failure_penalty_fee';
        $providerCol = in_array('provider_name', $cols, true) ? 's.provider_name' : "'techhub' AS provider_name";

        $stmt = $this->db->prepare("
            SELECT
                at.*,
                s.name AS service_name,
                s.slug AS service_slug,
                {$penaltyCol},
                {$providerCol},
                sp.price AS price_paid
            FROM api_transactions at
            JOIN services s  ON s.id  = at.service_id
            JOIN service_pricing sp ON sp.id = at.pricing_id
            WHERE at.gv_reference = ? AND at.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$ref, $this->userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Find a single api_transaction by internal ID.
     */
    private function findTransactionById(int $id): ?array
    {
        \Helpers\SchemaHelper::ensureProviderColumns($this->db);
        $cols = $this->db->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
        $penaltyCol  = in_array('failure_penalty_fee', $cols, true) ? 's.failure_penalty_fee' : '0.00 AS failure_penalty_fee';
        $providerCol = in_array('provider_name', $cols, true) ? 's.provider_name' : "'techhub' AS provider_name";

        $stmt = $this->db->prepare("
            SELECT
                at.*,
                s.name AS service_name,
                s.slug AS service_slug,
                {$penaltyCol},
                {$providerCol},
                sp.price AS price_paid
            FROM api_transactions at
            JOIN services s  ON s.id  = at.service_id
            JOIN service_pricing sp ON sp.id = at.pricing_id
            WHERE at.id = ? AND at.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $this->userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Apply a provider status update to an api_transaction row.
     * Handles successful completion and in-flight failure partial refunds.
     */
    private function applyStatusUpdate(array $tx, array $statusResult): void
    {
        $txId           = (int)$tx['id'];
        $providerStatus = $statusResult['provider_status'] ?? 'pending';

        if ($statusResult['is_complete'] && !$statusResult['is_failed']) {
            // Provider succeeded — mark completed
            $resultData = !empty($statusResult['result_data'])
                ? json_encode($statusResult['result_data'])
                : null;

            $this->db->prepare("
                UPDATE api_transactions
                SET gv_status            = 'completed',
                    provider_status      = ?,
                    result_data          = ?,
                    completed_at         = NOW()
                WHERE id = ?
            ")->execute([$providerStatus, $resultData, $txId]);

        } elseif ($statusResult['is_failed']) {
            // Provider failed — execute non-refundable processing fee partial refund
            $pricePaid  = (float)($tx['price_paid'] ?? 0.00);
            $penaltyFee = (float)($tx['failure_penalty_fee'] ?? 0.00);

            // Refund = Paid minus Penalty Fee (e.g. ₦1,000 - ₦100 = ₦900)
            $refundAmount = max(0.00, $pricePaid - $penaltyFee);

            // Execute wallet credit if not already refunded
            if ((int)($tx['refund_issued'] ?? 0) === 0 && $refundAmount > 0) {
                $serviceName = $tx['service_name'] ?? 'Service';
                $penaltyText = $penaltyFee > 0 ? " (₦" . number_format($refundAmount, 2) . " refunded, ₦" . number_format($penaltyFee, 2) . " processing fee applied)" : "";
                $desc = "Refund: {$serviceName}{$penaltyText} — Ref: {$tx['gv_reference']}";

                $this->walletService->creditAtomically(
                    (int)$tx['user_id'],
                    $refundAmount,
                    $desc,
                    $txId
                );
            }

            $userReason = $statusResult['error_message'] ?? 'No record found on identity registry for this request.';
            $feeNotice  = $penaltyFee > 0 ? " ₦" . number_format($penaltyFee, 2) . " processing fee applied. ₦" . number_format($refundAmount, 2) . " returned to wallet." : " Full fee refunded to wallet.";

            $txCols = $this->db->query("SHOW COLUMNS FROM api_transactions")->fetchAll(PDO::FETCH_COLUMN);
            $hasPenaltyCol = in_array('penalty_deducted', $txCols, true);
            $hasRefundCol  = in_array('refund_amount', $txCols, true);

            $updateSets = [
                "gv_status        = 'failed'",
                "provider_status  = ?",
                "refund_issued    = 1",
            ];
            $params = [$providerStatus];

            if ($hasPenaltyCol) {
                $updateSets[] = "penalty_deducted = ?";
                $params[] = $penaltyFee;
            }
            if ($hasRefundCol) {
                $updateSets[] = "refund_amount = ?";
                $params[] = $refundAmount;
            }

            $updateSets[] = "error_message    = ?";
            $params[] = $userReason . $feeNotice;

            $updateSets[] = "completed_at     = NOW()";

            $params[] = $txId;

            $sql = "UPDATE api_transactions SET " . implode(", ", $updateSets) . " WHERE id = ?";
            $this->db->prepare($sql)->execute($params);

        } else {
            // Still in progress — update provider_status only
            $this->db->prepare("
                UPDATE api_transactions
                SET provider_status = ?
                WHERE id = ?
            ")->execute([$providerStatus, $txId]);
        }
    }


    /**
     * Format a row for the list endpoint (lean — no PDF data).
     */
    private function formatListRow(array $row): array
    {
        return [
            'gv_reference'     => $row['gv_reference'],
            'service_name'     => $row['service_name'],
            'service_slug'     => $row['service_slug'],
            'variant_key'      => $row['variant_key'],
            'input_method'     => $row['input_method'],
            'gv_status'        => $row['gv_status'],
            'provider_status'  => $row['provider_status'],
            'result_type'      => $row['result_type'],
            'has_pdf'          => ($row['result_type'] === 'pdf_base64' && $row['gv_status'] === 'completed'),
            'ticket_id'        => $row['provider_ticket_id'] ?? null,
            'price_paid'       => (float)($row['price_paid'] ?? 0),
            'error_code'       => $row['error_code']  ?? null,
            'error_message'    => $row['error_message'] ?? null,
            'submitted_at'     => $row['submitted_at'],
            'completed_at'     => $row['completed_at'] ?? null,
            'last_checked_at'  => $row['last_checked_at'] ?? null,
        ];
    }

    /**
     * Format a row for the detail endpoint (full — except PDF data itself).
     */
    private function formatDetailRow(array $row): array
    {
        $base = $this->formatListRow($row);
        $base['input_summary']    = $row['input_summary'] ?? null;
        $base['provider']         = $row['provider']      ?? 'techhub';
        $base['provider_txn_id']  = $row['provider_txn_id'] ?? null;
        return $base;
    }
}
