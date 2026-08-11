<?php

namespace Models;

class Service extends BaseModel
{
    protected static string $table = 'services';

    public function findBySlug(string $slug): array|false
    {
        $sql = "SELECT s.*, sc.name as category_name, sc.slug as category_slug, sc.is_active as category_is_active 
                FROM " . static::$table . " s 
                LEFT JOIN service_categories sc ON c.name as category_id = sc.id 
                WHERE s.slug = :slug LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function getAll(): array
    {
        $sql = "SELECT s.*, sc.name as category_name 
                FROM " . static::$table . " s 
                LEFT JOIN service_categories sc ON c.name as category_id = sc.id 
                WHERE s.is_active = 1 AND sc.is_active = 1
                ORDER BY sc.name ASC, s.name ASC";
        $stmt = $this->db->query($sql);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $grouped = [];
        foreach ($results as $row) {
            $cat = $row['category_name'] ?? 'Uncategorized';
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $grouped[$cat][] = $row;
        }
        return $grouped;
    }

    public function getAllWithPricing(): array
    {
        $sql = "SELECT s.*, sp.id as pricing_id, sp.variant_key, sp.variant_name, sp.price 
                FROM " . static::$table . " s 
                LEFT JOIN service_pricing sp ON s.id = sp.service_id 
                WHERE s.is_active = 1 
                ORDER BY s.name ASC, sp.price ASC";
        $stmt = $this->db->query($sql);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $services = [];
        foreach ($results as $row) {
            $id = $row['id'];
            if (!isset($services[$id])) {
                $services[$id] = $row;
                $services[$id]['pricing'] = [];
                unset($services[$id]['pricing_id'], $services[$id]['variant_key'], $services[$id]['variant_name'], $services[$id]['price']);
            }
            if ($row['pricing_id']) {
                $services[$id]['pricing'][] = [
                    'id' => $row['pricing_id'],
                    'variant_key' => $row['variant_key'],
                    'variant_name' => $row['variant_name'],
                    'price' => (float)$row['price']
                ];
            }
        }
        return array_values($services);
    }

    public function findActiveBySlug(string $slug): array|false
    {
        $sql = "SELECT s.* 
                FROM " . static::$table . " s 
                INNER JOIN service_categories sc ON c.name as category_id = sc.id 
                WHERE s.slug = :slug AND s.is_active = 1 AND sc.is_active = 1 
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}




