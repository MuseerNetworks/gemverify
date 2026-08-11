<?php

namespace Models;

class Notification extends BaseModel
{
    protected static string $table = 'notifications';

    public function createNotification(int $userId, ?int $requestId, string $type, string $title, string $body): int|false
    {
        $sql = "INSERT INTO " . static::$table . " (user_id, request_id, type, title, body, is_read, created_at) 
                VALUES (:user_id, :request_id, :type, :title, :body, 0, NOW())";
        $stmt = $this->db->prepare($sql);
        if ($stmt->execute([
            'user_id' => $userId,
            'request_id' => $requestId,
            'type' => $type,
            'title' => $title,
            'body' => $body
        ])) {
            return (int)$this->db->lastInsertId();
        }
        return false;
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

    public function markRead(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE " . static::$table . " SET is_read = 1, read_at = NOW() WHERE id = :id AND user_id = :user_id");
        return $stmt->execute(['id' => $id, 'user_id' => $userId]);
    }

    public function markAllRead(int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE " . static::$table . " SET is_read = 1, read_at = NOW() WHERE user_id = :user_id AND is_read = 0");
        return $stmt->execute(['user_id' => $userId]);
    }

    public function getUnreadCount(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM " . static::$table . " WHERE user_id = :user_id AND is_read = 0");
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['count'] ?? 0);
    }
}



