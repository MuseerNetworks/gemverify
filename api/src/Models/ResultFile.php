<?php

namespace Models;

class ResultFile extends BaseModel
{
    protected static string $table = 'result_files';

    public function createResult(array $data): int|false
    {
        // First mark existing as not current
        if (!empty($data['request_id'])) {
            $upd = $this->db->prepare("UPDATE " . static::$table . " SET is_current = 0 WHERE request_id = :request_id");
            $upd->execute(['request_id' => $data['request_id']]);
            
            // Get max version
            $verStmt = $this->db->prepare("SELECT MAX(version) as max_v FROM " . static::$table . " WHERE request_id = :request_id");
            $verStmt->execute(['request_id' => $data['request_id']]);
            $verRow = $verStmt->fetch(\PDO::FETCH_ASSOC);
            $data['version'] = ($verRow['max_v'] ?? 0) + 1;
        } else {
            $data['version'] = 1;
        }
        
        $data['is_current'] = 1;
        
        $fields = array_keys($data);
        $placeholders = array_map(fn($f) => ":$f", $fields);
        
        $sql = "INSERT INTO " . static::$table . " (" . implode(', ', $fields) . ", created_at) 
                VALUES (" . implode(', ', $placeholders) . ", NOW())";
        
        $stmt = $this->db->prepare($sql);
        if ($stmt->execute($data)) {
            return (int)$this->db->lastInsertId();
        }
        return false;
    }

    public function getCurrentForRequest(int $requestId): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE request_id = :request_id AND is_current = 1 LIMIT 1");
        $stmt->execute(['request_id' => $requestId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function getAllForRequest(int $requestId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE request_id = :request_id ORDER BY version DESC");
        $stmt->execute(['request_id' => $requestId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findById(int $id): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}



