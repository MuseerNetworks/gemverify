<?php

namespace Models;

class ServicePricing extends BaseModel
{
    protected static string $table = 'service_pricing';

    public function getPrice(int $serviceId, ?string $variantKey): array|false
    {
        if ($variantKey === null) {
            $sql = "SELECT * FROM " . static::$table . " WHERE service_id = :service_id AND variant_key IS NULL LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['service_id' => $serviceId]);
        } else {
            $sql = "SELECT * FROM " . static::$table . " WHERE service_id = :service_id AND variant_key = :variant_key LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['service_id' => $serviceId, 'variant_key' => $variantKey]);
        }
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function getPriceBySlugAndVariant(string $serviceSlug, ?string $variantKey): array|false
    {
        if ($variantKey === null) {
            $sql = "SELECT sp.* FROM " . static::$table . " sp 
                    INNER JOIN services s ON sp.service_id = s.id 
                    WHERE s.slug = :slug AND sp.variant_key IS NULL LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['slug' => $serviceSlug]);
        } else {
            $sql = "SELECT sp.* FROM " . static::$table . " sp 
                    INNER JOIN services s ON sp.service_id = s.id 
                    WHERE s.slug = :slug AND sp.variant_key = :variant_key LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['slug' => $serviceSlug, 'variant_key' => $variantKey]);
        }
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function getAllForService(int $serviceId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE service_id = :service_id ORDER BY price ASC");
        $stmt->execute(['service_id' => $serviceId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function updatePrice(int $id, float $newPrice): bool
    {
        $stmt = $this->db->prepare("UPDATE " . static::$table . " SET price = :price, updated_at = NOW() WHERE id = :id");
        return $stmt->execute(['price' => $newPrice, 'id' => $id]);
    }
}



