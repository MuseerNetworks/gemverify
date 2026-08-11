<?php

namespace Models;

class Transaction extends BaseModel
{
    protected static string $table = 'transactions';

    public function generateReference(): string
    {
        return 'GVT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    public function createDebit(int $userId, float $amount, float $balanceBefore, float $balanceAfter, string $description, ?int $requestId, string $idempotencyKey): int|false
    {
        $stmt = $this->db->prepare("INSERT INTO " . static::$table . " 
            (user_id, type, amount, balance_before, balance_after, description, reference, idempotency_key, request_id, created_at) 
            VALUES (:user_id, 'debit', :amount, :balance_before, :balance_after, :description, :reference, :idempotency_key, :request_id, NOW())");
        
        $success = $stmt->execute([
            'user_id' => $userId,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'description' => $description,
            'reference' => $this->generateReference(),
            'idempotency_key' => $idempotencyKey,
            'request_id' => $requestId
        ]);

        return $success ? (int)$this->db->lastInsertId() : false;
    }

    public function createCredit(int $userId, float $amount, float $balanceBefore, float $balanceAfter, string $description, ?int $requestId): int|false
    {
        $stmt = $this->db->prepare("INSERT INTO " . static::$table . " 
            (user_id, type, amount, balance_before, balance_after, description, reference, request_id, created_at) 
            VALUES (:user_id, 'credit', :amount, :balance_before, :balance_after, :description, :reference, :request_id, NOW())");
        
        $success = $stmt->execute([
            'user_id' => $userId,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'description' => $description,
            'reference' => $this->generateReference(),
            'request_id' => $requestId
        ]);

        return $success ? (int)$this->db->lastInsertId() : false;
    }

    public function findByIdempotencyKey(string $key): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE idempotency_key = :key LIMIT 1");
        $stmt->execute(['key' => $key]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function getForUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function updateRelatedRequest(int $transactionId, int $requestId): bool
    {
        $stmt = $this->db->prepare("UPDATE " . static::$table . " SET request_id = :request_id WHERE id = :id");
        return $stmt->execute(['request_id' => $requestId, 'id' => $transactionId]);
    }
}



