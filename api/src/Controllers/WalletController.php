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

        // KatPay accounts are legacy records. Never auto-provision one from a
        // wallet page: ZenithPay activation is deliberate and BVN-gated.
        $vaStmt = $db->prepare('SELECT * FROM virtual_accounts WHERE user_id = ?');
        $vaStmt->execute([$userId]);
        $va = $vaStmt->fetch(PDO::FETCH_ASSOC) ?: null;

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

        $funding = (new ZenithPayWalletController())->fundingStatus($userId);
        $fundingAccounts = $funding['accounts'] ?? [];
        if (!empty($fundingAccounts)) {
            $primary = $fundingAccounts[0];
            $virtualAccount = ['account_number'=>$primary['account_number'],'account_name'=>$primary['account_name'],'bank_name'=>$primary['bank_name'],'status'=>$primary['status']];
        }
        if (($funding['zenithpay']['status'] ?? '') === 'active') {
            $virtualAccount = [
                'account_number' => $funding['zenithpay']['account_number'],
                'account_name' => $funding['zenithpay']['account_name'],
                'bank_name' => $funding['zenithpay']['bank_name'],
                'status' => 'active',
            ];
        } elseif (!$funding['katpay_funding_enabled']) {
            $virtualAccount = null;
        }

        Response::success([
            'balance'          => $wallet['balance'],
            'ledger_balance'   => $wallet['ledger_balance'],
            'currency'         => $wallet['currency'],
            'virtual_account'  => $virtualAccount,
            'funding'          => $funding,
            'funding_accounts' => $fundingAccounts,
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



