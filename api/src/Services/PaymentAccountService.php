<?php
namespace Services;
use PDO;

final class PaymentAccountService
{
    /** @return array<int,array<string,mixed>> */
    public function customerFundingAccounts(PDO $db, int $userId): array {
        $stmt=$db->prepare("SELECT a.id,a.provider_key,p.display_name,a.account_reference,a.account_number,a.account_name,a.bank_name,a.currency,a.status,a.last_credit_at
            FROM payment_virtual_accounts a JOIN payment_providers p ON p.provider_key=a.provider_key
            WHERE a.user_id=? AND a.status='active' AND p.customer_funding_enabled=1 AND p.maintenance_enabled=0
            ORDER BY p.display_order,a.created_at"); $stmt->execute([$userId]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    /** @return array<int,array<string,mixed>> */
    public function allAccountsForUser(PDO $db, int $userId): array {
        $stmt=$db->prepare("SELECT a.id,a.provider_key,p.display_name,a.account_reference,a.account_number,a.account_name,a.bank_name,a.currency,a.status,a.last_credit_at,a.created_at,a.updated_at,p.customer_funding_enabled,p.maintenance_enabled
            FROM payment_virtual_accounts a JOIN payment_providers p ON p.provider_key=a.provider_key WHERE a.user_id=? ORDER BY p.display_order,a.created_at"); $stmt->execute([$userId]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    /** @param array<string,mixed> $a */
    public function upsert(PDO $db, array $a): void {
        $db->prepare("INSERT INTO payment_virtual_accounts (user_id,provider_key,provider_account_id,account_reference,account_number,account_name,bank_name,currency,status,last_credit_at,provider_metadata,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE provider_account_id=VALUES(provider_account_id),account_name=VALUES(account_name),bank_name=VALUES(bank_name),currency=VALUES(currency),status=VALUES(status),last_credit_at=VALUES(last_credit_at),provider_metadata=VALUES(provider_metadata),updated_at=NOW()")
           ->execute([$a['user_id'],$a['provider_key'],$a['provider_account_id']??null,$a['account_reference']??null,$a['account_number'],$a['account_name']??null,$a['bank_name']??null,$a['currency']??'NGN',$a['status']??'active',$a['last_credit_at']??null,json_encode($a['provider_metadata']??null)]);
    }
}
