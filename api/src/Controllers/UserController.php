<?php
namespace Controllers;

use Helpers\Response;
use Helpers\Validator;
require_once __DIR__ . "/../../config/database.php";
use Middleware\AuthMiddleware;
use PDO;

class UserController {
    
    private function getJsonInput(): array {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }

    public function getProfile(): void {
        $userId = AuthMiddleware::getUserId();
        
        $db = db();
        $stmt = $db->prepare("
            SELECT u.id, u.business_name, u.email, u.phone, 
                   u.account_name, u.account_number, u.is_active, u.created_at,
                   (u.pin_hash IS NOT NULL) as pin_set,
                   w.balance as wallet_balance,
                   (SELECT COUNT(*) FROM notifications WHERE user_id = u.id AND is_read = 0) as unread_notifications
            FROM users u
            LEFT JOIN wallets w ON u.id = w.user_id
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            Response::success(['message' => 'User not found'], 404);
            return;
        }
        
        $user['wallet_balance'] = (float) $user['wallet_balance'];
        $user['unread_notifications'] = (int) $user['unread_notifications'];
        $user['is_active'] = (bool) $user['is_active'];
        $user['pin_set'] = (bool) ($user['pin_set'] ?? false);
        
        Response::success($user);
    }

    public function updateProfile(): void {
        $userId = AuthMiddleware::getUserId();
        $data = $this->getJsonInput();
        
        $errors = Validator::validate($data, [
            'business_name' => 'required',
            'phone' => 'required'
        ]);

        if (!empty($errors)) {
            Response::success(['errors' => $errors], 422);
            return;
        }
        
        $db = db();
        $stmt = $db->prepare("
            UPDATE users 
            SET business_name = ?, phone = ?, account_name = ?, account_number = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $data['business_name'],
            $data['phone'],
            $data['account_name'] ?? null,
            $data['account_number'] ?? null,
            $userId
        ]);
        
        Response::success(['message' => 'Profile updated successfully']);
    }

    public function getStats(): void {
        $userId = AuthMiddleware::getUserId();
        $db = db();
        
        $stats = [
            'total_requests' => 0,
            'completed' => 0,
            'pending' => 0,
            'under_review' => 0,
            'total_spent' => 0.00
        ];
        
        $stmt = $db->prepare("
            SELECT status, COUNT(*) as count 
            FROM manual_requests 
            WHERE user_id = ? 
            GROUP BY status
        ");
        $stmt->execute([$userId]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stats['total_requests'] += $row['count'];
            if ($row['status'] === 'completed') $stats['completed'] += $row['count'];
            if ($row['status'] === 'submitted' || $row['status'] === 'pending') $stats['pending'] += $row['count'];
            if ($row['status'] === 'under_review') $stats['under_review'] += $row['count'];
        }
        
        $stmtSpent = $db->prepare("
            SELECT SUM(amount) 
            FROM transactions 
            WHERE user_id = ? AND type = 'debit' AND status = 'successful'
        ");
        $stmtSpent->execute([$userId]);
        $stats['total_spent'] = (float) $stmtSpent->fetchColumn();
        
        Response::success($stats);
    }

    public function getNotifications(): void {
        $userId = AuthMiddleware::getUserId();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $db = db();
        
        $cStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
        $cStmt->execute([$userId]);
        $total = (int) $cStmt->fetchColumn();
        
        $stmt = $db->prepare("
            SELECT id, title, message, type, is_read, created_at 
            FROM notifications 
            WHERE user_id = :user_id 
            ORDER BY created_at DESC 
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($notifications as &$n) {
            $n['is_read'] = (bool) $n['is_read'];
        }
        
        Response::success([
            'data' => $notifications,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit)
            ]
        ]);
    }

    public function markNotificationRead(int $id): void {
        $userId = AuthMiddleware::getUserId();
        $db = db();
        
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        
        Response::success(['message' => 'Notification marked as read']);
    }
}



