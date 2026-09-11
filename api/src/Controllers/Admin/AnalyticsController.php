<?php
namespace Controllers\Admin;

use Helpers\Response;
use Middleware\AdminMiddleware;
use Services\RecordDocsService;
use PDO;

/**
 * AnalyticsController
 *
 * Supplies lightweight chart data for the Admin Dashboard:
 *  - Revenue & verification volume by hour (24H) or by day (7D / 30D)
 *  - Provider health: rolling 24h success rate & average latency
 *
 * Endpoint: GET /admin/analytics/chart?range=24h|7d|30d
 */
class AnalyticsController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = db();
    }

    /**
     * GET /admin/analytics/chart
     */
    public function getChartData(): void
    {
        try {
            AdminMiddleware::requireRole('support');

            $range = strtolower(trim($_GET['range'] ?? '24h'));
            if (!in_array($range, ['24h', '7d', '30d'], true)) {
                $range = '24h';
            }

            Response::success([
                'range'   => $range,
                'revenue' => $this->getRevenueData($range),
                'health'  => $this->getProviderHealth(),
                'summary' => $this->getSummaryStats(),
            ]);
        } catch (\Throwable $e) {
            Response::error('Failed to load analytics: ' . $e->getMessage(), [], 500);
        }
    }

    // ── Revenue & Volume Chart ────────────────────────────────────────────────

    private function getRevenueData(string $range): array
    {
        $apiTable = $this->tableExists('api_transactions');
        $reqTable = $this->tableExists('requests');
        $labels = $revenue = $volume = [];

        if ($range === '24h') {
            for ($h = 23; $h >= 0; $h--) {
                $labels[]  = date('H:00', strtotime("-{$h} hours"));
                $startDt   = date('Y-m-d H:00:00', strtotime("-{$h} hours"));
                $endDt     = date('Y-m-d H:59:59', strtotime("-{$h} hours"));
                [$rev, $vol] = $this->fetchBucket($apiTable, $reqTable, $startDt, $endDt, 'between');
                $revenue[] = round($rev, 2);
                $volume[]  = $vol;
            }
        } else {
            $days = ($range === '30d') ? 30 : 7;
            for ($d = $days - 1; $d >= 0; $d--) {
                $date      = date('Y-m-d', strtotime("-{$d} days"));
                $labels[]  = date('D d M', strtotime("-{$d} days"));
                [$rev, $vol] = $this->fetchBucket($apiTable, $reqTable, $date, $date, 'date');
                $revenue[] = round($rev, 2);
                $volume[]  = $vol;
            }
        }

        return ['labels' => $labels, 'revenue' => $revenue, 'volume' => $volume];
    }

    private function fetchBucket(bool $apiTable, bool $reqTable, string $start, string $end, string $mode): array
    {
        $rev = 0.0;
        $vol = 0;

        if ($apiTable) {
            if ($mode === 'date') {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) AS cnt, COALESCE(SUM(sp.price),0) AS rev
                    FROM api_transactions at
                    LEFT JOIN service_pricing sp ON sp.id = at.pricing_id
                    WHERE at.gv_status='completed' AND DATE(at.submitted_at)=:start
                ");
                $stmt->execute([':start' => $start]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) AS cnt, COALESCE(SUM(sp.price),0) AS rev
                    FROM api_transactions at
                    LEFT JOIN service_pricing sp ON sp.id = at.pricing_id
                    WHERE at.gv_status='completed' AND at.submitted_at BETWEEN :start AND :end
                ");
                $stmt->execute([':start' => $start, ':end' => $end]);
            }
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            $rev += (float)($row['rev'] ?? 0);
            $vol += (int)($row['cnt'] ?? 0);
        }

        if ($reqTable) {
            if ($mode === 'date') {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) AS cnt, COALESCE(SUM(sp.price),0) AS rev
                    FROM requests r
                    LEFT JOIN service_pricing sp ON sp.id = r.pricing_id
                    WHERE r.status='completed' AND DATE(r.created_at)=:start
                ");
                $stmt->execute([':start' => $start]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) AS cnt, COALESCE(SUM(sp.price),0) AS rev
                    FROM requests r
                    LEFT JOIN service_pricing sp ON sp.id = r.pricing_id
                    WHERE r.status='completed' AND r.created_at BETWEEN :start AND :end
                ");
                $stmt->execute([':start' => $start, ':end' => $end]);
            }
            $row2  = $stmt->fetch(PDO::FETCH_ASSOC);
            $rev  += (float)($row2['rev'] ?? 0);
            $vol  += (int)($row2['cnt'] ?? 0);
        }

        return [$rev, $vol];
    }

    // ── Provider Health (Rolling 24h) ─────────────────────────────────────────

    private function getProviderHealth(): array
    {
        $apiTable = $this->tableExists('api_transactions');
        $since    = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $health   = [];

        $providerMap = ['techhub' => 'TechHub', 's8v' => 'S8V.ng', 'recorddocs' => 'RecordDocs'];

        foreach ($providerMap as $slug => $label) {
            $successRate = null;
            $avgLatency  = null;
            $total       = 0;
            $completed   = 0;

            if ($apiTable) {
                $stmt = $this->db->prepare("
                    SELECT
                        COUNT(*) AS total,
                        COUNT(CASE WHEN gv_status='completed' THEN 1 END) AS completed,
                        AVG(CASE
                            WHEN gv_status='completed' AND COALESCE(completed_at, synced_at) IS NOT NULL
                            THEN TIMESTAMPDIFF(SECOND, submitted_at, COALESCE(completed_at, synced_at))
                        END) AS avg_secs
                    FROM api_transactions
                    WHERE provider=:provider AND submitted_at>=:since
                ");
                $stmt->execute([':provider' => $slug, ':since' => $since]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                $total     = (int)($row['total']     ?? 0);
                $completed = (int)($row['completed'] ?? 0);
                if ($total > 0) $successRate = round(($completed / $total) * 100, 1);
                if ($row['avg_secs'] !== null) $avgLatency = round((float)$row['avg_secs'], 1);
            }

            $status = 'healthy';
            if ($total > 0) {
                $status = ($successRate !== null && $successRate >= 95) ? 'healthy' : (($successRate !== null && $successRate >= 80) ? 'degraded' : 'critical');
            }

            $entry = compact('slug', 'label', 'status', 'successRate', 'avgLatency', 'total', 'completed');
            $entry['success_rate'] = $successRate;
            $entry['avg_latency']  = $avgLatency;
            $entry['total_24h']    = $total;
            $entry['completed_24h']= $completed;

            // Augment RecordDocs with live balance (protected with fast 2s timeout)
            if ($slug === 'recorddocs') {
                try {
                    $rdClient = new \Providers\RecordDocsClient(null, null, 2);
                    $rdBal    = (new RecordDocsService($rdClient))->getBalances();
                    if (!empty($rdBal['success'])) {
                        $entry['nin_balance'] = (float)($rdBal['nin_balance'] ?? 0);
                        $entry['bvn_balance'] = (float)($rdBal['bvn_balance'] ?? 0);
                        $entry['job_balance'] = (float)($rdBal['job_balance'] ?? 0);
                        $entry['currency']    = (string)($rdBal['currency']   ?? 'NGN');
                    }
                } catch (\Throwable $ignored) {}
            }

            $health[] = $entry;
        }

        return $health;
    }

    // ── Summary (today) ───────────────────────────────────────────────────────

    private function getSummaryStats(): array
    {
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd   = date('Y-m-d 23:59:59');
        $rev = 0.0; $comp = 0; $pending = 0; $failed = 0; $refunded = 0.0; $users = 0;

        if ($this->tableExists('api_transactions')) {
            $stmt = $this->db->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN gv_status='completed' THEN sp.price ELSE 0 END),0) AS revenue,
                    COUNT(CASE WHEN gv_status='completed' THEN 1 END)            AS completed,
                    COUNT(CASE WHEN gv_status IN('pending','processing') THEN 1 END) AS pending,
                    COUNT(CASE WHEN gv_status='failed' THEN 1 END)               AS failed,
                    COALESCE(SUM(CASE WHEN gv_status='refunded' THEN sp.price ELSE 0 END),0) AS refunded
                FROM api_transactions at
                LEFT JOIN service_pricing sp ON sp.id=at.pricing_id
                WHERE at.submitted_at BETWEEN :s AND :e
            ");
            $stmt->execute([':s' => $todayStart, ':e' => $todayEnd]);
            $row     = $stmt->fetch(PDO::FETCH_ASSOC);
            $rev     = (float)($row['revenue']  ?? 0);
            $comp    = (int)($row['completed']  ?? 0);
            $pending = (int)($row['pending']    ?? 0);
            $failed  = (int)($row['failed']     ?? 0);
            $refunded= (float)($row['refunded'] ?? 0);
        }

        if ($this->tableExists('users')) {
            $users = (int)$this->db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        }

        return [
            'revenue_today'   => round($rev, 2),
            'completed_today' => $comp,
            'pending_total'   => $pending,
            'failed_today'    => $failed,
            'refunded_today'  => round($refunded, 2),
            'user_count'      => $users,
        ];
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    }
}
