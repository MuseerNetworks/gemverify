<?php
namespace Controllers;

use Helpers\Response;
require_once __DIR__ . "/../../config/database.php";
use PDO;

class ServiceController {
    
    public function getAll(): void {
        $db = db();
        
        $stmt = $db->query("
            SELECT s.id, s.name, s.slug, s.description, s.est_time, s.is_manual, c.name as category 
            FROM services s 
            JOIN service_categories c ON s.category_id = c.id
            WHERE s.is_active = 1 
            ORDER BY c.name, s.name
        ");
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $serviceIds = array_column($services, 'id');
        $variants = [];
        
        if (!empty($serviceIds)) {
            $in = str_repeat('?,', count($serviceIds) - 1) . '?';
            $vStmt = $db->prepare("SELECT service_id, variant_key, price FROM service_pricing WHERE service_id IN ($in)");
            $vStmt->execute($serviceIds);
            while ($row = $vStmt->fetch(PDO::FETCH_ASSOC)) {
                $variants[$row['service_id']][] = [
                    'key' => $row['variant_key'],
                    'price' => (float) $row['price']
                ];
            }
        }
        
        $grouped = [];
        foreach ($services as $svc) {
            $cat = $svc['category'];
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $svc['variants'] = $variants[$svc['id']] ?? [];
            $grouped[$cat][] = $svc;
        }
        
        Response::success($grouped);
    }

    public function getBySlug(string $slug): void {
        $db = db();
        
        $stmt = $db->prepare("
            SELECT s.id, s.name, s.slug, s.description, s.est_time, s.is_manual, c.name as category 
            FROM services s 
            JOIN service_categories c ON s.category_id = c.id
            WHERE s.slug = ? AND s.is_active = 1
        ");
        $stmt->execute([$slug]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$service) {
            Response::notFound('Service not found');
        }
        
        $vStmt = $db->prepare("SELECT variant_key, price FROM service_pricing WHERE service_id = ?");
        $vStmt->execute([$service['id']]);
        $service['variants'] = [];
        while ($row = $vStmt->fetch(PDO::FETCH_ASSOC)) {
            $service['variants'][] = [
                'key' => $row['variant_key'],
                'price' => (float) $row['price']
            ];
        }
        
        Response::success($service);
    }

    public function getPrice(string $slug): void {
        $variantKey = $_GET['variant'] ?? null;
        
        $db = db();
        
        $stmt = $db->prepare("SELECT id FROM services WHERE slug = ? AND is_active = 1");
        $stmt->execute([$slug]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$service) {
            Response::success(['message' => 'Service not found'], 404);
            return;
        }
        
        if ($variantKey) {
            $pStmt = $db->prepare("SELECT price FROM service_pricing WHERE service_id = ? AND variant_key = ?");
            $pStmt->execute([$service['id'], $variantKey]);
        } else {
            $pStmt = $db->prepare("SELECT price FROM service_pricing WHERE service_id = ? AND (variant_key IS NULL OR variant_key = '')");
            $pStmt->execute([$service['id']]);
        }
        
        $priceRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$priceRow) {
            Response::success(['message' => 'Price not found for specified variant'], 404);
            return;
        }
        
        Response::success(['price' => (float) $priceRow['price']]);
    }
}




