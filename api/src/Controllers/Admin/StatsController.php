<?php

namespace Controllers\Admin;

use Core\Database;
use Helpers\Response;
use Exception;
use PDO;

class StatsController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = db();
        $this->ensureCostPriceColumn();
    }

    private function ensureCostPriceColumn(): void
    {
        try {
            $hasCostPrice = (bool)$this->db->query("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'service_pricing'
                  AND column_name = 'cost_price'
            ")->fetchColumn();

            if (!$hasCostPrice) {
                $this->db->exec("ALTER TABLE service_pricing ADD COLUMN cost_price DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER price");
            }
            // Ensure Category 1 (NIN Services)
            $catStmt = $this->db->query("SELECT id FROM service_categories WHERE slug = 'nin' LIMIT 1");
            $ninCatId = (int)($catStmt ? $catStmt->fetchColumn() : 1);
            if ($ninCatId <= 0) $ninCatId = 1;

            // 1. Ensure 'nin-validation-single' service exists
            $singleStmt = $this->db->prepare("SELECT id FROM services WHERE slug = 'nin-validation-single' LIMIT 1");
            $singleStmt->execute();
            $singleSvcId = (int)$singleStmt->fetchColumn();
            if ($singleSvcId <= 0) {
                $this->db->prepare("INSERT INTO services (category_id, name, slug, description, is_manual, est_time, is_active, created_at) VALUES (?, 'NIN Validation — Single', 'nin-validation-single', 'Single NIN Validation service', 1, 'Instant', 1, NOW())")
                         ->execute([$ninCatId]);
                $singleSvcId = (int)$this->db->lastInsertId();
            } else {
                $this->db->prepare("UPDATE services SET name = 'NIN Validation — Single', is_active = 1 WHERE id = ?")->execute([$singleSvcId]);
            }

            // 2. Ensure 'nin-validation-bulk' service exists
            $bulkStmt = $this->db->prepare("SELECT id FROM services WHERE slug = 'nin-validation-bulk' LIMIT 1");
            $bulkStmt->execute();
            $bulkSvcId = (int)$bulkStmt->fetchColumn();
            if ($bulkSvcId <= 0) {
                $this->db->prepare("INSERT INTO services (category_id, name, slug, description, is_manual, est_time, is_active, created_at) VALUES (?, 'NIN Validation — Bulk', 'nin-validation-bulk', 'Bulk NIN Validation service', 1, 'Instant', 1, NOW())")
                         ->execute([$ninCatId]);
                $bulkSvcId = (int)$this->db->lastInsertId();
            } else {
                $this->db->prepare("UPDATE services SET name = 'NIN Validation — Bulk', is_active = 1 WHERE id = ?")->execute([$bulkSvcId]);
            }

            // Standard variants list
            $valVariants = [
                ['No Record Found', 'No Record Found', 300.00],
                ['SIM Validation', 'SIM Validation', 200.00],
                ['vNIN validation', 'vNIN validation', 250.00],
                ['Update Records Validation', 'Update Records Validation', 400.00],
                ['Bank Validation', 'Bank Validation', 300.00],
                ['Modification Validation', 'Modification Validation', 350.00],
                ['Photographic Error', 'Photographic Error', 300.00],
            ];

            // Seed/sync variants for Single
            foreach ($valVariants as $v) {
                $check = $this->db->prepare("SELECT id FROM service_pricing WHERE service_id = ? AND variant_key = ? LIMIT 1");
                $check->execute([$singleSvcId, $v[0]]);
                if (!$check->fetchColumn()) {
                    $this->db->prepare("INSERT INTO service_pricing (service_id, variant_key, variant_label, price, cost_price, is_active) VALUES (?, ?, ?, ?, 0.00, 1)")
                             ->execute([$singleSvcId, $v[0], $v[1], $v[2]]);
                }
            }

            // Seed/sync variants for Bulk
            foreach ($valVariants as $v) {
                $check = $this->db->prepare("SELECT id FROM service_pricing WHERE service_id = ? AND variant_key = ? LIMIT 1");
                $check->execute([$bulkSvcId, $v[0]]);
                if (!$check->fetchColumn()) {
                    $this->db->prepare("INSERT INTO service_pricing (service_id, variant_key, variant_label, price, cost_price, is_active) VALUES (?, ?, ?, ?, 0.00, 1)")
                             ->execute([$bulkSvcId, $v[0], $v[1], $v[2]]);
                }
            }

            // Also normalize legacy 'nin-validation' if it exists
            $this->db->exec("UPDATE services SET is_active = 0 WHERE slug = 'nin-validation'");
            $this->db->exec("UPDATE service_pricing SET variant_key = 'vNIN validation', variant_label = 'vNIN validation' WHERE variant_key LIKE '%v%nin%validation%'");
        } catch (\Throwable $e) {
            error_log('[GemVerify Notice] cost_price column check: ' . $e->getMessage());
        }
    }

    public function getStats(): void
    {
        try {
            $stats = [];

            // ── Check which optional tables exist on this environment ──────────────
            // api_transactions and admin_withdrawals may not be migrated on live yet.
            // We detect their existence once and use it to build safe queries.
            $apiTxnExists = (bool)$this->db
                ->query("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema = DATABASE()
                          AND table_name = 'api_transactions'")
                ->fetchColumn();

            $adminWdExists = (bool)$this->db
                ->query("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema = DATABASE()
                          AND table_name = 'admin_withdrawals'")
                ->fetchColumn();

            // ── Unified counts ─────────────────────────────────────────────────────
            if ($apiTxnExists) {
                $stmtTotals = $this->db->query("
                    SELECT
                        (SELECT COUNT(*) FROM manual_requests) + (SELECT COUNT(*) FROM api_transactions) as total_requests,
                        (SELECT COUNT(*) FROM manual_requests WHERE status = 'completed') +
                        (SELECT COUNT(*) FROM api_transactions WHERE gv_status = 'completed') as total_completed,
                        (SELECT COUNT(*) FROM manual_requests WHERE status IN ('submitted', 'pending')) +
                        (SELECT COUNT(*) FROM api_transactions WHERE gv_status = 'pending') as total_pending,
                        (SELECT COUNT(*) FROM manual_requests WHERE status = 'under_review') as total_under_review,
                        (SELECT COUNT(*) FROM manual_requests WHERE status = 'processing') +
                        (SELECT COUNT(*) FROM api_transactions WHERE gv_status = 'processing') as total_processing,
                        (SELECT COUNT(*) FROM manual_requests WHERE status IN ('rejected', 'cancelled')) +
                        (SELECT COUNT(*) FROM api_transactions WHERE gv_status IN ('failed', 'refunded')) as total_rejected,
                        (SELECT COUNT(*) FROM manual_requests WHERE DATE(submitted_at) = CURRENT_DATE()) +
                        (SELECT COUNT(*) FROM api_transactions WHERE DATE(submitted_at) = CURRENT_DATE()) as requests_today
                ");
            } else {
                // api_transactions not yet migrated — count only manual_requests
                $stmtTotals = $this->db->query("
                    SELECT
                        (SELECT COUNT(*) FROM manual_requests) as total_requests,
                        (SELECT COUNT(*) FROM manual_requests WHERE status = 'completed') as total_completed,
                        (SELECT COUNT(*) FROM manual_requests WHERE status IN ('submitted', 'pending')) as total_pending,
                        (SELECT COUNT(*) FROM manual_requests WHERE status = 'under_review') as total_under_review,
                        (SELECT COUNT(*) FROM manual_requests WHERE status = 'processing') as total_processing,
                        (SELECT COUNT(*) FROM manual_requests WHERE status IN ('rejected', 'cancelled')) as total_rejected,
                        (SELECT COUNT(*) FROM manual_requests WHERE DATE(submitted_at) = CURRENT_DATE()) as requests_today
                ");
            }
            $totals = $stmtTotals->fetch(PDO::FETCH_ASSOC);
            $stats  = array_merge($stats, $totals);

            // ── User & Wallet Totals ───────────────────────────────────────────────
            $stmtUsers = $this->db->query("
                SELECT
                    (SELECT COUNT(*) FROM users WHERE is_active = 1) as total_users,
                    (SELECT COUNT(*) FROM users WHERE is_active = 1 AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) as active_users,
                    (SELECT COALESCE(SUM(balance), 0) FROM wallets) as total_wallet_liability
            ");
            $userStats = $stmtUsers->fetch(PDO::FETCH_ASSOC);
            $stats     = array_merge($stats, $userStats);

            // ── Revenue & Business Cash Calculation ────────────────────────────────
            $stmtRev = $this->db->query("
                SELECT
                    COALESCE(SUM(CASE WHEN DATE(created_at) = CURRENT_DATE() THEN amount ELSE 0 END), 0) as revenue_today,
                    COALESCE(SUM(amount), 0) as total_revenue
                FROM transactions
                WHERE type = 'debit' AND status = 'completed'
            ");
            $revData      = $stmtRev->fetch(PDO::FETCH_ASSOC);
            $revenueToday = (float)($revData['revenue_today'] ?? 0);
            $totalRevenue = (float)($revData['total_revenue'] ?? 0);

            // Calculate actual provider cost from completed service pricing records
            $providerCostTotal = 0.0;
            $providerCostToday = 0.0;
            if ($apiTxnExists) {
                $stmtCost = $this->db->query("
                    SELECT
                        COALESCE(SUM(CASE WHEN at.gv_status = 'completed' THEN COALESCE(sp.cost_price, 0) ELSE 0 END), 0) AS total_cost,
                        COALESCE(SUM(CASE WHEN at.gv_status = 'completed' AND DATE(at.submitted_at) = CURRENT_DATE() THEN COALESCE(sp.cost_price, 0) ELSE 0 END), 0) AS cost_today
                    FROM api_transactions at
                    LEFT JOIN service_pricing sp ON sp.id = at.pricing_id
                ");
                $costRow = $stmtCost->fetch(PDO::FETCH_ASSOC);
                $providerCostTotal = (float)($costRow['total_cost'] ?? 0);
                $providerCostToday = (float)($costRow['cost_today'] ?? 0);
            }

            // Fallback estimation if cost_price is zero
            if ($providerCostTotal === 0.0 && $totalRevenue > 0) {
                $providerCostTotal = round($totalRevenue * 0.70, 2); // 70% provider cost default
                $providerCostToday = round($revenueToday * 0.70, 2);
            }

            // Formula: Business Cash = User Payments - Profit
            $totalProfit = max(0, $totalRevenue - $providerCostTotal);
            $profitToday = max(0, $revenueToday - $providerCostToday);
            $totalBusinessCash = $providerCostTotal;
            $businessCashToday = $providerCostToday;

            // Check completed withdrawals by type
            $withdrawnProfit = 0.0;
            $withdrawnBusinessCash = 0.0;
            if ($adminWdExists) {
                // Check if withdrawal_type column exists
                $hasTypeCol = (bool)$this->db->query("
                    SELECT COUNT(*) FROM information_schema.columns
                    WHERE table_schema = DATABASE()
                      AND table_name = 'admin_withdrawals'
                      AND column_name = 'withdrawal_type'
                ")->fetchColumn();

                if ($hasTypeCol) {
                    $stmtWd = $this->db->query("
                        SELECT
                            COALESCE(SUM(CASE WHEN withdrawal_type = 'profit' THEN amount ELSE 0 END), 0) as withdrawn_profit,
                            COALESCE(SUM(CASE WHEN withdrawal_type = 'business_cash' THEN amount ELSE 0 END), 0) as withdrawn_cash,
                            COALESCE(SUM(amount), 0) as total_withdrawn
                        FROM admin_withdrawals WHERE status = 'completed'
                    ");
                    $wdRow = $stmtWd->fetch(PDO::FETCH_ASSOC);
                    $withdrawnProfit = (float)($wdRow['withdrawn_profit'] ?? 0);
                    $withdrawnBusinessCash = (float)($wdRow['withdrawn_cash'] ?? 0);
                } else {
                    $totalWithdrawn = (float)$this->db->query("SELECT COALESCE(SUM(amount), 0) FROM admin_withdrawals WHERE status = 'completed'")->fetchColumn();
                    $withdrawnProfit = $totalWithdrawn;
                }
            }

            $stats['revenue_today']              = $revenueToday;
            $stats['total_revenue']              = $totalRevenue;
            $stats['user_payments']              = $totalRevenue;
            $stats['profit_today']               = $profitToday;
            $stats['total_profit']               = $totalProfit;
            $stats['business_cash_today']        = $businessCashToday;
            $stats['total_business_cash']        = $totalBusinessCash;
            $stats['withdrawable_business_cash'] = max(0, $totalBusinessCash - $withdrawnBusinessCash);
            $stats['withdrawable_profit']        = max(0, $totalProfit - $withdrawnProfit);
            $stats['total_withdrawable']         = max(0, $totalRevenue - ($withdrawnProfit + $withdrawnBusinessCash));
            $stats['provider_balance']           = 0.00;

            // ── By status (unified) ────────────────────────────────────────────────
            if ($apiTxnExists) {
                $stmtStatus = $this->db->query("
                    SELECT status, COUNT(*) as count FROM (
                        SELECT status FROM manual_requests
                        UNION ALL
                        SELECT gv_status as status FROM api_transactions
                    ) s GROUP BY status
                ");
            } else {
                $stmtStatus = $this->db->query("
                    SELECT status, COUNT(*) as count FROM manual_requests GROUP BY status
                ");
            }
            $stats['requests_by_status'] = $stmtStatus->fetchAll(PDO::FETCH_ASSOC);

            // ── By category (unified) ──────────────────────────────────────────────
            if ($apiTxnExists) {
                $stmtCategory = $this->db->query("
                    SELECT category, COUNT(*) as count FROM (
                        SELECT c.name as category
                        FROM manual_requests r
                        JOIN services s ON r.service_id = s.id
                        JOIN service_categories c ON s.category_id = c.id
                        UNION ALL
                        SELECT c.name as category
                        FROM api_transactions at
                        JOIN services s ON at.service_id = s.id
                        JOIN service_categories c ON s.category_id = c.id
                    ) cat GROUP BY category
                ");
            } else {
                $stmtCategory = $this->db->query("
                    SELECT c.name as category, COUNT(*) as count
                    FROM manual_requests r
                    JOIN services s ON r.service_id = s.id
                    JOIN service_categories c ON s.category_id = c.id
                    GROUP BY category
                ");
            }
            $stats['requests_by_category'] = $stmtCategory->fetchAll(PDO::FETCH_ASSOC);

            // ── Top services (unified) ─────────────────────────────────────────────
            if ($apiTxnExists) {
                $stmtTop = $this->db->query("
                    SELECT name, COUNT(*) as request_count FROM (
                        SELECT s.name FROM manual_requests r JOIN services s ON r.service_id = s.id
                        UNION ALL
                        SELECT s.name FROM api_transactions at JOIN services s ON at.service_id = s.id
                    ) top_s GROUP BY name ORDER BY request_count DESC LIMIT 5
                ");
            } else {
                $stmtTop = $this->db->query("
                    SELECT s.name, COUNT(*) as request_count
                    FROM manual_requests r JOIN services s ON r.service_id = s.id
                    GROUP BY name ORDER BY request_count DESC LIMIT 5
                ");
            }
            $stats['top_services'] = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

            // ── Recent requests (unified) ──────────────────────────────────────────
            if ($apiTxnExists) {
                $stmtRecent = $this->db->query("
                    SELECT reference, status, price_paid, submitted_at, service_name FROM (
                        SELECT r.reference, r.status, r.price_paid, r.submitted_at, s.name as service_name
                        FROM manual_requests r
                        JOIN services s ON r.service_id = s.id
                        UNION ALL
                        SELECT at.gv_reference as reference, at.gv_status as status,
                               COALESCE(sp.price, t.amount, 0) as price_paid,
                               at.submitted_at, s.name as service_name
                        FROM api_transactions at
                        JOIN services s ON at.service_id = s.id
                        LEFT JOIN service_pricing sp ON at.pricing_id = sp.id
                        LEFT JOIN transactions t ON at.transaction_id = t.id
                    ) reqs ORDER BY submitted_at DESC LIMIT 10
                ");
            } else {
                $stmtRecent = $this->db->query("
                    SELECT r.reference, r.status, r.price_paid, r.submitted_at, s.name as service_name
                    FROM manual_requests r
                    JOIN services s ON r.service_id = s.id
                    ORDER BY r.submitted_at DESC LIMIT 10
                ");
            }
            $stats['recent_requests'] = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

            Response::success($stats);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function getUsers(): void
    {
        try {
            $page    = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = 50;
            $offset  = ($page - 1) * $perPage;
            $filter  = $_GET['filter'] ?? 'all'; // all | active | suspended

            $where = '';
            if ($filter === 'active')    $where = 'WHERE u.is_active = 1';
            if ($filter === 'suspended') $where = 'WHERE u.is_active = 0';
            if ($filter === 'today')     $where = "WHERE u.is_active = 1 AND u.updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";

            $total = (int) $this->db->query(
                "SELECT COUNT(*) FROM users u $where"
            )->fetchColumn();

            $stmt = $this->db->prepare("
                SELECT u.id, u.business_name, u.email, u.phone,
                       u.is_active, u.created_at, u.updated_at,
                       COALESCE(w.balance, 0) AS wallet_balance,
                       (SELECT COUNT(*) FROM manual_requests WHERE user_id = u.id) AS request_count
                FROM users u
                LEFT JOIN wallets w ON w.user_id = u.id
                $where
                ORDER BY u.created_at DESC
                LIMIT $perPage OFFSET $offset
            ");
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success([
                'users'      => $users,
                'total'      => $total,
                'page'       => $page,
                'per_page'   => $perPage,
                'last_page'  => (int) ceil($total / $perPage),
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function getTransactions(): void
    {
        try {
            $page    = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = 50;
            $offset  = ($page - 1) * $perPage;

            $total = (int) $this->db->query("SELECT COUNT(*) FROM transactions")->fetchColumn();

            $stmt = $this->db->prepare("
                SELECT t.id, t.reference, t.type, t.amount,
                       t.balance_before, t.balance_after,
                       t.description, t.status, t.created_at,
                       u.business_name, u.email
                FROM transactions t
                JOIN users u ON u.id = t.user_id
                ORDER BY t.created_at DESC
                LIMIT $perPage OFFSET $offset
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success([
                'transactions' => $rows,
                'total'        => $total,
                'page'         => $page,
                'per_page'     => $perPage,
                'last_page'    => (int) ceil($total / $perPage),
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function getServices(): void
    {
        try {
            // NOTE: Uses LEFT JOIN + COALESCE so services with missing/null category_id are not excluded.
            // Avoids GROUP BY to prevent ONLY_FULL_GROUP_BY issues on production MySQL.
            $stmt = $this->db->query("
                SELECT s.id, s.name, s.slug, s.is_active, s.is_manual, s.est_time,
                       COALESCE(c.name, 'General') AS category
                FROM services s
                LEFT JOIN service_categories c ON s.category_id = c.id
                ORDER BY c.name, s.name
            ");
            $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Auto-seed if the production services table is empty
            if (empty($services)) {
                $this->seedDatabaseInternal();
                $stmt = $this->db->query("
                    SELECT s.id, s.name, s.slug, s.is_active, s.is_manual, s.est_time,
                           COALESCE(c.name, 'General') AS category
                    FROM services s
                    LEFT JOIN service_categories c ON s.category_id = c.id
                    ORDER BY c.name, s.name
                ");
                $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            foreach ($services as &$service) {
                $stmtPrice = $this->db->prepare("SELECT id as pricing_id, variant_key, price, COALESCE(cost_price, 0) as cost_price FROM service_pricing WHERE service_id = ?");
                $stmtPrice->execute([$service['id']]);
                $service['pricing'] = $stmtPrice->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($service);

            Response::success($services);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function seedDatabase(): void
    {
        try {
            $this->seedDatabaseInternal();
            Response::success(['message' => 'Default categories, services and pricing seeded successfully.']);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    private function seedDatabaseInternal(): void
    {
        try {
            $pdo = $this->db;
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

            // 1. Seed Categories
            $categories = [
                ['name' => 'NIN', 'slug' => 'nin', 'sort_order' => 1],
                ['name' => 'BVN', 'slug' => 'bvn', 'sort_order' => 2],
                ['name' => 'JAMB', 'slug' => 'jamb', 'sort_order' => 3],
                ['name' => 'CAC', 'slug' => 'cac', 'sort_order' => 4],
                ['name' => 'TIN', 'slug' => 'tin', 'sort_order' => 5],
                ['name' => 'ATTESTATION', 'slug' => 'attestation', 'sort_order' => 6]
            ];

            $stmt = $pdo->prepare("INSERT IGNORE INTO service_categories (name, slug, sort_order) VALUES (:name, :slug, :sort_order)");
            foreach ($categories as $cat) {
                $stmt->execute($cat);
            }

            $catMap = [];
            foreach ($pdo->query("SELECT id, slug FROM service_categories") as $row) {
                $catMap[$row['slug']] = $row['id'];
            }

            // 2. Seed Services & Pricing Variants
            $services = [
                // NIN Category
                [
                    'category_slug' => 'nin',
                    'name' => 'NIN Enrollment',
                    'slug' => 'nin-enrollment',
                    'description' => 'New NIN Enrollment for Adult and Child',
                    'est_time' => '1-7 days',
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 2000]
                    ]
                ],
                [
                    'category_slug' => 'nin',
                    'name' => 'NIN Verification',
                    'slug' => 'nin-verification',
                    'description' => 'Verify NIN records instantly via slip or search',
                    'est_time' => 'Instant',
                    'is_manual' => 0,
                    'pricing' => [
                        ['variant_key' => 'basic', 'variant_label' => 'Basic Slip', 'price' => 250],
                        ['variant_key' => 'regular', 'variant_label' => 'Regular Slip', 'price' => 350],
                        ['variant_key' => 'standard', 'variant_label' => 'Standard Slip', 'price' => 500],
                        ['variant_key' => 'premium', 'variant_label' => 'Premium Slip', 'price' => 800],
                        ['variant_key' => 'vnin', 'variant_label' => 'vNIN Slip', 'price' => 1000]
                    ]
                ],
                [
                    'category_slug' => 'nin',
                    'name' => 'NIN Validation (Single & Bulk)',
                    'slug' => 'nin-validation',
                    'description' => 'Bulk NIN Validation service',
                    'est_time' => 'Instant',
                    'pricing' => [
                        ['variant_key' => 'No Record Found — ₦300', 'variant_label' => 'No Record Found', 'price' => 300],
                        ['variant_key' => 'SIM Validation — ₦200', 'variant_label' => 'SIM Validation', 'price' => 200],
                        ['variant_key' => 'vNIN validation', 'variant_label' => 'vNIN validation', 'price' => 250],
                        ['variant_key' => 'Update Records Validation — ₦400', 'variant_label' => 'Update Records Validation', 'price' => 400],
                        ['variant_key' => 'Bank Validation — ₦300', 'variant_label' => 'Bank Validation', 'price' => 300],
                        ['variant_key' => 'Modification Validation — ₦350', 'variant_label' => 'Modification Validation', 'price' => 350],
                        ['variant_key' => 'Photographic Error — ₦300', 'variant_label' => 'Photographic Error', 'price' => 300]
                    ]
                ],
                [
                    'category_slug' => 'nin',
                    'name' => 'IPE Modification',
                    'slug' => 'ipe-modification',
                    'description' => 'NIN IPE Clearance and Modification',
                    'est_time' => '2-5 days',
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 1200]
                    ]
                ],
                [
                    'category_slug' => 'nin',
                    'name' => 'IPE Clearance — Bulk',
                    'slug' => 'ipe-clearance',
                    'description' => 'Bulk IPE Clearance Processing',
                    'est_time' => 'Instant',
                    'is_manual' => 1,
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Per Tracking ID', 'price' => 500]
                    ]
                ],
                [
                    'category_slug' => 'nin',
                    'name' => 'IPE Clearance — Single',
                    'slug' => 'ipe-clearance-single',
                    'description' => 'Single IPE Clearance Processing',
                    'est_time' => 'Instant',
                    'is_manual' => 0,
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 500]
                    ]
                ],
                [
                    'category_slug' => 'nin',
                    'name' => 'NIN Personalisation',
                    'slug' => 'personalization',
                    'description' => 'NIN Personalisation Processing',
                    'est_time' => '1-3 days',
                    'is_manual' => 0,
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 800]
                    ]
                ],
                [
                    'category_slug' => 'nin',
                    'name' => 'NIN Modification',
                    'slug' => 'nin-modification',
                    'description' => 'Modify Name, Phone, DOB or Address on NIN',
                    'est_time' => '3-7 days',
                    'pricing' => [
                        ['variant_key' => 'Name', 'variant_label' => 'Name Modification', 'price' => 1500],
                        ['variant_key' => 'Phone', 'variant_label' => 'Phone Modification', 'price' => 800],
                        ['variant_key' => 'DOB', 'variant_label' => 'DOB Modification', 'price' => 5000],
                        ['variant_key' => 'Address', 'variant_label' => 'Address Modification', 'price' => 1200],
                        ['variant_key' => 'Name&DOB', 'variant_label' => 'Name & DOB Modification', 'price' => 6000],
                        ['variant_key' => 'Name&Phone', 'variant_label' => 'Name & Phone Modification', 'price' => 2000]
                    ]
                ],
                [
                    'category_slug' => 'nin',
                    'name' => 'DOB More Than 5 Years',
                    'slug' => 'dob-correction',
                    'description' => 'DOB Correction above 5 years tier',
                    'est_time' => '5-15 days',
                    'pricing' => [
                        ['variant_key' => 'Above 5 Years', 'variant_label' => 'Above 5 Years Tier', 'price' => 10000],
                        ['variant_key' => 'Above 10 Years', 'variant_label' => 'Above 10 Years Tier', 'price' => 15000],
                        ['variant_key' => 'Above 15 Years', 'variant_label' => 'Above 15 Years Tier', 'price' => 20000]
                    ]
                ],
                [
                    'category_slug' => 'nin',
                    'name' => 'Self-Service Delinking',
                    'slug' => 'self-service',
                    'description' => 'Delinking Email or Retrieval of NIN Details',
                    'est_time' => 'Instant',
                    'is_manual' => 0,
                    'pricing' => [
                        ['variant_key' => 'Delinking Email', 'variant_label' => 'Delinking Email', 'price' => 500],
                        ['variant_key' => 'Retrieval NIN Details', 'variant_label' => 'Retrieval NIN Details', 'price' => 500]
                    ]
                ],

                // BVN Category
                [
                    'category_slug' => 'bvn',
                    'name' => 'BVN Verification',
                    'slug' => 'bvn-verification',
                    'description' => 'Verify BVN details via slip or search',
                    'est_time' => 'Instant',
                    'is_manual' => 0,
                    'pricing' => [
                        ['variant_key' => 'full', 'variant_label' => 'Full Details Slip', 'price' => 400],
                        ['variant_key' => 'premium', 'variant_label' => 'Premium Slip', 'price' => 700]
                    ]
                ],
                [
                    'category_slug' => 'bvn',
                    'name' => 'BVN Retrieval',
                    'slug' => 'bvn-retrieval',
                    'description' => 'Retrieve BVN details by name/phone',
                    'est_time' => 'Instant',
                    'is_manual' => 0,
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 400]
                    ]
                ],
                [
                    'category_slug' => 'bvn',
                    'name' => 'BVN License Creation',
                    'slug' => 'bvn-license',
                    'description' => 'Create or Re-issue BVN License',
                    'est_time' => '1-3 days',
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 3000]
                    ]
                ],
                [
                    'category_slug' => 'bvn',
                    'name' => 'Non-Appearance Enrollment',
                    'slug' => 'bvn-nonappearance',
                    'description' => 'Non-Appearance BVN Enrollment',
                    'est_time' => '1-7 days',
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 25000]
                    ]
                ],
                [
                    'category_slug' => 'bvn',
                    'name' => 'Central Risk Management',
                    'slug' => 'bvn-risk',
                    'description' => 'Central Risk Management Resolution',
                    'est_time' => '24-48 hrs',
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 2000]
                    ]
                ],
                [
                    'category_slug' => 'bvn',
                    'name' => 'BVN Modification',
                    'slug' => 'bvn-modification',
                    'description' => 'Update Name, Phone, DOB or Address on BVN',
                    'est_time' => '3-7 days',
                    'pricing' => [
                        ['variant_key' => 'Update Name', 'variant_label' => 'Update Name', 'price' => 1500],
                        ['variant_key' => 'Update Phone', 'variant_label' => 'Update Phone', 'price' => 800],
                        ['variant_key' => 'Update DOB', 'variant_label' => 'Update DOB', 'price' => 5000],
                        ['variant_key' => 'Update Address', 'variant_label' => 'Update Address', 'price' => 1200]
                    ]
                ],

                // JAMB Category
                [
                    'category_slug' => 'jamb',
                    'name' => 'JAMB Original Result Slip',
                    'slug' => 'jamb-original-result',
                    'description' => 'Original Result Slip Print Out',
                    'est_time' => 'Instant',
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 1500]
                    ]
                ],
                [
                    'category_slug' => 'jamb',
                    'name' => 'JAMB 2026 Exam Slip Printing',
                    'slug' => 'jamb-2026-slip',
                    'description' => 'Reprint 2026 UTME Exam Slip',
                    'est_time' => 'Instant',
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 1000]
                    ]
                ],
                [
                    'category_slug' => 'jamb',
                    'name' => 'JAMB Admission Letter Print Out',
                    'slug' => 'jamb-admission-letter',
                    'description' => 'Original Admission Letter Print Out',
                    'est_time' => 'Instant',
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 1500]
                    ]
                ],
                [
                    'category_slug' => 'jamb',
                    'name' => 'JAMB Re-Prints / Other Services',
                    'slug' => 'jamb-reprints',
                    'description' => 'JAMB Re-Prints and related processing',
                    'est_time' => 'Instant',
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 1000]
                    ]
                ],
                [
                    'category_slug' => 'jamb',
                    'name' => 'JAMB Reprint Original Result Slip',
                    'slug' => 'jamb-reprint-original',
                    'description' => 'Reprint Original Result Slip',
                    'est_time' => 'Instant',
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 1000]
                    ]
                ],

                // CAC Category
                [
                    'category_slug' => 'cac',
                    'name' => 'Business Name Registration',
                    'slug' => 'cac-business',
                    'description' => 'CAC Business Name Registration',
                    'est_time' => '3-5 days',
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 25000]
                    ]
                ],
                [
                    'category_slug' => 'cac',
                    'name' => 'Company LTD Setup',
                    'slug' => 'cac-ltd',
                    'description' => 'CAC Company Limited Registration',
                    'est_time' => '5-7 days',
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 45000]
                    ]
                ],

                // TIN Category
                [
                    'category_slug' => 'tin',
                    'name' => 'TIN Registration',
                    'slug' => 'tin-registration',
                    'description' => 'Individual or Company Tax ID Registration',
                    'est_time' => '1-3 days',
                    'pricing' => [
                        ['variant_key' => 'Individual TIN', 'variant_label' => 'Individual TIN', 'price' => 1500],
                        ['variant_key' => 'Company TIN', 'variant_label' => 'Company TIN', 'price' => 1500]
                    ]
                ],

                // Attestation Category
                [
                    'category_slug' => 'attestation',
                    'name' => 'NIN Attestation',
                    'slug' => 'nin-attestation',
                    'description' => 'Official NIN Attestation Document',
                    'est_time' => '5-7 days',
                    'pricing' => [
                        ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 17000]
                    ]
                ]
            ];

            $svcStmt = $pdo->prepare("INSERT INTO services (category_id, name, slug, description, est_time, is_manual) 
                                      VALUES (:category_id, :name, :slug, :description, :est_time, :is_manual)
                                      ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), est_time=VALUES(est_time), is_manual=VALUES(is_manual)");

            $priceStmt = $pdo->prepare("INSERT INTO service_pricing (service_id, variant_key, variant_label, price) 
                                        VALUES (:service_id, :variant_key, :variant_label, :price)
                                        ON DUPLICATE KEY UPDATE price=VALUES(price), variant_label=VALUES(variant_label)");

            foreach ($services as $svc) {
                if (!isset($catMap[$svc['category_slug']])) continue;
                $svcStmt->execute([
                    'category_id' => $catMap[$svc['category_slug']],
                    'name' => $svc['name'],
                    'slug' => $svc['slug'],
                    'description' => $svc['description'],
                    'est_time' => $svc['est_time'],
                    'is_manual' => $svc['is_manual'] ?? 1
                ]);
                
                $serviceId = $pdo->query("SELECT id FROM services WHERE slug = '{$svc['slug']}'")->fetchColumn();

                if ($serviceId) {
                    foreach ($svc['pricing'] as $price) {
                        if ($price['variant_key'] === null) {
                            $pdo->exec("DELETE FROM service_pricing WHERE service_id = $serviceId AND variant_key IS NULL");
                        } else {
                            $pdo->exec("DELETE FROM service_pricing WHERE service_id = $serviceId AND variant_key = " . $pdo->quote($price['variant_key']));
                        }

                        $priceStmt->execute([
                            'service_id' => $serviceId,
                            'variant_key' => $price['variant_key'],
                            'variant_label' => $price['variant_label'],
                            'price' => $price['price']
                        ]);
                    }
                }
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        } catch (Exception $e) {
            error_log('[seedDatabaseInternal] ' . $e->getMessage());
        }
    }

    public function updateService(int $id): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        try {
            $stmtSvc = $this->db->prepare("SELECT name, is_active, is_manual, est_time FROM services WHERE id = ?");
            $stmtSvc->execute([$id]);
            $existing = $stmtSvc->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                Response::notFound('Service not found');
            }

            $name = isset($input['name']) ? $input['name'] : $existing['name'];
            $isActive = isset($input['active']) ? (int)$input['active'] : (int)$existing['is_active'];
            $isManual = isset($input['is_manual']) ? (int)$input['is_manual'] : (int)$existing['is_manual'];
            $estTime = array_key_exists('est_time', $input) ? $input['est_time'] : $existing['est_time'];

            if (!$name) {
                Response::error('Service name is required', [], 400);
            }

            $this->db->beginTransaction();

            $stmt = $this->db->prepare("UPDATE services SET name = ?, is_active = ?, is_manual = ?, est_time = ? WHERE id = ?");
            $stmt->execute([$name, $isActive, $isManual, $estTime, $id]);

            $adminId = \Middleware\AdminMiddleware::getAdminId();
            $stmtAudit = $this->db->prepare("INSERT INTO audit_logs (actor_type, actor_id, action, notes) VALUES ('admin', ?, 'SERVICE_UPDATED', ?)");
            $stmtAudit->execute([$adminId, "Updated service $id settings: " . json_encode($input)]);

            $this->db->commit();
            Response::success(['success' => true, 'message' => 'Service updated successfully']);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function updateServicePrice(string $id, string $pricingId): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $price = isset($input['price']) ? (float)$input['price'] : null;
        $costPrice = isset($input['cost_price']) ? (float)$input['cost_price'] : 0.00;

        if ($price === null || $price <= 0) {
            Response::error('Valid selling price > 0 is required', [], 400);
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("UPDATE service_pricing SET price = ?, cost_price = ? WHERE id = ? AND service_id = ?");
            $stmt->execute([$price, $costPrice, $pricingId, $id]);

            $adminId = \Middleware\AdminMiddleware::getAdminId();
            $stmtAudit = $this->db->prepare("INSERT INTO audit_logs (actor_type, actor_id, action, notes) VALUES ('admin', ?, 'PRICE_UPDATED', ?)");
            $stmtAudit->execute([$adminId, "Updated pricing $pricingId for service $id: Selling Price = $price, Provider Cost Price = $costPrice"]);

            $this->db->commit();
            Response::success(['success' => true, 'message' => 'Pricing and provider cost updated successfully']);
        } catch (Exception $e) {
            $this->db->rollBack();
            Response::error($e->getMessage(), [], 500);
        }
    }
}





