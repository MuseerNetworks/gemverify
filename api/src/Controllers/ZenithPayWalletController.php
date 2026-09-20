<?php
namespace Controllers;

use Helpers\Response;
use Middleware\AuthMiddleware;
use PDO;
use Services\ZenithPayService;
use Services\PaymentAccountService;

final class ZenithPayWalletController
{
    public function activate(): void
    {
        $userId = AuthMiddleware::getUserId();
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $bvn = preg_replace('/\D/', '', (string) ($input['bvn'] ?? ''));
        if (!preg_match('/^\d{11}$/', $bvn)) {
            Response::error('Enter a valid 11-digit BVN.', 422);
            return;
        }

        $db = db();
        if ($this->setting($db, 'zenithpay_activation_enabled', '1') !== '1') {
            Response::error('Bank account activation is temporarily unavailable.', 503);
            return;
        }

        $stmt = $db->prepare('SELECT id, first_name, last_name, business_name, email FROM users WHERE id = ? AND is_active = 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            Response::error('User account was not found.', 404);
            return;
        }
        $firstName = trim((string) ($user['first_name'] ?? ''));
        $lastName = trim((string) ($user['last_name'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            Response::error('Your account name needs review before bank account activation. Please contact support.', 422);
            return;
        }

        $stmt = $db->prepare('SELECT * FROM zenithpay_virtual_accounts WHERE user_id = ? FOR UPDATE');
        $db->beginTransaction();
        try {
            $stmt->execute([$userId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing && $existing['status'] === 'active') {
                $db->commit();
                Response::success(['account' => $this->publicAccount($existing)], 'Bank account is already active.');
                return;
            }
            if ($existing && $existing['status'] === 'unknown') {
                $db->commit();
                Response::success(['account' => $this->publicAccount($existing)], 'Your bank account is being confirmed.');
                return;
            }
            $db->prepare("INSERT INTO zenithpay_virtual_accounts (user_id, customer_email, status, last_error, created_at, updated_at)
                VALUES (?, ?, 'pending', NULL, NOW(), NOW())
                ON DUPLICATE KEY UPDATE customer_email = VALUES(customer_email), status = 'pending', last_error = NULL, updated_at = NOW()")
                ->execute([$userId, $user['email']]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        try {
            $data = (new ZenithPayService())->assignDedicatedAccount(
                $bvn,
                trim($firstName . ' ' . $lastName),
                $firstName,
                $lastName,
                (string) $user['email']
            );
            $reference = (string) ($data['accountReference'] ?? '');
            $number = (string) ($data['accountNumber'] ?? '');
            if ($reference === '' || $number === '') {
                throw new \RuntimeException('ZenithPay did not return an account reference and number.');
            }
            // The provider response can include BVN. Persist only the fields needed
            // to operate and reconcile the account.
            $safeResponse = [
                'response_code' => $data['response_code'] ?? null,
                'accountReference' => $reference,
                'accountName' => $data['accountName'] ?? null,
                'bankName' => $data['bankName'] ?? null,
                'accountNumber' => $number,
                'accountStatus' => $data['accountStatus'] ?? null,
                'createdOn' => $data['createdOn'] ?? null,
            ];
            $db->prepare("UPDATE zenithpay_virtual_accounts SET account_reference=?, account_number=?, account_name=?, bank_name=?, customer_email=?, status='active', provider_response=?, last_error=NULL, updated_at=NOW() WHERE user_id=?")
                ->execute([$reference, $number, $data['accountName'] ?? null, $data['bankName'] ?? null, $data['customerEmail'] ?? $user['email'], json_encode($safeResponse), $userId]);
            (new PaymentAccountService())->upsert($db, [
                'user_id'=>$userId, 'provider_key'=>'zenithpay', 'provider_account_id'=>$reference,
                'account_reference'=>$reference, 'account_number'=>$number, 'account_name'=>$data['accountName'] ?? null,
                'bank_name'=>$data['bankName'] ?? null, 'currency'=>'NGN', 'status'=>'active', 'provider_metadata'=>$safeResponse,
            ]);
            $account = $this->accountForUser($db, $userId);
            Response::success(['account' => $this->publicAccount($account)], 'Your bank account is active.');
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $status = str_contains(strtolower($message), 'network') || str_contains(strtolower($message), 'timed out') ? 'unknown' : 'failed';
            $db->prepare('UPDATE zenithpay_virtual_accounts SET status=?, last_error=?, updated_at=NOW() WHERE user_id=?')
                ->execute([$status, substr($message, 0, 500), $userId]);
            Response::error($status === 'unknown' ? 'Your bank account is being confirmed. Please check back shortly.' : 'We could not activate your bank account. Please try again later.', 502);
        }
    }

    /** @return array<string,mixed> */
    public function fundingStatus(int $userId): array
    {
        $db = db();
        try {
            $account = $this->accountForUser($db, $userId);
        } catch (\Throwable) {
            // Safe rollout behavior while an application deploy is waiting for
            // its phpMyAdmin migration: retain the existing KatPay experience.
            return ['zenithpay' => ['status' => 'activation_required'], 'katpay_funding_enabled' => true];
        }
        $accounts = [];
        try { $accounts = (new PaymentAccountService())->customerFundingAccounts($db, $userId); } catch (\Throwable) {}
        return [
            'zenithpay' => $account ? $this->publicAccount($account) : ['status' => 'activation_required'],
            'katpay_funding_enabled' => $this->setting($db, 'katpay_funding_enabled', '1') === '1',
            'accounts' => $accounts,
        ];
    }

    /** @return array<string,mixed>|null */
    private function accountForUser(PDO $db, int $userId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM zenithpay_virtual_accounts WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @param array<string,mixed> $account @return array<string,mixed> */
    private function publicAccount(array $account): array
    {
        return [
            'status' => $account['status'],
            'account_reference' => $account['account_reference'] ?: null,
            'account_number' => $account['account_number'] ?: null,
            'account_name' => $account['account_name'] ?: null,
            'bank_name' => $account['bank_name'] ?: null,
        ];
    }

    private function setting(PDO $db, string $key, string $default): string
    {
        try {
            $stmt = $db->prepare('SELECT setting_value FROM payment_gateway_settings WHERE setting_key = ?');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value === false ? $default : (string) $value;
        } catch (\Throwable) {
            return $default;
        }
    }
}
