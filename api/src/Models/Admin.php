<?php

namespace Models;

class Admin extends BaseModel
{
    protected static string $table = 'admins';

    public function findById(int $id): array|false
    {
        return $this->find($id);
    }

    public function findByEmail(string $email): array|false
    {
        return $this->findBy('email', $email);
    }

    public function verifyPassword(int $id, string $password): bool
    {
        $admin = $this->findById($id);
        if (!$admin || empty($admin['password'])) {
            return false;
        }
        
        return password_verify($password, $admin['password']);
    }

    public function updateLastLogin(int $id): bool
    {
        return $this->update($id, ['last_login' => date('Y-m-d H:i:s')]);
    }

    public function isActive(int $id): bool
    {
        $admin = $this->findById($id);
        return $admin && isset($admin['is_active']) && $admin['is_active'] == 1;
    }
}


