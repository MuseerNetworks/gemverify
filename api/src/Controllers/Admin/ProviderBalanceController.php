<?php
namespace Controllers\Admin;

use Helpers\Response;
use Middleware\AdminMiddleware;
use PDO;

/**
 * ProviderBalanceController
 *
 * Shows TechHub API spend derived from GemVerify's own database.
 * TechHub has no live balance endpoint — we calculate how much has been
 * consumed from api_transactions records, which is accurate and real-time.
 *
 * KatPay is the payment GATEWAY (not a service provider) — not shown here.
 */
class ProviderBalanceController {

    private PDO $db;

    public function __construct() {
        $this->db = db();
    }

    /**
     * GET /admin/provider-balances
     * Returns TechHub spend data derived from api_transactions.
     */
    public function getBalances(): void {
        try {
            AdminMiddleware::requireRole('admin');

            $nowStr = date('Y-m-d H:i:s');

            // ── Check if api_transactions exists (may not be migrated on live yet) ─
            $tableExists = (bool)$this->db
                ->query("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema = DATABASE()
                          AND table_name = 'api_transactions'")
                ->fetchColumn();

            if (!$tableExists) {
                Response::success([
                    'total_balance' => 0,
                    'last_sync'     => $nowStr,
                    'note'          => 'api_transactions table not yet migrated on this environment.',
                    'providers'     => [
                        [
                            'name'              => 'TechHub Verification API',
                            'category'          => 'Automated Identity Verification',
                            'available_balance' => 0,
                            'spend_total'       => 0,
                            'spend_pending'     => 0,
                            'jobs_completed'    => 0,
                            'jobs_pending'      => 0,
                            'jobs_failed'       => 0,
                            'threshold'         => 5000.0,
                            'status'            => 'No Data — Run Migration',
                            'last_sync'         => $nowStr,
                            'error'             => 'api_transactions table missing on this environment.'
                        ]
                    ]
                ]);
                return;
            }

            // ── TechHub spend from completed/failed/refunded jobs ─────────────────
            $stmtSpend = $this->db->query("
                SELECT
                    COALESCE(SUM(CASE WHEN at.gv_status IN ('completed','failed','refunded')
                                     THEN COALESCE(sp.price, 0) ELSE 0 END), 0) AS spend_total,
                    COALESCE(SUM(CASE WHEN at.gv_status IN ('pending','processing')
                                     THEN COALESCE(sp.price, 0) ELSE 0 END), 0) AS spend_pending,
                    COUNT(CASE WHEN at.gv_status = 'completed' THEN 1 END)       AS jobs_completed,
                    COUNT(CASE WHEN at.gv_status IN ('pending','processing') THEN 1 END) AS jobs_pending,
                    COUNT(CASE WHEN at.gv_status IN ('failed','refunded') THEN 1 END)    AS jobs_failed,
                    COUNT(*) AS jobs_total
                FROM api_transactions at
                LEFT JOIN service_pricing sp ON sp.id = at.pricing_id
            ");
            $row = $stmtSpend->fetch(PDO::FETCH_ASSOC);

            $spendTotal   = (float)($row['spend_total']   ?? 0);
            $spendPending = (float)($row['spend_pending']  ?? 0);

            // Determine status
            if ((int)($row['jobs_failed'] ?? 0) > 0 && $spendTotal === 0.0) {
                $status = 'Check Config';
            } elseif ((int)($row['jobs_pending'] ?? 0) > 50) {
                $status = 'High Load';
            } else {
                $status = 'Active';
            }

            $providers = [
                [
                    'name'              => 'TechHub Verification API',
                    'category'          => 'Automated Identity Verification (NIN/BVN)',
                    'available_balance' => $spendTotal,     // "available_balance" repurposed as total spend for UI compat
                    'spend_total'       => $spendTotal,
                    'spend_pending'     => $spendPending,
                    'jobs_completed'    => (int)($row['jobs_completed'] ?? 0),
                    'jobs_pending'      => (int)($row['jobs_pending']   ?? 0),
                    'jobs_failed'       => (int)($row['jobs_failed']    ?? 0),
                    'jobs_total'        => (int)($row['jobs_total']     ?? 0),
                    'threshold'         => 5000.0,
                    'status'            => $status,
                    'last_sync'         => $nowStr,
                    'error'             => null,
                    'note'              => 'Spend calculated from GemVerify records. TechHub does not expose a live balance API.'
                ]
            ];

            Response::success([
                'total_balance' => $spendTotal,
                'last_sync'     => $nowStr,
                'note'          => 'Figures are derived from GemVerify transaction records (TechHub has no balance API).',
                'providers'     => $providers
            ]);

        } catch (\Throwable $e) {
            Response::error('Failed to query provider balances: ' . $e->getMessage(), [], 500);
        }
    }
}
