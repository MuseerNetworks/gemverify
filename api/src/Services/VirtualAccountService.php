<?php
namespace Services;

use RuntimeException;
use PDO;

/**
 * VirtualAccountService
 *
 * Handles creation and retrieval of static KatPay virtual bank accounts.
 * Each user gets exactly one virtual account, provisioned automatically at registration.
 * If provisioning fails at registration (e.g. KatPay is down), it is retried on first wallet view.
 */
class VirtualAccountService {

    private \PDO $db;

    public function __construct(\PDO $db) {
        $this->db = $db;
    }

    /**
     * Get the virtual account for a user, creating it if it doesn't exist yet.
     * This is the single entry point — always call this, never call createForUser directly.
     *
     * @param int   $userId
     * @param array $userInfo  { business_name, email, phone }
     * @return array|null  Virtual account row, or null if creation failed
     */
    public function getOrCreate(int $userId, array $userInfo): ?array {
        // Check if already provisioned and active
        $existing = $this->getByUserId($userId);
        if ($existing && $existing['status'] === 'active') {
            return $existing;
        }

        // If pending (creation previously failed/pending), retry
        try {
            return $this->createForUser($userId, $userInfo);
        } catch (RuntimeException $e) {
            // Log failure but don't crash the request
            error_log('[VirtualAccount] Failed to create for user ' . $userId . ': ' . $e->getMessage());
            return $existing; // return the pending row if it exists
        }
    }

    /**
     * Get existing virtual account row for a user (any status).
     */
    public function getByUserId(int $userId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM virtual_accounts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create a KatPay virtual account for a user and store it in the DB.
     * Safe to call multiple times — will update existing pending rows.
     *
     * @throws RuntimeException if KatPay API call fails
     */
    public function createForUser(int $userId, array $userInfo): array {
        $katpay = new KatPayService();
        $merchantRef = 'GVU_' . $userId;

        $data = $katpay->createVirtualAccount([
            'customer_name'      => $userInfo['business_name'] ?? ('GemVerify User #' . $userId),
            'customer_email'     => $userInfo['email'],
            'customer_phone'     => $userInfo['phone'] ?? '',
            'merchant_reference' => $merchantRef,
            'callback_url'       => KATPAY_CALLBACK_URL,
            'metadata'           => [
                'user_id' => $userId,
                'type'    => 'virtual_account',
            ],
        ]);

        // Upsert — insert or update if pending row already exists
        $stmt = $this->db->prepare("
            INSERT INTO virtual_accounts
              (user_id, katpay_va_id, account_number, account_name, bank_name, bank_code, currency, status, raw_response, created_at, updated_at)
            VALUES
              (:uid, :va_id, :acct_no, :acct_name, :bank, :bank_code, 'NGN', 'active', :raw, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
              katpay_va_id   = VALUES(katpay_va_id),
              account_number = VALUES(account_number),
              account_name   = VALUES(account_name),
              bank_name      = VALUES(bank_name),
              bank_code      = VALUES(bank_code),
              status         = 'active',
              raw_response   = VALUES(raw_response),
              updated_at     = NOW()
        ");

        $stmt->execute([
            ':uid'       => $userId,
            ':va_id'     => $data['id']             ?? ($data['uuid'] ?? null),
            ':acct_no'   => $data['account_number'] ?? null,
            ':acct_name' => $data['account_name']   ?? null,
            ':bank'      => $data['bank_name']       ?? null,
            ':bank_code' => $data['bank_code']       ?? null,
            ':raw'       => json_encode($data),
        ]);

        // Return the freshly saved row
        return $this->getByUserId($userId) ?? [
            'user_id'        => $userId,
            'account_number' => $data['account_number'] ?? null,
            'account_name'   => $data['account_name']   ?? null,
            'bank_name'      => $data['bank_name']       ?? null,
            'status'         => 'active',
        ];
    }

    /**
     * Mark a virtual account as having received a credit.
     */
    public function recordCredit(int $userId): void {
        $this->db->prepare(
            "UPDATE virtual_accounts SET last_credit_at = NOW(), updated_at = NOW() WHERE user_id = ?"
        )->execute([$userId]);
    }
}
