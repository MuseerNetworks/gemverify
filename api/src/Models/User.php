<?php

namespace Models;

use PDO;

class User extends BaseModel
{
    protected static string $table = 'users';

    public function findById(int $id): array|false
    {
        return $this->find($id);
    }

    public function findByEmail(string $email): array|false
    {
        return $this->findBy('email', $email);
    }

    public function createUser(array $data): int|false
    {
        try {
            $this->db->beginTransaction();
            
            $userId = $this->create($data);
            if (!$userId) {
                $this->db->rollBack();
                return false;
            }
            
            $walletSql = "INSERT INTO wallets (user_id, balance, created_at, updated_at) VALUES (:user_id, 0.00, NOW(), NOW())";
            $this->query($walletSql, ['user_id' => $userId]);
            
            $this->db->commit();
            return $userId;
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function updateUser(int $id, array $data): bool
    {
        return $this->update($id, $data);
    }

    public function updatePin(int $id, string $pinHash): bool
    {
        return $this->update($id, ['pin' => $pinHash]);
    }

    public function verifyPin(int $id, string $pin): bool
    {
        $user = $this->findById($id);
        if (!$user || empty($user['pin'])) {
            return false;
        }
        
        return password_verify($pin, $user['pin']);
    }

    public function verifyPassword(int $id, string $password): bool
    {
        $user = $this->findById($id);
        if (!$user || empty($user['password'])) {
            return false;
        }
        
        return password_verify($password, $user['password']);
    }

    public function softDelete(int $id): bool
    {
        return $this->update($id, ['deleted_at' => date('Y-m-d H:i:s')]);
    }

    public function getWithWallet(int $id): array|false
    {
        $sql = "SELECT u.*, w.balance, w.id as wallet_id 
                FROM " . static::$table . " u 
                LEFT JOIN wallets w ON u.id = w.user_id 
                WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1";
        
        $stmt = $this->query($sql, ['id' => $id]);
        return $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    }
}


