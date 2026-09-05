<?php
/**
 * GemVerify — Admin API Transaction Controller
 *
 * Provides admin-level visibility into all TechHub API service requests stored
 * in the api_transactions table. Admins can list, filter, view details, and
 * mark transactions for manual refund processing.
 *
 * Endpoints (registered in routes/admin.php):
 *   GET  /admin/api-transactions                      — paginated list with filters
 *   GET  /admin/api-transactions/{ref}                — full detail of one transaction
 *   GET  /admin/api-transactions/stats                — aggregate counts by status/service
 *   PATCH /admin/api-transactions/{ref}/status        — override gv_status (admin fix)
 *   POST  /admin/api-transactions/{ref}/refund-flag   — flag transaction for wallet refund
 *
 * Security:
 *   - All endpoints require AdminMiddleware (applied in router before invoking controller)
 *   - refund-flag and status override require 'admin' role minimum
 *   - result_data (base64 PDF) is NEVER returned in list or detail — too large
 *   - PDF available only via dedicated /pdf endpoint guarded separately
 *
 * @package Controllers\Admin
 */

namespace Controllers\Admin;

use Helpers\Response;
use Services\AuditService;
use Services\WalletService;
use Services\TechHubService;
use Services\S8VService;
use PDO;
use Exception;

class ApiTransactionController
{
    private PDO $db;
    private AuditService $auditService;
    private WalletService $walletService;
    private TechHubService $techHubService;
    private S8VService $s8vService;
    private int $adminId;

    private const PAGE_SIZE = 25;

    // Valid gv_status values that admin can manually override to
    private const OVERRIDE_STATUSES = ['pending', 'processing', 'completed', 'failed', 'refunded', 'reconciliation_required'];

    public function __construct()
    {
        $this->db             = db();
        $this->auditService   = new AuditService($this->db);
        $this->walletService  = new WalletService($this->db);
        $this->techHubService = new TechHubService();
        $this->s8vService     = new S8VService();
        $this->adminId        = (int)($_SERVER['ADMIN_ID'] ?? 1);
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    /**
     * GET /admin/api-transactions
     *
     * Returns paginated list of all API transactions across all users.
     * Excludes result_data (PDF/result JSON) — keeps response lean.
     *
     * Query params:
     *   page          int     Page number (default 1)
     *   per_page      int     Rows per page (max 100, default 25)
     *   status        string  Filter by gv_status
     *   service_slug  string  Filter by service slug
     *   user_id       int     Filter by user
     *   provider      string  Filter by provider name (default: any)
     *   result_type   string  'pdf_base64' | 'ticket'
     *   date_from     string  YYYY-MM-DD
     *   date_to       string  YYYY-MM-DD
     *   search        string  Search gv_reference or provider_ticket_id
     */
    public function listTransactions(): void
    {
        try {
            $page     = max(1, (int)($_GET['page']     ?? 1));
            $perPage  = min(100, max(1, (int)($_GET['per_page'] ?? self::PAGE_SIZE)));
            $offset   = ($page - 1) * $perPage;

            // Filters
            $status      = $_GET['status']       ?? null;
            $slug        = $_GET['service_slug'] ?? null;
            $userId      = $_GET['user_id']      ?? null;
            $provider    = $_GET['provider']     ?? null;
            $resultType  = $_GET['result_type']  ?? null;
            $dateFrom    = $_GET['date_from']    ?? null;
            $dateTo      = $_GET['date_to']      ?? null;
            $search      = trim($_GET['search']  ?? '');

            $where  = ['1=1'];
            $params = [];

            if ($status) {
                if (!in_array($status, self::OVERRIDE_STATUSES, true)) {
                    Response::error('Invalid status filter.', [], 400);
                    return;
                }
                $where[] = 'at.gv_status = ?';
                $params[] = $status;
            }
            if ($slug) {
                $where[] = 's.slug = ?';
                $params[] = $slug;
            }
            if ($userId) {
                $where[] = 'at.user_id = ?';
                $params[] = (int)$userId;
            }
            if ($provider) {
                $where[] = 'at.provider = ?';
                $params[] = $provider;
            }
            if ($resultType && in_array($resultType, ['pdf_base64', 'ticket'], true)) {
                $where[] = 'at.result_type = ?';
                $params[] = $resultType;
            }
            if ($dateFrom) {
                $where[] = 'DATE(at.submitted_at) >= ?';
                $params[] = $dateFrom;
            }
            if ($dateTo) {
                $where[] = 'DATE(at.submitted_at) <= ?';
                $params[] = $dateTo;
            }
            if ($search !== '') {
                $where[] = '(at.gv_reference LIKE ? OR at.provider_ticket_id LIKE ?)';
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
            }

            $whereClause = implode(' AND ', $where);

            // Total count
            $countStmt = $this->db->prepare("
                SELECT COUNT(at.id)
                FROM api_transactions at
                JOIN services s ON s.id = at.service_id
                JOIN users u    ON u.id = at.user_id
                WHERE {$whereClause}
            ");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Paginated rows
            $listParams   = $params;
            $listParams[] = $perPage;
            $listParams[] = $offset;

            $stmt = $this->db->prepare("
                SELECT
                    at.id,
                    at.gv_reference,
                    at.gv_status,
                    at.provider,
                    at.provider_status,
                    at.result_type,
                    at.variant_key,
                    at.input_method,
                    at.input_summary,
                    at.provider_ticket_id,
                    at.provider_txn_id,
                    at.error_code,
                    at.error_message,
                    at.refund_issued,
                    at.submitted_at,
                    at.provider_responded_at,
                    at.completed_at,
                    at.last_checked_at,
                    s.name   AS service_name,
                    s.slug   AS service_slug,
                    sp.price AS price_paid,
                    u.business_name AS user_name,
                    u.email         AS user_email,
                    u.id            AS user_id
                FROM api_transactions at
                JOIN services       s  ON s.id  = at.service_id
                JOIN service_pricing sp ON sp.id = at.pricing_id
                JOIN users          u  ON u.id  = at.user_id
                WHERE {$whereClause}
                ORDER BY at.submitted_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute($listParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items = array_map([$this, 'formatListRow'], $rows);

            Response::success([
                'items'       => $items,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int)ceil($total / $perPage),
            ]);

        } catch (Exception $e) {
            Response::error('Failed to load API transactions: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * GET /admin/api-transactions/stats
     *
     * Returns aggregate counts and revenue for API transactions.
     * Useful for admin dashboard widgets.
     */
    public function getStats(): void
    {
        try {
            // Status breakdown
            $statusStmt = $this->db->query("
                SELECT gv_status, COUNT(*) AS cnt, COALESCE(SUM(sp.price), 0) AS revenue
                FROM api_transactions at
                JOIN service_pricing sp ON sp.id = at.pricing_id
                GROUP BY gv_status
            ");
            $statusRows = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

            // Service breakdown (top 10)
            $svcStmt = $this->db->query("
                SELECT s.name AS service, s.slug, COUNT(at.id) AS cnt,
                       COALESCE(SUM(sp.price), 0) AS revenue
                FROM api_transactions at
                JOIN services s        ON s.id  = at.service_id
                JOIN service_pricing sp ON sp.id = at.pricing_id
                GROUP BY at.service_id
                ORDER BY cnt DESC
                LIMIT 10
            ");
            $svcRows = $svcStmt->fetchAll(PDO::FETCH_ASSOC);

            // Today's totals
            $todayStmt = $this->db->query("
                SELECT COUNT(*) AS cnt, COALESCE(SUM(sp.price), 0) AS revenue
                FROM api_transactions at
                JOIN service_pricing sp ON sp.id = at.pricing_id
                WHERE DATE(at.submitted_at) = CURDATE()
            ");
            $today = $todayStmt->fetch(PDO::FETCH_ASSOC);

            // Total pending (non-terminal)
            $pendingStmt = $this->db->query("
                SELECT COUNT(*) FROM api_transactions
                WHERE gv_status IN ('pending','processing')
            ");
            $pendingCount = (int)$pendingStmt->fetchColumn();

            // Failed (not yet refunded)
            $failedStmt = $this->db->query("
                SELECT COUNT(*) FROM api_transactions
                WHERE gv_status = 'failed' AND refund_issued = 0
            ");
            $failedPendingRefund = (int)$failedStmt->fetchColumn();

            Response::success([
                'by_status'            => $statusRows,
                'by_service'           => $svcRows,
                'today'                => $today,
                'pending_count'        => $pendingCount,
                'failed_pending_refund'=> $failedPendingRefund,
            ]);

        } catch (Exception $e) {
            Response::error('Failed to load API transaction stats: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * GET /admin/api-transactions/{ref}
     *
     * Returns full admin detail for a single API transaction.
     * Excludes result_data to keep response lean.
     */
    public function getDetail(string $ref): void
    {
        try {
            $tx = $this->findByRef($ref);
            if (!$tx) {
                Response::error('API transaction not found.', [], 404);
                return;
            }

            Response::success($this->formatDetailRow($tx));

        } catch (Exception $e) {
            Response::error('Failed to load transaction: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * PATCH /admin/api-transactions/{ref}/status
     *
     * Admin-level override of gv_status. Use carefully — this bypasses
     * normal TechHub polling. Intended for manual correction only.
     *
     * Body: { "status": "completed|failed|refunded|pending|processing", "note": "reason" }
     */
    public function overrideStatus(string $ref): void
    {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $status = trim($input['status'] ?? '');
        $note   = trim($input['note']   ?? '');

        if (!in_array($status, self::OVERRIDE_STATUSES, true)) {
            Response::error(
                'Invalid status. Allowed: ' . implode(', ', self::OVERRIDE_STATUSES),
                [], 400
            );
            return;
        }
        if ($note === '') {
            Response::error('A note is required when overriding status.', [], 400);
            return;
        }

        try {
            $tx = $this->findByRef($ref);
            if (!$tx) {
                Response::error('API transaction not found.', [], 404);
                return;
            }

            $oldStatus = $tx['gv_status'];

            $this->db->prepare("
                UPDATE api_transactions
                SET gv_status    = ?,
                    completed_at = CASE WHEN ? IN ('completed','failed','refunded')
                                        THEN COALESCE(completed_at, NOW())
                                        ELSE completed_at END
                WHERE id = ?
            ")->execute([$status, $status, $tx['id']]);

            $this->auditService->log(
                'ADMIN_API_TXN_STATUS_OVERRIDE',
                $tx['id'],
                'admin',
                $this->adminId,
                ['old_status' => $oldStatus],
                ['new_status' => $status],
                $note
            );

            Response::success([
                'gv_reference' => $ref,
                'old_status'   => $oldStatus,
                'new_status'   => $status,
                'message'      => 'Status updated successfully.',
            ]);

        } catch (Exception $e) {
            Response::error('Failed to override status: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * POST /admin/api-transactions/{ref}/refund-flag
     *
     * Issues an immediate wallet refund for a failed/stuck API transaction.
     * Calls WalletService::creditAtomically() to credit the user's wallet,
     * sets refund_issued = 1 and gv_status = 'refunded', and records an audit trail.
     *
     * Body: { "note": "reason for refund" }
     */
    public function flagForRefund(string $ref): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $note  = trim($input['note'] ?? '');

        if ($note === '') {
            Response::error('A note is required for refund flagging.', [], 400);
            return;
        }

        try {
            $tx = $this->findByRef($ref);
            if (!$tx) {
                Response::error('API transaction not found.', [], 404);
                return;
            }

            if ($tx['refund_issued']) {
                Response::error('This transaction has already been refunded.', [], 409);
                return;
            }

            // Only failed or stuck transactions should be refunded
            if (!in_array($tx['gv_status'], ['failed', 'pending', 'processing'], true)) {
                Response::error(
                    'Refund only applies to failed/pending/processing transactions. Current status: ' . $tx['gv_status'],
                    [], 422
                );
                return;
            }

            $pricePaid = (float)($tx['price_paid'] ?? 0);
            $userId    = (int)$tx['user_id'];

            if ($pricePaid <= 0) {
                Response::error('Transaction has no chargeable amount to refund.', [], 422);
                return;
            }

            $this->db->beginTransaction();

            // Credit user wallet atomically
            $this->walletService->creditAtomically(
                $userId,
                $pricePaid,
                'Admin refund: ' . $ref . ' — ' . $note,
                null
            );

            // Mark transaction as refunded
            $this->db->prepare("
                UPDATE api_transactions
                SET refund_issued = 1,
                    gv_status     = 'refunded',
                    completed_at  = COALESCE(completed_at, NOW())
                WHERE id = ?
            ")->execute([$tx['id']]);

            $this->auditService->log(
                'ADMIN_API_TXN_REFUNDED',
                $tx['id'],
                'admin',
                $this->adminId,
                ['gv_status' => $tx['gv_status'], 'price' => $pricePaid, 'user_id' => $userId],
                ['refund_issued' => 1, 'new_status' => 'refunded', 'wallet_credited' => true],
                $note
            );

            $this->db->commit();

            Response::success([
                'gv_reference'    => $ref,
                'message'         => "Refund of ₦" . number_format($pricePaid, 2) . " credited to user wallet successfully.",
                'amount_refunded' => $pricePaid,
                'user_id'         => $userId,
            ]);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
            $this->db->rollBack();
            }
            Response::error('Failed to process refund: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * POST /admin/api-transactions/batch-refund
     *
     * Bulk-refunds ALL api_transactions that are failed/processing/pending
     * with refund_issued = 0. Intended to recover user money from pre-fix
     * failed jobs that were never automatically refunded.
     *
     * Super admin only. Idempotent — safe to run multiple times.
     */
    public function batchRefund(): void
    {
        try {
            AdminMiddleware::requireRole('super_admin');

            // Fetch all unrefunded failed/stuck transactions
            $stmt = $this->db->query("
                SELECT
                    at.id, at.gv_reference, at.user_id, at.gv_status,
                    COALESCE(sp.price, t.amount, 0) AS price_paid
                FROM api_transactions at
                LEFT JOIN service_pricing sp ON sp.id = at.pricing_id
                LEFT JOIN transactions   t  ON t.id  = at.transaction_id
                WHERE at.gv_status IN ('failed', 'pending', 'processing')
                  AND at.refund_issued = 0
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                Response::success([
                    'message'        => 'No unrefunded failed transactions found. All clear.',
                    'refunded_count' => 0,
                    'total_amount'   => 0,
                    'errors'         => []
                ]);
                return;
            }

            $refundedCount  = 0;
            $totalAmount    = 0.0;
            $errors         = [];

            foreach ($rows as $tx) {
                $pricePaid = (float)$tx['price_paid'];
                if ($pricePaid <= 0) {
                    // Nothing to refund — just mark as handled
                    $this->db->prepare("
                        UPDATE api_transactions
                        SET refund_issued = 1, gv_status = 'refunded',
                            completed_at = COALESCE(completed_at, NOW())
                        WHERE id = ?
                    ")->execute([$tx['id']]);
                    continue;
                }

                try {
                    $this->db->beginTransaction();

                    $this->walletService->creditAtomically(
                        (int)$tx['user_id'],
                        $pricePaid,
                        'Batch auto-refund: ' . $tx['gv_reference'] . ' (' . $tx['gv_status'] . ')',
                        null
                    );

                    $this->db->prepare("
                        UPDATE api_transactions
                        SET gv_status = 'refunded', refund_issued = 1,
                            completed_at = COALESCE(completed_at, NOW())
                        WHERE id = ?
                    ")->execute([$tx['id']]);

                    $this->auditService->log(
                        'ADMIN_BATCH_REFUND',
                        $tx['id'],
                        'admin',
                        $this->adminId,
                        ['gv_status' => $tx['gv_status'], 'price' => $pricePaid],
                        ['refund_issued' => 1, 'new_status' => 'refunded'],
                        'Batch refund by admin'
                    );

                    $this->db->commit();

                    $refundedCount++;
                    $totalAmount += $pricePaid;

                } catch (\Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $errors[] = [
                        'ref'     => $tx['gv_reference'],
                        'user_id' => $tx['user_id'],
                        'error'   => $e->getMessage()
                    ];
                }
            }

            Response::success([
                'message'        => "Batch refund complete. {$refundedCount} transaction(s) refunded totalling ₦" . number_format($totalAmount, 2) . '.',
                'refunded_count' => $refundedCount,
                'total_amount'   => $totalAmount,
                'skipped_count'  => count($errors),
                'errors'         => $errors
            ]);

        } catch (Exception $e) {
            Response::error('Batch refund failed: ' . $e->getMessage(), [], 500);
        }
    }

    // ── Private Helpers ────────────────────────────────────────────────────────


    /**
     * Find a single api_transaction by GV reference (no user restriction — admin view).
     */
    /**
     * POST /admin/api-transactions/{ref}/sync
     *
     * Live polls TechHub for the latest status and result of this transaction.
     * Updates gv_status, provider_status, provider_financial_status, result_data,
     * synced_at, and synced_by.
     */
    public function syncWithProvider(string $ref): void
    {
        try {
            $tx = $this->findByRef($ref);
            if (!$tx) {
                Response::error('API transaction not found.', [], 404);
                return;
            }

            // 1. Guard against synchronous slip services (NIN/BVN Verification)
            if (($tx['result_type'] ?? '') === 'pdf_base64') {
                if (!empty($tx['has_result'])) {
                    Response::success([
                        'gv_reference' => $ref,
                        'gv_status'    => $tx['gv_status'],
                        'message'      => 'Instant slip verification — result is already downloaded and saved locally.',
                    ]);
                    return;
                } else {
                    Response::success([
                        'gv_reference' => $ref,
                        'gv_status'    => $tx['gv_status'],
                        'message'      => 'This is a synchronous slip generator. No remote ticket exists to poll. Use Reconcile or Refund if needed.',
                    ]);
                    return;
                }
            }

            $activeProvider = !empty($tx['provider']) ? strtolower(trim($tx['provider'])) : (!empty($tx['provider_name']) ? strtolower(trim($tx['provider_name'])) : 'techhub');
            $providerLabel  = ($activeProvider === 's8v') ? 'S8V.ng' : 'TechHub';

            $ticketId = !empty($tx['provider_ticket_id']) ? trim($tx['provider_ticket_id']) : null;

            // If no ticket_id, attempt to extract tracking ID from input_summary
            if (!$ticketId && !empty($tx['input_summary'])) {
                if (preg_match('/tracking[:=]\s*([a-zA-Z0-9_-]+)/i', $tx['input_summary'], $m)) {
                    $ticketId = $m[1];
                }
            }

            if (!$ticketId) {
                Response::error("Cannot sync: No {$providerLabel} ticket or tracking reference associated with this transaction. You can attach a Ticket ID in Details.", [], 422);
                return;
            }

            $serviceSlug = $tx['service_slug'] ?? 'ipe-clearance-single';
            $variantKey  = $tx['variant_key'] ?? null;

            if ($activeProvider === 's8v') {
                $statusResult = $this->s8vService->checkAsyncStatus($serviceSlug, $variantKey, $ticketId, $tx['input_summary'] ?? null);
            } else {
                $statusResult = $this->techHubService->checkAsyncStatus($serviceSlug, $variantKey, $ticketId);
            }

            if (!$statusResult['success']) {
                $rawErrMsg = $statusResult['error_message'] ?? 'Unknown provider error';
                
                // Gracefully handle "Ticket not found" on provider system
                if (stripos($rawErrMsg, 'ticket') !== false && (stripos($rawErrMsg, 'not found') !== false || stripos($rawErrMsg, 'invalid') !== false)) {
                    $this->db->prepare("
                        UPDATE api_transactions
                        SET gv_status = 'reconciliation_required',
                            provider_status = 'not_found',
                            reconciliation_notes = ?,
                            synced_at = NOW(),
                            synced_by = ?
                        WHERE id = ?
                    ")->execute([
                        "{$providerLabel} query: Ticket " . $ticketId . ' not found on provider system. Flagged for admin reconciliation.',
                        'admin_' . $this->adminId,
                        $tx['id']
                    ]);

                    Response::success([
                        'gv_reference'     => $ref,
                        'provider_status'  => 'not_found',
                        'gv_status'        => 'reconciliation_required',
                        'synced_at'        => date('Y-m-d H:i:s'),
                        'ticket_not_found' => true,
                        'message'          => "{$providerLabel} reported: Ticket '{$ticketId}' not found on provider system. Flagged for Admin reconciliation.",
                    ]);
                    return;
                }

                Response::error("{$providerLabel} sync query failed: " . $rawErrMsg, [
                    'provider_response' => $statusResult
                ], 502);
                return;
            }

            $pStatus = strtolower($statusResult['provider_status'] ?? 'pending');
            $newGvStatus = $tx['gv_status'];
            $resultData = $tx['result_data'] ?? null;
            $syncMessage = "Synced with {$providerLabel} successfully. Status is now '{$newGvStatus}'.";

            $isSuccess = in_array($pStatus, ['success', 'completed', 'successful'], true) ||
                         (!empty($statusResult['is_complete']) && empty($statusResult['is_failed'])) ||
                         !empty($statusResult['result_data']['pdf_base64']) ||
                         !empty($statusResult['result_data']['slip']);

            if ($isSuccess) {
                $newGvStatus = 'completed';
                $resultData  = json_encode($statusResult['result_data'] ?? []);
                $this->db->prepare("
                    UPDATE api_transactions
                    SET gv_status = 'completed',
                        provider_status = 'completed',
                        provider_financial_status = 'charged',
                        result_data = ?,
                        synced_at = NOW(),
                        synced_by = ?,
                        completed_at = COALESCE(completed_at, NOW())
                    WHERE id = ?
                ")->execute([$resultData, 'admin_' . $this->adminId, $tx['id']]);
                $syncMessage = "Synced with {$providerLabel} successfully. Status is now 'completed'.";

            } elseif ($pStatus === 'failed') {
                if ($activeProvider === 's8v') {
                    $pricePaid  = (float)($tx['price_paid'] ?? 0.00);
                    $penaltyFee = (float)($tx['failure_penalty_fee'] ?? 100.00);
                    if ($penaltyFee <= 0 && $serviceSlug === 'nin-personalization') {
                        $penaltyFee = 100.00;
                    }
                    $refundAmount = max(0.00, $pricePaid - $penaltyFee);

                    if (empty($tx['refund_issued']) && $refundAmount > 0) {
                        $serviceName = $tx['service_name'] ?? 'Verification';
                        $penaltyText = $penaltyFee > 0 ? " (₦" . number_format($refundAmount, 2) . " refunded, ₦" . number_format($penaltyFee, 2) . " processing fee applied)" : "";
                        $desc = "Refund: {$serviceName}{$penaltyText} — Ref: {$tx['gv_reference']}";
                        $this->walletService->creditAtomically(
                            (int)$tx['user_id'],
                            $refundAmount,
                            $desc,
                            null
                        );
                    }

                    $txCols = $this->db->query("SHOW COLUMNS FROM api_transactions")->fetchAll(PDO::FETCH_COLUMN);
                    $hasPenaltyCol = in_array('penalty_deducted', $txCols, true);
                    $hasRefundCol  = in_array('refund_amount', $txCols, true);

                    $errorCode      = $statusResult['error_code'] ?? 'FAILED_RECORD_NOT_FOUND';
                    $userReason     = $statusResult['error_message'] ?? 'Failed';
                    $resultDataJson = !empty($statusResult['result_data']) ? json_encode($statusResult['result_data']) : null;

                    $updateSets = [
                        "gv_status                  = 'failed'",
                        "provider_status           = 'failed'",
                        "provider_financial_status = 'charged'",
                        "refund_issued             = 1",
                        "synced_at                 = NOW()",
                        "synced_by                 = ?",
                        "completed_at              = COALESCE(completed_at, NOW())",
                        "reconciliation_notes      = ?"
                    ];
                    $params = ['admin_' . $this->adminId];

                    if ($hasPenaltyCol) {
                        $updateSets[] = "penalty_deducted = ?";
                        $params[] = $penaltyFee;
                    }
                    if ($hasRefundCol) {
                        $updateSets[] = "refund_amount = ?";
                        $params[] = $refundAmount;
                    }
                    if (in_array('error_code', $txCols, true) && $errorCode) {
                        $updateSets[] = "error_code = ?";
                        $params[] = $errorCode;
                    }
                    if (in_array('result_data', $txCols, true) && $resultDataJson) {
                        $updateSets[] = "result_data = ?";
                        $params[] = $resultDataJson;
                    }
                    if (in_array('error_message', $txCols, true) && $userReason) {
                        $updateSets[] = "error_message = ?";
                        $params[] = $userReason;
                    }

                    $reconcileNote = "S8V sync reported failure (" . $userReason . "). Retained ₦" . number_format($penaltyFee, 2) . " processing fee, refunded ₦" . number_format($refundAmount, 2) . " to wallet.";
                    $params[] = $reconcileNote;

                    $params[] = $tx['id'];

                    $sql = "UPDATE api_transactions SET " . implode(", ", $updateSets) . " WHERE id = ?";
                    $this->db->prepare($sql)->execute($params);

                    $newGvStatus = 'failed';
                    $syncMessage = "Synced with S8V.ng successfully. Status is 'failed' (" . ($statusResult['error_message'] ?? 'Identity not found') . "). ₦" . number_format($penaltyFee, 2) . " processing fee retained, ₦" . number_format($refundAmount, 2) . " refunded to user wallet.";

                } else {
                    $isReversed = !empty($statusResult['is_reversed']) || !empty($statusResult['result_data']['reversed']);
                    $newGvStatus = $isReversed ? 'refunded' : 'reconciliation_required';
                    $finStatus   = $isReversed ? 'reversed' : 'charged';

                    $this->db->prepare("
                        UPDATE api_transactions
                        SET gv_status = ?,
                            provider_status = 'failed',
                            provider_financial_status = ?,
                            synced_at = NOW(),
                            synced_by = ?,
                            reconciliation_notes = ?
                        WHERE id = ?
                    ")->execute([
                        $newGvStatus,
                        $finStatus,
                        'admin_' . $this->adminId,
                        'TechHub sync reported failure (' . ($statusResult['error_message'] ?? 'Provider failed') . '). Charge status: ' . $finStatus,
                        $tx['id']
                    ]);
                    $syncMessage = "Synced with TechHub successfully. Status is now '{$newGvStatus}'.";
                }

            } else {
                if ($tx['gv_status'] === 'completed') {
                    $newGvStatus = 'completed';
                    $syncMessage = "Synced with {$providerLabel} successfully. Transaction is completed.";
                } else {
                    // Still pending / processing
                    $this->db->prepare("
                        UPDATE api_transactions
                        SET provider_status = 'processing',
                            synced_at = NOW(),
                            synced_by = ?
                        WHERE id = ?
                    ")->execute(['admin_' . $this->adminId, $tx['id']]);
                    $syncMessage = "Synced with {$providerLabel} successfully. Ticket is still being processed on registry.";
                }
            }

            $this->auditService->log(
                'ADMIN_API_TXN_SYNCED',
                $tx['id'],
                'admin',
                $this->adminId,
                ['old_status' => $tx['gv_status']],
                ['new_status' => $newGvStatus, 'provider_status' => $pStatus],
                "Live {$providerLabel} sync for {$ref}: provider_status={$pStatus}"
            );

            Response::success([
                'gv_reference'     => $ref,
                'provider_status'  => $pStatus,
                'gv_status'        => $newGvStatus,
                'synced_at'        => date('Y-m-d H:i:s'),
                'provider_data'    => $statusResult['result_data'] ?? [],
                'message'          => $syncMessage,
            ]);

        } catch (Exception $e) {
            Response::error('Failed to sync transaction with provider: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * PATCH /admin/api-transactions/{ref}/ticket
     *
     * Allows admin to update/attach the provider_ticket_id manually and optionally sync immediately.
     */
    public function updateTicket(string $ref): void
    {
        $input    = json_decode(file_get_contents('php://input'), true) ?? [];
        $ticketId = trim($input['ticket_id'] ?? '');

        if ($ticketId === '') {
            Response::error('Ticket ID cannot be empty.', [], 400);
            return;
        }

        try {
            $tx = $this->findByRef($ref);
            if (!$tx) {
                Response::error('API transaction not found.', [], 404);
                return;
            }

            $this->db->prepare("
                UPDATE api_transactions
                SET provider_ticket_id = ?
                WHERE id = ?
            ")->execute([$ticketId, $tx['id']]);

            $this->auditService->log(
                'ADMIN_API_TXN_TICKET_ATTACHED',
                $tx['id'],
                'admin',
                $this->adminId,
                ['old_ticket' => $tx['provider_ticket_id']],
                ['new_ticket' => $ticketId],
                "Admin attached provider ticket: {$ticketId}"
            );

            Response::success([
                'gv_reference' => $ref,
                'ticket_id'    => $ticketId,
                'message'      => "Provider Ticket ID updated to '{$ticketId}'. You can now sync with TechHub.",
            ]);

        } catch (Exception $e) {
            Response::error('Failed to update ticket: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * POST /admin/api-transactions/{ref}/reconcile
     *
     * Allows authorized Admin to resolve an ambiguous or reconciliation-flagged transaction.
     * Actions:
     *   - 'manual_complete': mark completed with admin note
     *   - 'authorized_refund': issue atomic refund with mandatory reason
     *   - 'dismiss_anomaly': clear reconciliation flag
     */
    public function reconcileTransaction(string $ref): void
    {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = trim($input['action'] ?? '');
        $note   = trim($input['note']   ?? '');

        if (!in_array($action, ['manual_complete', 'authorized_refund', 'dismiss_anomaly'], true)) {
            Response::error('Invalid reconciliation action. Allowed: manual_complete, authorized_refund, dismiss_anomaly', [], 400);
            return;
        }
        if ($note === '') {
            Response::error('A detailed reconciliation note is mandatory.', [], 400);
            return;
        }

        try {
            $tx = $this->findByRef($ref);
            if (!$tx) {
                Response::error('API transaction not found.', [], 404);
                return;
            }

            $pricePaid = (float)($tx['price_paid'] ?? 0);
            $userId    = (int)$tx['user_id'];

            $this->db->beginTransaction();

            if ($action === 'manual_complete') {
                $this->db->prepare("
                    UPDATE api_transactions
                    SET gv_status = 'completed',
                        provider_financial_status = 'charged',
                        reconciliation_notes = ?,
                        completed_at = COALESCE(completed_at, NOW())
                    WHERE id = ?
                ")->execute(['Reconciliation: Manually completed by admin — ' . $note, $tx['id']]);

            } elseif ($action === 'authorized_refund') {
                if ($tx['refund_issued']) {
                    $this->db->rollBack();
                    Response::error('This transaction has already been refunded.', [], 409);
                    return;
                }
                if ($pricePaid <= 0) {
                    $this->db->rollBack();
                    Response::error('No price paid to refund.', [], 422);
                    return;
                }

                $this->walletService->creditAtomically(
                    $userId,
                    $pricePaid,
                    'Admin reconciliation refund: ' . $ref . ' — ' . $note,
                    null
                );

                $this->db->prepare("
                    UPDATE api_transactions
                    SET gv_status = 'refunded',
                        refund_issued = 1,
                        reconciliation_notes = ?,
                        completed_at = COALESCE(completed_at, NOW())
                    WHERE id = ?
                ")->execute(['Reconciliation: Authorized refund by admin — ' . $note, $tx['id']]);

            } elseif ($action === 'dismiss_anomaly') {
                $this->db->prepare("
                    UPDATE api_transactions
                    SET gv_status = 'failed',
                        reconciliation_notes = ?
                    WHERE id = ?
                ")->execute(['Reconciliation: Anomaly dismissed by admin — ' . $note, $tx['id']]);
            }

            $this->auditService->log(
                'ADMIN_API_TXN_RECONCILED',
                $tx['id'],
                'admin',
                $this->adminId,
                ['old_status' => $tx['gv_status']],
                ['action' => $action, 'note' => $note],
                "Reconciliation action '{$action}' executed for {$ref}: {$note}"
            );

            $this->db->commit();

            Response::success([
                'gv_reference' => $ref,
                'action'       => $action,
                'message'      => 'Transaction reconciled successfully.',
            ]);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::error('Failed to reconcile transaction: ' . $e->getMessage(), [], 500);
        }
    }

    private function findByRef(string $ref): ?array
    {
        \Helpers\SchemaHelper::ensureProviderColumns($this->db);
        $cols = $this->db->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
        $penaltyCol  = in_array('failure_penalty_fee', $cols, true) ? 's.failure_penalty_fee' : "0.00 AS failure_penalty_fee";
        $providerCol = in_array('provider_name', $cols, true) ? 's.provider_name' : "'techhub' AS provider_name";

        $stmt = $this->db->prepare("
            SELECT
                at.*,
                s.name   AS service_name,
                s.slug   AS service_slug,
                {$penaltyCol},
                {$providerCol},
                sp.price AS price_paid,
                u.business_name AS user_name,
                u.email         AS user_email
            FROM api_transactions at
            JOIN services        s  ON s.id  = at.service_id
            JOIN service_pricing sp ON sp.id = at.pricing_id
            JOIN users           u  ON u.id  = at.user_id
            WHERE at.gv_reference = ?
            LIMIT 1
        ");
        $stmt->execute([$ref]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Format a row for list display (no result_data).
     */
    private function formatListRow(array $row): array
    {
        return [
            'gv_reference'        => $row['gv_reference'],
            'gv_status'           => $row['gv_status'],
            'provider'            => $row['provider'],
            'provider_status'     => $row['provider_status'],
            'result_type'         => $row['result_type'],
            'service'             => [
                'name' => $row['service_name'],
                'slug' => $row['service_slug'],
            ],
            'user'                => [
                'id'            => $row['user_id'],
                'business_name' => $row['user_name'],
                'email'         => $row['user_email'],
            ],
            'variant_key'         => $row['variant_key'],
            'input_method'        => $row['input_method'],
            'input_summary'       => $row['input_summary'],
            'provider_ticket_id'  => $row['provider_ticket_id'],
            'provider_txn_id'     => $row['provider_txn_id'],
            'price_paid'          => (float)($row['price_paid'] ?? 0),
            'error_code'          => $row['error_code']   ?? null,
            'error_message'       => $row['error_message'] ?? null,
            'refund_issued'       => (bool)($row['refund_issued'] ?? false),
            'submitted_at'        => $row['submitted_at'],
            'provider_responded_at' => $row['provider_responded_at'] ?? null,
            'completed_at'        => $row['completed_at'] ?? null,
            'last_checked_at'     => $row['last_checked_at'] ?? null,
        ];
    }

    /**
     * Format a row for detail view (same as list for now — adds no extra sensitive data).
     */
    private function formatDetailRow(array $row): array
    {
        $base = $this->formatListRow($row);
        $base['idempotency_key']      = $row['idempotency_key'] ?? null;
        $base['provider_endpoint']    = $row['provider_endpoint'] ?? null;
        $base['has_result']           = !empty($row['result_data']);
        return $base;
    }
}
