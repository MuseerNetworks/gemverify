<?php

namespace Models;

class ManualRequest extends BaseModel
{
    protected static string $table = 'manual_requests';

    public function generateReference(): string
    {
        $stmt = $this->db->query("SELECT COUNT(*) + 1 as next_id FROM " . static::$table);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $next = $row['next_id'] ?? 1;
        return 'GV-' . date('Y') . '-' . str_pad($next, 8, '0', STR_PAD_LEFT);
    }

    public function createRequest(array $data): int|false
    {
        $data['reference'] = $this->generateReference();
        
        $fields = array_keys($data);
        $placeholders = array_map(fn($f) => ":$f", $fields);
        
        $sql = "INSERT INTO " . static::$table . " (" . implode(', ', $fields) . ", submitted_at, created_at, updated_at) 
                VALUES (" . implode(', ', $placeholders) . ", NOW(), NOW(), NOW())";
        
        $stmt = $this->db->prepare($sql);
        if ($stmt->execute($data)) {
            return (int)$this->db->lastInsertId();
        }
        return false;
    }

    public function findByReference(string $reference): array|false
    {
        $sql = "SELECT r.*, u.name as user_name, u.email as user_email, s.name as service_name, sc.name as category_name 
                FROM " . static::$table . " r 
                LEFT JOIN users u ON r.user_id = u.id 
                LEFT JOIN services s ON r.service_id = s.id 
                LEFT JOIN service_categories sc ON s.category_id = sc.id 
                WHERE r.reference = :reference LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['reference' => $reference]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function findByReferenceForUser(string $reference, int $userId): array|false
    {
        $sql = "SELECT r.*, s.name as service_name, sc.name as category_name 
                FROM " . static::$table . " r 
                LEFT JOIN services s ON r.service_id = s.id 
                LEFT JOIN service_categories sc ON s.category_id = sc.id 
                WHERE r.reference = :reference AND r.user_id = :user_id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['reference' => $reference, 'user_id' => $userId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function getForUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT r.*, s.name as service_name 
                FROM " . static::$table . " r 
                LEFT JOIN services s ON r.service_id = s.id 
                WHERE r.user_id = :user_id 
                ORDER BY r.created_at DESC 
                LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getForAdmin(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $offset = ($page - 1) * $perPage;
        
        $where = [];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = "r.status = :status";
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['service_id'])) {
            $where[] = "r.service_id = :service_id";
            $params['service_id'] = $filters['service_id'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = "sc.id = :category_id";
            $params['category_id'] = $filters['category_id'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = "r.user_id = :user_id";
            $params['user_id'] = $filters['user_id'];
        }
        if (!empty($filters['reference'])) {
            $where[] = "r.reference LIKE :reference";
            $params['reference'] = '%' . $filters['reference'] . '%';
        }
        if (!empty($filters['date_from'])) {
            $where[] = "r.created_at >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = "r.created_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['assigned_admin_id'])) {
            $where[] = "r.assigned_admin_id = :assigned_admin_id";
            $params['assigned_admin_id'] = $filters['assigned_admin_id'];
        }

        $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
        
        $sql = "SELECT r.*, u.name as user_name, s.name as service_name, sc.name as category_name 
                FROM " . static::$table . " r 
                LEFT JOIN users u ON r.user_id = u.id 
                LEFT JOIN services s ON r.service_id = s.id 
                LEFT JOIN service_categories sc ON s.category_id = sc.id 
                $whereClause 
                ORDER BY r.created_at DESC 
                LIMIT :limit OFFSET :offset";
                
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(":$key", $val);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function updateStatus(int $id, string $newStatus, ?string $notes = null): bool
    {
        $sql = "UPDATE " . static::$table . " SET status = :status, admin_notes = :notes, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'status' => $newStatus,
            'notes' => $notes,
            'id' => $id
        ]);
    }

    public function assignAdmin(int $id, int $adminId): bool
    {
        $sql = "UPDATE " . static::$table . " SET assigned_admin_id = :admin_id, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'admin_id' => $adminId,
            'id' => $id
        ]);
    }

    public function setResultFile(int $id, int $resultFileId): bool
    {
        $sql = "UPDATE " . static::$table . " SET result_file_id = :result_id, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'result_id' => $resultFileId,
            'id' => $id
        ]);
    }

    public function markCompleted(int $id, string $notes): bool
    {
        $sql = "UPDATE " . static::$table . " SET status = 'completed', admin_notes = :notes, completed_at = NOW(), updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['notes' => $notes, 'id' => $id]);
    }

    public function markRejected(int $id, string $reason): bool
    {
        $sql = "UPDATE " . static::$table . " SET status = 'rejected', rejection_reason = :reason, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['reason' => $reason, 'id' => $id]);
    }

    public function requestAdditionalInfo(int $id, string $infoRequest): bool
    {
        $sql = "UPDATE " . static::$table . " SET status = 'awaiting_info', additional_info_request = :request, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['request' => $infoRequest, 'id' => $id]);
    }

    public function submitAdditionalInfo(int $id, string $response): bool
    {
        $sql = "UPDATE " . static::$table . " SET status = 'info_received', additional_info_response = :response, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['response' => $response, 'id' => $id]);
    }

    public function getStats(): array
    {
        $sql = "SELECT status, COUNT(*) as count, SUM(price) as revenue FROM " . static::$table . " GROUP BY status WITH ROLLUP";
        $stmt = $this->db->query($sql);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $stats = [
            'by_status' => [],
            'total_requests' => 0,
            'total_revenue' => 0.0
        ];
        
        foreach ($results as $row) {
            if ($row['status'] === null) {
                $stats['total_requests'] = (int)$row['count'];
                $stats['total_revenue'] = (float)$row['revenue'];
            } else {
                $stats['by_status'][$row['status']] = [
                    'count' => (int)$row['count'],
                    'revenue' => (float)$row['revenue']
                ];
            }
        }
        
        return $stats;
    }

    public function getStatsForUser(int $userId): array
    {
        $sql = "SELECT status, COUNT(*) as count FROM " . static::$table . " WHERE user_id = :user_id GROUP BY status";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $stats = [
            'total' => 0,
            'by_status' => []
        ];
        
        foreach ($results as $row) {
            $stats['by_status'][$row['status']] = (int)$row['count'];
            $stats['total'] += (int)$row['count'];
        }
        
        return $stats;
    }
}




