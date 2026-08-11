<?php
namespace Services;

use PDO;

class NotificationService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function notify(int $userId, ?int $requestId, string $type, string $title, string $body): void {
        $stmt = $this->db->prepare("
            INSERT INTO notifications (user_id, request_id, type, title, body, is_read, created_at)
            VALUES (:userId, :requestId, :type, :title, :body, 0, NOW())
        ");
        $stmt->execute([
            'userId' => $userId,
            'requestId' => $requestId,
            'type' => $type,
            'title' => $title,
            'body' => $body
        ]);
    }

    public function notifyStatusChange(int $userId, int $requestId, string $serviceName, string $reference, string $newStatus): void {
        $title = "Request Status Updated";
        $body = "Your request {$reference} for {$serviceName} is now: {$newStatus}.";
        $this->notify($userId, $requestId, 'status_change', $title, $body);
    }

    public function notifyResultReady(int $userId, int $requestId, string $serviceName, string $reference): void {
        $title = "Result Ready";
        $body = "The result for your request {$reference} ({$serviceName}) is now ready for download.";
        $this->notify($userId, $requestId, 'result_ready', $title, $body);
    }

    public function notifyInfoRequired(int $userId, int $requestId, string $serviceName, string $reference, string $infoRequest): void {
        $title = "Information Required";
        $body = "We need more information for request {$reference} ({$serviceName}): {$infoRequest}";
        $this->notify($userId, $requestId, 'info_required', $title, $body);
    }

    public function notifyRefund(int $userId, int $requestId, float $amount, string $serviceName): void {
        $title = "Refund Issued";
        $body = "A refund of ₦" . number_format($amount, 2) . " has been issued to your wallet for {$serviceName}.";
        $this->notify($userId, $requestId, 'refund_issued', $title, $body);
    }

    public function getForUser(int $userId, int $page = 1, int $perPage = 20): array {
        $offset = ($page - 1) * $perPage;
        
        $stmt = $this->db->prepare("
            SELECT * FROM notifications 
            WHERE user_id = :userId 
            ORDER BY created_at DESC 
            LIMIT :perPage OFFSET :offset
        ");
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markRead(int $notificationId, int $userId): bool {
        $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :userId");
        $stmt->execute(['id' => $notificationId, 'userId' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function getUnreadCount(int $userId): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = :userId AND is_read = 0");
        $stmt->execute(['userId' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['cnt'] : 0;
    }
}



