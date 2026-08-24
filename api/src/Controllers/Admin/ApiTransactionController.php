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
use PDO;
use Exception;

class ApiTransactionController
{
    private PDO $db;
    private AuditService $auditService;
    private WalletService $walletService;
    private int $adminId;

    private const PAGE_SIZE = 25;

    // Valid gv_status values that admin can manually override to
    private const OVERRIDE_STATUSES = ['pending', 'processing', 'completed', 'failed', 'refunded'];

    public function __construct()
    {
        $this->db           = db();
        $this->auditService = new AuditService($this->db);
        $this->walletService = new WalletService($this->db);
        $this->adminId      = (int)($_SERVER['ADMIN_ID'] ?? 1);
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

    // ── Private Helpers ────────────────────────────────────────────────────────

    /**
     * Find a single api_transaction by GV reference (no user restriction — admin view).
     */
    private function findByRef(string $ref): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                at.*,
                s.name   AS service_name,
                s.slug   AS service_slug,
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
