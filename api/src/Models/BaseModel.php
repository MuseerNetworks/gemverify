<?php

namespace Models;

use Config\Database;
use PDO;
use PDOStatement;

abstract class BaseModel
{
    protected static string $table;
    protected PDO $db;

    public function __construct()
    {
        $this->db = db();
    }

    protected function find(int $id): array|false
    {
        $stmt = $this->query("SELECT * FROM " . static::$table . " WHERE id = :id LIMIT 1", ['id' => $id]);
        return $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    }

    protected function findBy(string $column, mixed $value): array|false
    {
        $stmt = $this->query("SELECT * FROM " . static::$table . " WHERE {$column} = :val LIMIT 1", ['val' => $value]);
        return $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    }

    protected function findAllBy(string $column, mixed $value, string $orderBy = 'id DESC', int $limit = 100): array
    {
        $stmt = $this->query("SELECT * FROM " . static::$table . " WHERE {$column} = :val ORDER BY {$orderBy} LIMIT {$limit}", ['val' => $value]);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    protected function create(array $data): int|false
    {
        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $placeholders = ':' . implode(', :', $keys);
        
        $sql = "INSERT INTO " . static::$table . " ({$fields}) VALUES ({$placeholders})";
        
        if ($this->query($sql, $data)) {
            return (int)$this->db->lastInsertId();
        }
        return false;
    }

    protected function update(int $id, array $data): bool
    {
        $setParts = [];
        foreach ($data as $key => $val) {
            $setParts[] = "{$key} = :{$key}";
        }
        $setStr = implode(', ', $setParts);
        $data['id'] = $id;
        
        $sql = "UPDATE " . static::$table . " SET {$setStr} WHERE id = :id";
        $stmt = $this->query($sql, $data);
        
        return $stmt !== false;
    }

    protected function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM " . static::$table . " WHERE id = :id", ['id' => $id]);
        return $stmt !== false;
    }

    protected function query(string $sql, array $params = []): PDOStatement|false
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return false;
        }
        
        if ($stmt->execute($params)) {
            return $stmt;
        }
        
        return false;
    }

    protected function paginate(string $sql, array $params, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        
        // Extract base query for count
        $countSql = preg_replace('/SELECT (.*?) FROM /i', 'SELECT COUNT(*) as total FROM ', $sql);
        $countStmt = $this->query($countSql, $params);
        $total = $countStmt ? (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'] : 0;
        
        $sql .= " LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->query($sql, $params);
        $data = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        
        $lastPage = $total > 0 ? ceil($total / $perPage) : 1;
        
        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int)$lastPage
        ];
    }
}


