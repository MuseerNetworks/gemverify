<?php
namespace Services;

use PDO;
use RuntimeException;

class PricingService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getPrice(string $serviceSlug, ?string $variantKey): array {
        if ($variantKey !== null) {
            $stmt = $this->db->prepare("
                SELECT p.price, p.id as pricing_id, s.id as service_id, s.name as service_name, s.est_time, s.is_manual
                FROM service_pricing p
                JOIN services s ON p.service_id = s.id
                WHERE s.slug = :slug AND p.variant_key = :variantKey AND s.is_active = 1 AND p.is_active = 1
            ");
            $stmt->execute(['slug' => $serviceSlug, 'variantKey' => $variantKey]);
        } else {
            $stmt = $this->db->prepare("
                SELECT p.price, p.id as pricing_id, s.id as service_id, s.name as service_name, s.est_time, s.is_manual
                FROM service_pricing p
                JOIN services s ON p.service_id = s.id
                WHERE s.slug = :slug AND p.variant_key IS NULL AND s.is_active = 1 AND p.is_active = 1
            ");
            $stmt->execute(['slug' => $serviceSlug]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (float)$row['price'] <= 0) {
            throw new RuntimeException("Pricing not configured or invalid for service slug: $serviceSlug");
        }

        return [
            'price' => (float)$row['price'],
            'pricing_id' => (int)$row['pricing_id'],
            'service_id' => (int)$row['service_id'],
            'service_name' => $row['service_name'],
            'est_time' => $row['est_time'],
            'is_manual' => (bool)$row['is_manual']
        ];
    }

    public function getServiceBySlug(string $slug): array {
        $stmt = $this->db->prepare("
            SELECT s.*, c.name as category_name
            FROM services s JOIN service_categories c ON s.category_id = c.id
            WHERE s.slug = :slug AND s.is_active = 1
        ");
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException("Service not found or inactive: $slug");
        }
        return $row;
    }

    public function calculateBulkPrice(string $serviceSlug, string $variantKey, int $count): array {
        $pricing = $this->getPrice($serviceSlug, $variantKey);
        $pricing['price'] = $pricing['price'] * $count;
        return $pricing;
    }
}




