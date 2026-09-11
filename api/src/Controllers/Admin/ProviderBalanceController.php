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
                // ── TechHub: only completed jobs, exclude failed/refunded to show real spend
                $stmtTH = $this->db->query("
                    SELECT
                        COALESCE(SUM(CASE WHEN at.gv_status = 'completed'
                                         THEN COALESCE(sp.price, 0) ELSE 0 END), 0) AS spend_total,
                        COALESCE(SUM(CASE WHEN at.gv_status IN ('pending','processing')
                                         THEN COALESCE(sp.price, 0) ELSE 0 END), 0) AS spend_pending,
                        COUNT(CASE WHEN at.gv_status = 'completed' THEN 1 END)       AS jobs_completed,
                        COUNT(CASE WHEN at.gv_status IN ('pending','processing') THEN 1 END) AS jobs_pending,
                        COUNT(CASE WHEN at.gv_status IN ('failed','refunded') THEN 1 END)    AS jobs_failed,
                        COUNT(*) AS jobs_total
                    FROM api_transactions at
                    LEFT JOIN service_pricing sp ON sp.id = at.pricing_id
                    WHERE at.provider = 'techhub' OR at.provider IS NULL
                ");
                $rowTH = $stmtTH->fetch(PDO::FETCH_ASSOC);

                $spendTotal    = (float)($rowTH['spend_total']   ?? 0);
                $spendPending  = (float)($rowTH['spend_pending']  ?? 0);
                $jobsCompleted = (int)($rowTH['jobs_completed']   ?? 0);
                $jobsPending   = (int)($rowTH['jobs_pending']     ?? 0);
                $jobsFailed    = (int)($rowTH['jobs_failed']      ?? 0);
                $jobsTotal     = (int)($rowTH['jobs_total']       ?? 0);

                if ($spendTotal === 0.0 && $jobsFailed > 0) {
                    $techHubStatus = 'Check Config';
                } elseif ($jobsPending > 50) {
                    $techHubStatus = 'High Load';
                } else {
                    $techHubStatus = 'Active';
                }
                $techHubNote = 'Spend reflects completed jobs only. Failed/refunded jobs are excluded. TechHub has no live balance API.';

                // ── S8V: separate spend row
                $stmtS8V = $this->db->query("
                    SELECT
                        COALESCE(SUM(CASE WHEN at.gv_status = 'completed'
                                         THEN COALESCE(sp.price, 0) ELSE 0 END), 0) AS spend_total,
                        COALESCE(SUM(CASE WHEN at.gv_status IN ('pending','processing')
                                         THEN COALESCE(sp.price, 0) ELSE 0 END), 0) AS spend_pending,
                        COUNT(CASE WHEN at.gv_status = 'completed' THEN 1 END)       AS jobs_completed,
                        COUNT(CASE WHEN at.gv_status IN ('pending','processing') THEN 1 END) AS jobs_pending,
                        COUNT(CASE WHEN at.gv_status IN ('failed','refunded') THEN 1 END)    AS jobs_failed,
                        COUNT(*) AS jobs_total
                    FROM api_transactions at
                    LEFT JOIN service_pricing sp ON sp.id = at.pricing_id
                    WHERE at.provider = 's8v'
                ");
                $rowS8V = $stmtS8V->fetch(PDO::FETCH_ASSOC);
                $s8vSpend     = (float)($rowS8V['spend_total']   ?? 0);
                $s8vPending   = (float)($rowS8V['spend_pending']  ?? 0);
                $s8vCompleted = (int)($rowS8V['jobs_completed']   ?? 0);
                $s8vPend      = (int)($rowS8V['jobs_pending']     ?? 0);
                $s8vFailed    = (int)($rowS8V['jobs_failed']      ?? 0);
                $s8vTotal     = (int)($rowS8V['jobs_total']       ?? 0);

                $s8vStatus = ($s8vPend > 20) ? 'High Load' : (($s8vFailed > 0 && $s8vSpend === 0.0) ? 'Check Config' : 'Active');
            }

            $providers = [
                [
                    'name'              => 'TechHub Verification API',
                    'category'          => 'NIN / BVN Automated Identity Verification',
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
                ],
                [
                    'name'              => 'S8V.ng Identity API',
                    'category'          => 'NIN Personalisation & IPE Clearance',
                    'available_balance' => $s8vSpend,
                    'spend_total'       => $s8vSpend,
                    'spend_pending'     => $s8vPending,
                    'jobs_completed'    => $s8vCompleted,
                    'jobs_pending'      => $s8vPend,
                    'jobs_failed'       => $s8vFailed,
                    'jobs_total'        => $s8vTotal,
                    'threshold'         => 2000.0,
                    'status'            => $s8vStatus,
                    'last_sync'         => $nowStr,
                    'error'             => null,
                    'note'              => 'Spend reflects completed jobs only. S8V returns HTTP 402 when wallet is empty.',
                ],
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
