<?php
namespace Controllers;

use Helpers\Response;
require_once __DIR__ . "/../../config/database.php";
use Middleware\AuthMiddleware;
use PDO;

class WalletController {
    
    public function getWallet(): void {
        $userId = AuthMiddleware::getUserId();
        $db = db();

        $stmt = $db->prepare("SELECT balance, currency FROM wallets WHERE user_id = ?");
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {
            Response::success(['message' => 'Wallet not found'], 404);
            return;
        }

        $tStmt = $db->prepare("
            SELECT reference, type, amount, description, status, created_at
            FROM transactions
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $tStmt->execute([$userId]);
        $transactions = $tStmt->fetchAll(PDO::FETCH_ASSOC);

        // Format decimals
        $wallet['balance'] = (float) $wallet['balance'];
        $wallet['ledger_balance'] = $wallet['balance'];

        foreach ($transactions as &$t) {
            $t['amount'] = (float) $t['amount'];
        }

        // ── Virtual Account ────────────────────────────────────────────────
        // Fetch user info for potential retry provisioning
        $userStmt = $db->prepare("SELECT business_name, email, phone FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);

        $vaService = new \Services\VirtualAccountService($db);
        $va = $vaService->getOrCreate($userId, $userInfo ?: []);

        $virtualAccount = null;
        if ($va && $va['status'] === 'active') {
            $virtualAccount = [
                'account_number' => $va['account_number'],
                'account_name'   => $va['account_name'],
                'bank_name'      => $va['bank_name'],
                'status'         => 'active',
            ];
        } elseif ($va) {
            $virtualAccount = ['status' => 'pending', 'message' => 'Your account is being set up. Please check back shortly.'];
        }

        Response::success([
            'balance'          => $wallet['balance'],
            'ledger_balance'   => $wallet['ledger_balance'],
            'currency'         => $wallet['currency'],
            'virtual_account'  => $virtualAccount,
            'recent_transactions' => $transactions,
        ]);
    }

    public function getTransactions(): void {
        $userId = AuthMiddleware::getUserId();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $db = db();
        
        $cStmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ?");
        $cStmt->execute([$userId]);
        $total = (int) $cStmt->fetchColumn();

        $tStmt = $db->prepare("
            SELECT reference, type, amount, description, status, created_at
            FROM transactions
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $tStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $tStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $tStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $tStmt->execute();
        
        $transactions = $tStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($transactions as &$t) {
            $t['amount'] = (float) $t['amount'];
        }
        
        Response::success([
            'data' => $transactions,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit)
            ]
        ]);
    }
}



