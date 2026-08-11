<?php

namespace Models;

class Wallet extends BaseModel
{
    protected static string $table = 'wallets';

    public function findByUserId(int $userId): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE user_id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function getBalance(int $userId): float
    {
        $wallet = $this->findByUserId($userId);
        return $wallet ? (float)$wallet['balance'] : 0.0;
    }

    public function createWallet(int $userId): bool
    {
        $stmt = $this->db->prepare("INSERT INTO " . static::$table . " (user_id, balance, ledger_balance, created_at, updated_at) VALUES (:user_id, 0.00, 0.00, NOW(), NOW())");
        return $stmt->execute(['user_id' => $userId]);
    }

    public function deductBalance(int $userId, float $amount): bool
    {
        $stmt = $this->db->prepare("UPDATE " . static::$table . " SET balance = balance - :amount, ledger_balance = ledger_balance - :amount, updated_at = NOW() WHERE user_id = :user_id AND balance >= :amount");
        $stmt->execute([
            'amount' => $amount,
            'user_id' => $userId
        ]);
        return $stmt->rowCount() === 1;
    }

    public function addBalance(int $userId, float $amount): bool
    {
        $stmt = $this->db->prepare("UPDATE " . static::$table . " SET balance = balance + :amount, ledger_balance = ledger_balance + :amount, updated_at = NOW() WHERE user_id = :user_id");
        return $stmt->execute([
            'amount' => $amount,
            'user_id' => $userId
        ]);
    }

    public function lockForUpdate(int $userId): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE user_id = :user_id FOR UPDATE");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}



