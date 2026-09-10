<?php
namespace Controllers\Admin;

use Helpers\Response;
use Middleware\AdminMiddleware;
use Services\RecordDocsService;
use PDO;

/**
 * ProviderBalanceController
 *
 * Shows TechHub spend (derived from GemVerify's own records) and RecordDocs
 * live balances (NIN quota, BVN quota, job cash float via RecordDocs API).
 *
 * KatPay is the payment GATEWAY (not a service provider) — not shown here.
 */
class ProviderBalanceController {

    private PDO $db;
    private RecordDocsService $recordDocsService;

    public function __construct() {
        $this->db                = db();
        $this->recordDocsService = new RecordDocsService();
    }

    /**
     * GET /admin/provider-balances
     * Returns TechHub spend data + RecordDocs live balances.
     */
    public function getBalances(): void {
        try {
            AdminMiddleware::requireRole('admin');

            $nowStr = date('Y-m-d H:i:s');

            // ── Check if api_transactions exists ─────────────────────────────
            $tableExists = (bool)$this->db
                ->query("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema = DATABASE()
                          AND table_name = 'api_transactions'")
                ->fetchColumn();

            // ── TechHub spend from completed/failed/refunded jobs ─────────────
            $spendTotal   = 0.0;
            $spendPending = 0.0;
            $jobsCompleted = 0;
            $jobsPending   = 0;
            $jobsFailed    = 0;
            $jobsTotal     = 0;
            $techHubStatus = 'No Data — Run Migration';
            $techHubNote   = 'api_transactions table missing on this environment.';

            if ($tableExists) {
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
                    WHERE at.provider != 'recorddocs' OR at.provider IS NULL
                ");
                $row = $stmtSpend->fetch(PDO::FETCH_ASSOC);

                $spendTotal    = (float)($row['spend_total']   ?? 0);
                $spendPending  = (float)($row['spend_pending']  ?? 0);
                $jobsCompleted = (int)($row['jobs_completed']   ?? 0);
                $jobsPending   = (int)($row['jobs_pending']     ?? 0);
                $jobsFailed    = (int)($row['jobs_failed']      ?? 0);
                $jobsTotal     = (int)($row['jobs_total']       ?? 0);

                if ($jobsFailed > 0 && $spendTotal === 0.0) {
                    $techHubStatus = 'Check Config';
                } elseif ($jobsPending > 50) {
                    $techHubStatus = 'High Load';
                } else {
                    $techHubStatus = 'Active';
                }
                $techHubNote = 'Spend calculated from GemVerify records. TechHub does not expose a live balance API.';
            }

            $providers = [
                [
                    'name'              => 'TechHub Verification API',
                    'category'          => 'Automated Identity Verification (NIN/BVN)',
                    'available_balance' => $spendTotal,
                    'spend_total'       => $spendTotal,
                    'spend_pending'     => $spendPending,
                    'jobs_completed'    => $jobsCompleted,
                    'jobs_pending'      => $jobsPending,
                    'jobs_failed'       => $jobsFailed,
                    'jobs_total'        => $jobsTotal,
                    'threshold'         => 5000.0,
                    'status'            => $techHubStatus,
                    'last_sync'         => $nowStr,
                    'error'             => $tableExists ? null : $techHubNote,
                    'note'              => $techHubNote,
                ]
            ];

            // ── RecordDocs live balances ──────────────────────────────────────
            $rdError = null;
            try {
                $rdBalances = $this->recordDocsService->getBalances();
                if ($rdBalances['success']) {
                    $ninUnits = (float)($rdBalances['nin_balance'] ?? 0);
                    $bvnUnits = (float)($rdBalances['bvn_balance'] ?? 0);
                    $jobCash  = (float)($rdBalances['job_balance'] ?? 0);
                    $currency = (string)($rdBalances['currency']   ?? 'NGN');

                    $rdStatus = ($ninUnits < 10 || $bvnUnits < 10) ? 'Low Balance' : 'Active';

                    $providers[] = [
                        'name'              => 'RecordDocs Identity API',
                        'category'          => 'NIN / BVN Verifications & Async Jobs (IPE, Modification, Validation)',
                        'available_balance' => $jobCash,
                        'nin_balance'       => $ninUnits,
                        'bvn_balance'       => $bvnUnits,
                        'job_balance'       => $jobCash,
                        'currency'          => $currency,
                        'spend_total'       => $jobCash,
                        'spend_pending'     => 0.0,
                        'jobs_completed'    => null,
                        'jobs_pending'      => null,
                        'jobs_failed'       => null,
                        'threshold'         => 1000.0,
                        'status'            => $rdStatus,
                        'last_sync'         => $nowStr,
                        'error'             => null,
                        'note'              => "NIN units: {$ninUnits} | BVN units: {$bvnUnits} | Job cash: {$currency} {$jobCash}",
                    ];
                } else {
                    $rdError = $rdBalances['error'] ?? 'Failed to fetch RecordDocs balance';
                    $providers[] = [
                        'name'              => 'RecordDocs Identity API',
                        'category'          => 'NIN / BVN Verifications & Async Jobs',
                        'available_balance' => 0,
                        'threshold'         => 1000.0,
                        'status'            => 'Check Config',
                        'last_sync'         => $nowStr,
                        'error'             => $rdError,
                        'note'              => 'Ensure RECORDDOCS_API_KEY is configured in .env',
                    ];
                }
            } catch (\Throwable $e) {
                $rdError = $e->getMessage();
                $providers[] = [
                    'name'              => 'RecordDocs Identity API',
                    'category'          => 'NIN / BVN Verifications & Async Jobs',
                    'available_balance' => 0,
                    'threshold'         => 1000.0,
                    'status'            => 'Error',
                    'last_sync'         => $nowStr,
                    'error'             => $rdError,
                    'note'              => 'API connection error. Check RECORDDOCS_API_KEY and network.',
                ];
            }

            $totalBalance = array_sum(array_column($providers, 'available_balance'));

            Response::success([
                'total_balance' => $totalBalance,
                'last_sync'     => $nowStr,
                'note'          => 'TechHub figures derived from GemVerify records. RecordDocs figures are live from provider API.',
                'providers'     => $providers
            ]);

        } catch (\Throwable $e) {
            Response::error('Failed to query provider balances: ' . $e->getMessage(), [], 500);
        }
    }
}
