<?php
namespace Controllers\Admin;

use Helpers\Response;
use Middleware\AdminMiddleware;
use Services\RecordDocsService;
use PDO;

/**
 * ProviderBalanceController
 *
 * TechHub and S8V.ng have no balance API. Their available balance is tracked
 * via a top-up ledger (provider_topups table):
 *   available_balance = SUM(topups) - SUM(completed spend)
 *
 * RecordDocs exposes a live balance API, so its balance is fetched directly.
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

    // ──────────────────────────────────────────────────────────────────────────
    // GET /admin/provider-balances
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Returns provider balances.
     * TechHub and S8V: available_balance = total_topups - completed_spend (top-up ledger).
     * RecordDocs: live from provider API.
     */
    public function getBalances(): void {
        try {
            AdminMiddleware::requireRole('admin');

            $nowStr = date('Y-m-d H:i:s');

            // ── Check tables exist ────────────────────────────────────────────
            $apiTxnExists = (bool)$this->db
                ->query("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema = DATABASE()
                          AND table_name = 'api_transactions'")
                ->fetchColumn();

            $topupTableExists = (bool)$this->db
                ->query("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema = DATABASE()
                          AND table_name = 'provider_topups'")
                ->fetchColumn();

            // ── Helper: fetch total topped-up for a provider ──────────────────
            $getTopups = function (string $provider) use ($topupTableExists): float {
                if (!$topupTableExists) return 0.0;
                $stmt = $this->db->prepare(
                    "SELECT COALESCE(SUM(amount), 0) FROM provider_topups WHERE provider = :p"
                );
                $stmt->execute([':p' => $provider]);
                return (float)$stmt->fetchColumn();
            };

            // ── TechHub ───────────────────────────────────────────────────────
            $thSpend     = 0.0;
            $thPending   = 0.0;
            $thCompleted = 0;
            $thPendingN  = 0;
            $thFailed    = 0;
            $thTotal     = 0;
            $thStatus    = 'No Data — Run Migration';
            $thNote      = 'api_transactions table missing on this environment.';

            if ($apiTxnExists) {
                $stmt = $this->db->query("
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
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                $thSpend    = (float)($row['spend_total']   ?? 0);
                $thPending  = (float)($row['spend_pending'] ?? 0);
                $thCompleted = (int)($row['jobs_completed'] ?? 0);
                $thPendingN  = (int)($row['jobs_pending']   ?? 0);
                $thFailed    = (int)($row['jobs_failed']    ?? 0);
                $thTotal     = (int)($row['jobs_total']     ?? 0);

                if ($thSpend === 0.0 && $thFailed > 0) {
                    $thStatus = 'Check Config';
                } elseif ($thPendingN > 50) {
                    $thStatus = 'High Load';
                } else {
                    $thStatus = 'Active';
                }
                $thNote = 'No live balance API. Balance = total topped-up minus completed spend.';
            }

            $thTopups    = $getTopups('techhub');
            $thAvailable = $thTopups > 0 ? max(0.0, $thTopups - $thSpend) : null;

            if ($thTopups > 0 && $thAvailable !== null && $thAvailable < 5000.0) {
                $thStatus = 'Low Balance';
            }

            // ── S8V.ng ────────────────────────────────────────────────────────
            $s8vSpend    = 0.0;
            $s8vPending  = 0.0;
            $s8vCompleted = 0;
            $s8vPendingN  = 0;
            $s8vFailed    = 0;
            $s8vTotal     = 0;
            $s8vStatus    = 'Active';

            if ($apiTxnExists) {
                $stmt = $this->db->query("
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
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $s8vSpend     = (float)($row['spend_total']   ?? 0);
                $s8vPending   = (float)($row['spend_pending'] ?? 0);
                $s8vCompleted = (int)($row['jobs_completed']  ?? 0);
                $s8vPendingN  = (int)($row['jobs_pending']    ?? 0);
                $s8vFailed    = (int)($row['jobs_failed']     ?? 0);
                $s8vTotal     = (int)($row['jobs_total']      ?? 0);

                if ($s8vPendingN > 20) {
                    $s8vStatus = 'High Load';
                } elseif ($s8vFailed > 0 && $s8vSpend === 0.0) {
                    $s8vStatus = 'Check Config';
                }
            }

            $s8vTopups    = $getTopups('s8v');
            $s8vAvailable = $s8vTopups > 0 ? max(0.0, $s8vTopups - $s8vSpend) : null;

            if ($s8vTopups > 0 && $s8vAvailable !== null && $s8vAvailable < 2000.0) {
                $s8vStatus = 'Low Balance';
            }

            // ── Build providers array ─────────────────────────────────────────
            $providers = [
                [
                    'name'              => 'TechHub Verification API',
                    'provider_key'      => 'techhub',
                    'category'          => 'NIN / BVN Automated Identity Verification',
                    'topup_total'       => $thTopups,
                    'available_balance' => $thAvailable,
                    'spend_total'       => $thSpend,
                    'spend_pending'     => $thPending,
                    'jobs_completed'    => $thCompleted,
                    'jobs_pending'      => $thPendingN,
                    'jobs_failed'       => $thFailed,
                    'jobs_total'        => $thTotal,
                    'threshold'         => 5000.0,
                    'status'            => $thStatus,
                    'last_sync'         => $nowStr,
                    'error'             => $apiTxnExists ? null : $thNote,
                    'note'              => $thNote,
                ],
                [
                    'name'              => 'S8V.ng Identity API',
                    'provider_key'      => 's8v',
                    'category'          => 'NIN Personalisation & IPE Clearance',
                    'topup_total'       => $s8vTopups,
                    'available_balance' => $s8vAvailable,
                    'spend_total'       => $s8vSpend,
                    'spend_pending'     => $s8vPending,
                    'jobs_completed'    => $s8vCompleted,
                    'jobs_pending'      => $s8vPendingN,
                    'jobs_failed'       => $s8vFailed,
                    'jobs_total'        => $s8vTotal,
                    'threshold'         => 2000.0,
                    'status'            => $s8vStatus,
                    'last_sync'         => $nowStr,
                    'error'             => null,
                    'note'              => 'No live balance API. Balance = total topped-up minus completed spend. S8V returns HTTP 402 when wallet is empty.',
                ],
            ];

            // ── RecordDocs live balances ──────────────────────────────────────
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
                        'provider_key'      => 'recorddocs',
                        'category'          => 'NIN / BVN Verifications & Async Jobs (IPE, Modification, Validation)',
                        'topup_total'       => null,
                        'available_balance' => $jobCash,
                        'nin_balance'       => $ninUnits,
                        'bvn_balance'       => $bvnUnits,
                        'job_balance'       => $jobCash,
                        'currency'          => $currency,
                        'spend_total'       => null,
                        'spend_pending'     => null,
                        'jobs_completed'    => null,
                        'jobs_pending'      => null,
                        'jobs_failed'       => null,
                        'threshold'         => 1000.0,
                        'status'            => $rdStatus,
                        'last_sync'         => $nowStr,
                        'error'             => null,
                        'note'              => "Live API balance. NIN units: {$ninUnits} | BVN units: {$bvnUnits} | Job cash: {$currency} {$jobCash}",
                    ];
                } else {
                    $rdError = $rdBalances['error'] ?? 'Failed to fetch RecordDocs balance';
                    $providers[] = [
                        'name'              => 'RecordDocs Identity API',
                        'provider_key'      => 'recorddocs',
                        'category'          => 'NIN / BVN Verifications & Async Jobs',
                        'topup_total'       => null,
                        'available_balance' => 0,
                        'threshold'         => 1000.0,
                        'status'            => 'Check Config',
                        'last_sync'         => $nowStr,
                        'error'             => $rdError,
                        'note'              => 'Ensure RECORDDOCS_API_KEY is configured in .env',
                    ];
                }
            } catch (\Throwable $e) {
                $providers[] = [
                    'name'              => 'RecordDocs Identity API',
                    'provider_key'      => 'recorddocs',
                    'category'          => 'NIN / BVN Verifications & Async Jobs',
                    'topup_total'       => null,
                    'available_balance' => 0,
                    'threshold'         => 1000.0,
                    'status'            => 'Error',
                    'last_sync'         => $nowStr,
                    'error'             => $e->getMessage(),
                    'note'              => 'API connection error. Check RECORDDOCS_API_KEY and network.',
                ];
            }

            // Total available = sum of non-null available_balances
            $totalBalance = 0.0;
            foreach ($providers as $p) {
                if ($p['available_balance'] !== null) {
                    $totalBalance += (float)$p['available_balance'];
                }
            }

            Response::success([
                'total_balance' => $totalBalance,
                'last_sync'     => $nowStr,
                'note'          => 'TechHub and S8V balances derived from top-up ledger minus completed spend. RecordDocs is live from provider API.',
                'providers'     => $providers,
            ]);

        } catch (\Throwable $e) {
            Response::error('Failed to query provider balances: ' . $e->getMessage(), [], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /admin/provider-topups
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Record a manual wallet top-up for a provider.
     * Body: { provider, amount, reference?, note? }
     */
    public function recordTopUp(): void {
        try {
            AdminMiddleware::requireRole('admin');

            $body      = json_decode(file_get_contents('php://input'), true) ?? [];
            $provider  = trim($body['provider']  ?? '');
            $amount    = (float)($body['amount']    ?? 0);
            $reference = trim($body['reference'] ?? '');
            $note      = trim($body['note']      ?? '');

            $validProviders = ['techhub', 's8v', 'recorddocs'];
            if (!in_array($provider, $validProviders, true)) {
                Response::error('Invalid provider. Must be one of: ' . implode(', ', $validProviders), [], 422);
                return;
            }
            if ($amount <= 0) {
                Response::error('Amount must be greater than zero.', [], 422);
                return;
            }

            // Get current admin id from JWT
            $adminId = null;
            try {
                $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
                $payload = \Helpers\JWT::verify($token);
                $adminId = $payload->sub ?? null;
            } catch (\Throwable $_) {}

            $stmt = $this->db->prepare("
                INSERT INTO provider_topups
                    (provider, amount, currency, reference, note, recorded_by, topped_up_at)
                VALUES
                    (:provider, :amount, 'NGN', :reference, :note, :recorded_by, NOW())
            ");
            $stmt->execute([
                ':provider'    => $provider,
                ':amount'      => $amount,
                ':reference'   => $reference ?: null,
                ':note'        => $note       ?: null,
                ':recorded_by' => $adminId,
            ]);

            $topupId = (int)$this->db->lastInsertId();

            $stmt2 = $this->db->prepare("SELECT COALESCE(SUM(amount),0) FROM provider_topups WHERE provider=:p");
            $stmt2->execute([':p' => $provider]);
            $topupTotal = (float)$stmt2->fetchColumn();

            Response::success([
                'id'           => $topupId,
                'provider'     => $provider,
                'amount'       => $amount,
                'topup_total'  => $topupTotal,
                'message'      => 'Top-up recorded successfully.',
            ], 201);

        } catch (\Throwable $e) {
            Response::error('Failed to record top-up: ' . $e->getMessage(), [], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /admin/provider-topups
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Paginated top-up history.
     * Query params: ?provider=techhub&page=1&per_page=20
     */
    public function getTopUpHistory(): void {
        try {
            AdminMiddleware::requireRole('admin');

            $provider = trim($_GET['provider'] ?? '');
            $page     = max(1, (int)($_GET['page']     ?? 1));
            $perPage  = min(100, max(5, (int)($_GET['per_page'] ?? 20)));
            $offset   = ($page - 1) * $perPage;

            $where  = $provider ? 'WHERE pt.provider = :provider' : '';
            $params = $provider ? [':provider' => $provider] : [];

            // Total count
            $countStmt = $this->db->prepare(
                "SELECT COUNT(*) FROM provider_topups pt $where"
            );
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Rows
            $rowStmt = $this->db->prepare("
                SELECT
                    pt.id,
                    pt.provider,
                    pt.amount,
                    pt.currency,
                    pt.reference,
                    pt.note,
                    pt.topped_up_at,
                    a.name AS recorded_by_name
                FROM provider_topups pt
                LEFT JOIN admins a ON a.id = pt.recorded_by
                $where
                ORDER BY pt.topped_up_at DESC
                LIMIT :limit OFFSET :offset
            ");
            foreach ($params as $k => $v) { $rowStmt->bindValue($k, $v); }
            $rowStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
            $rowStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
            $rowStmt->execute();
            $rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success([
                'items'       => $rows,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int)ceil($total / $perPage),
            ]);

        } catch (\Throwable $e) {
            Response::error('Failed to fetch top-up history: ' . $e->getMessage(), [], 500);
        }
    }
}
