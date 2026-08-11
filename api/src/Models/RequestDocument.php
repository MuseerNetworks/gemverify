<?php

namespace Models;

class RequestDocument extends BaseModel
{
    protected static string $table = 'request_documents';

    public function createDocument(array $data): int|false
    {
        $fields = array_keys($data);
        $placeholders = array_map(fn($f) => ":$f", $fields);
        
        $sql = "INSERT INTO " . static::$table . " (" . implode(', ', $fields) . ", uploaded_at) 
                VALUES (" . implode(', ', $placeholders) . ", NOW())";
        
        $stmt = $this->db->prepare($sql);
        if ($stmt->execute($data)) {
            return (int)$this->db->lastInsertId();
        }
        return false;
    }

    public function getForRequest(int $requestId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE request_id = :request_id ORDER BY uploaded_at ASC");
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



