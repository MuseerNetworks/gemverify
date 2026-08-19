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
    }

    public function getStats(): void
    {
        try {
            $stats = [];
            
            // Totals
            $stmt = $this->db->query("
                SELECT 
                    COUNT(*) as total_requests,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as total_completed,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as total_pending,
                    SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as total_under_review,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as total_rejected,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN price_paid ELSE 0 END), 0) as total_revenue
                FROM manual_requests
            ");
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats = array_merge($stats, $totals);

            // User & Wallet Totals
            $stmtUsers = $this->db->query("
                SELECT 
                    (SELECT COUNT(*) FROM users WHERE is_active = 1) as total_users,
                    (SELECT COUNT(*) FROM users WHERE is_active = 1 AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) as active_users,
                    (SELECT COALESCE(SUM(balance), 0) FROM wallets) as total_wallet_liability
            ");
            $userStats = $stmtUsers->fetch(PDO::FETCH_ASSOC);
            $stats = array_merge($stats, $userStats);
            // Today's stats
            $stmtToday = $this->db->query("
                SELECT 
                    COUNT(*) as requests_today,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN price_paid ELSE 0 END), 0) as revenue_today
                FROM manual_requests 
                WHERE DATE(submitted_at) = CURRENT_DATE()
            ");
            $today = $stmtToday->fetch(PDO::FETCH_ASSOC);
            $stats = array_merge($stats, $today);
            // Overwrite total_revenue on the KPI card with today's revenue so the label matches
            $stats['total_revenue'] = (float)($stats['revenue_today'] ?? 0);
            $stats['total_profit'] = (float)$stats['total_revenue'] * 0.25;
            $stats['withdrawable_profit'] = (float)$stats['total_revenue'] * 0.25;
            $stats['provider_balance'] = 0.00;

            // By status
            $stmtStatus = $this->db->query("SELECT status, COUNT(*) as count FROM manual_requests GROUP BY status");
            $stats['requests_by_status'] = $stmtStatus->fetchAll(PDO::FETCH_ASSOC);

            // By category
            $stmtCategory = $this->db->query("
                SELECT c.name as category, COUNT(r.id) as count 
                FROM manual_requests r 
                JOIN services s ON r.service_id = s.id 
                JOIN service_categories c ON s.category_id = c.id
                GROUP BY c.name
            ");
            $stats['requests_by_category'] = $stmtCategory->fetchAll(PDO::FETCH_ASSOC);

            // Top services
            $stmtTop = $this->db->query("
                SELECT s.name, COUNT(r.id) as request_count 
                FROM manual_requests r 
                JOIN services s ON r.service_id = s.id 
                GROUP BY s.id 
                ORDER BY request_count DESC LIMIT 5
            ");
            $stats['top_services'] = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

            // Recent requests
            $stmtRecent = $this->db->query("
                SELECT r.reference, r.status, r.price_paid, r.submitted_at, s.name as service_name
                FROM manual_requests r
                JOIN services s ON r.service_id = s.id
                ORDER BY r.submitted_at DESC LIMIT 10
            ");
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
            $stmt = $this->db->query("
                SELECT s.id, s.name, s.slug, s.is_active, s.is_manual, s.est_time, c.name as category, 
                       COUNT(r.id) as request_count,
                       COALESCE(SUM(CASE WHEN r.status = 'completed' THEN r.price_paid ELSE 0 END), 0) as revenue,
                       0 as avg_processing_time
                FROM services s JOIN service_categories c ON s.category_id = c.id
                LEFT JOIN manual_requests r ON s.id = r.service_id
                GROUP BY s.id
            ");
            $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($services as &$service) {
                $stmtPrice = $this->db->prepare("SELECT id as pricing_id, variant_key, price FROM service_pricing WHERE service_id = ?");
                $stmtPrice->execute([$service['id']]);
                $service['pricing'] = $stmtPrice->fetchAll(PDO::FETCH_ASSOC);
            }

            Response::success(['success' => true, 'data' => $services]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
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

            $adminId = $_SERVER['ADMIN_ID'] ?? 1;
            $stmtAudit = $this->db->prepare("INSERT INTO audit_logs (actor_type, actor_id, action, notes) VALUES ('admin', ?, 'SERVICE_UPDATED', ?)");
            $stmtAudit->execute([$adminId, "Updated service $id settings: " . json_encode($input)]);

            $this->db->commit();
            Response::success(['success' => true, 'message' => 'Service updated successfully']);
        } catch (Exception $e) {
            $this->db->rollBack();
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function updateServicePrice(string $id, string $pricingId): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $price = $input['price'] ?? null;

        if ($price === null || $price <= 0) {
            Response::error('Valid price > 0 is required', [], 400);
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("UPDATE service_pricing SET price = ? WHERE id = ? AND service_id = ?");
            $stmt->execute([$price, $pricingId, $id]);

            $adminId = $_SERVER['ADMIN_ID'] ?? 1;
            $stmtAudit = $this->db->prepare("INSERT INTO audit_logs (actor_type, actor_id, action, notes) VALUES ('admin', ?, 'PRICE_UPDATED', ?)");
            $stmtAudit->execute([$adminId, "Updated pricing $pricingId for service $id to $price"]);

            $this->db->commit();
            Response::success(['success' => true, 'message' => 'Price updated successfully']);
        } catch (Exception $e) {
            $this->db->rollBack();
            Response::error($e->getMessage(), [], 500);
        }
    }
}





