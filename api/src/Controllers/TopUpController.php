<?php
namespace Controllers;

use Helpers\Response;
use Middleware\AuthMiddleware;
use Services\KatPayService;
use Services\WalletService;
use Services\ReferenceService;
use PDO;
use RuntimeException;

require_once __DIR__ . "/../../config/database.php";

/**
 * TopUpController
 *
 * Handles wallet top-up via KatPay Pay-with-Transfer.
 *
 * Security rules enforced here:
 *  1. Signature verified before ANY business logic (handleCallback)
 *  2. Timestamp freshness checked (replay attack prevention)
 *  3. Idempotency via delivery_id (duplicate callback suppression)
 *  4. Independent verification via KatPay verify API before crediting
 *  5. credited_tx_id guard (double-credit prevention)
 *  6. Partial payments (amount_received < amount) held for admin — NOT auto-credited
 */
class TopUpController {

    // ──────────────────────────────────────────────────────────────────────────
    // USER: Initiate a new wallet top-up
    // POST /user/wallet/topup
    // ──────────────────────────────────────────────────────────────────────────
    public function initiate(): void {
        $userId = AuthMiddleware::getUserId();
        $db     = db();

        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $amount = isset($body['amount']) ? (float) $body['amount'] : 0;

        // Validate amount
        if ($amount < KATPAY_MIN_TOPUP) {
            Response::error('Minimum top-up amount is ₦' . number_format(KATPAY_MIN_TOPUP, 2), 422);
            return;
        }
        if ($amount > 10000000) {
            Response::error('Maximum top-up amount is ₦10,000,000', 422);
            return;
        }

        // Fetch user details for KatPay
        $stmt = $db->prepare("SELECT full_name, email, phone FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            Response::error('User not found', 404);
            return;
        }

        // Generate a unique merchant_reference
        $merchantRef = 'WTU_' . $userId . '_' . time() . '_' . substr(uniqid(), -6);

        // Insert pending record BEFORE calling KatPay (so we have an audit trail even if KatPay fails)
        $stmt = $db->prepare("
            INSERT INTO wallet_topups
              (user_id, merchant_reference, amount, currency, status, created_at, updated_at)
            VALUES
              (?, ?, ?, 'NGN', 'pending', NOW(), NOW())
        ");
        $stmt->execute([$userId, $merchantRef, $amount]);
        $topupId = (int) $db->lastInsertId();

        // Call KatPay API
        try {
            $katpay   = new KatPayService();
            $data     = $katpay->initiateTransferPayment([
                'amount'             => $amount,
                'customer_name'      => $user['full_name'] ?? 'GemVerify User',
                'customer_email'     => $user['email'],
                'customer_phone'     => $user['phone'] ?? '',
                'callback_url'       => KATPAY_CALLBACK_URL,
                'merchant_reference' => $merchantRef,
                'description'        => 'GemVerify Wallet Top-up',
                'metadata'           => [
                    'user_id'  => $userId,
                    'topup_id' => $topupId,
                ],
                'expires_in'         => 30,
            ]);
        } catch (RuntimeException $e) {
            // Mark the pending row as failed so admin can see it
            $db->prepare("UPDATE wallet_topups SET status='failed', admin_note=? WHERE id=?")
               ->execute(['KatPay initiation error: ' . $e->getMessage(), $topupId]);
            Response::error('Payment gateway error. Please try again.', 502);
            return;
        }

        // Update the row with KatPay response data
        $expiresAt = isset($data['expires_at'])
            ? date('Y-m-d H:i:s', strtotime($data['expires_at']))
            : null;

        $stmt = $db->prepare("
            UPDATE wallet_topups SET
              katpay_uuid     = ?,
              checkout_url    = ?,
              payment_account = ?,
              expires_at      = ?,
              updated_at      = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $data['uuid']            ?? null,
            $data['checkout_url']    ?? null,
            json_encode($data['payment_account'] ?? null),
            $expiresAt,
            $topupId,
        ]);

        Response::success([
            'topup_id'        => $topupId,
            'merchant_reference' => $merchantRef,
            'checkout_url'    => $data['checkout_url'],
            'payment_account' => $data['payment_account'] ?? null,
            'amount'          => $amount,
            'expires_at'      => $data['expires_at'] ?? null,
            'message'         => 'Redirecting to payment page...',
        ], 201);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PUBLIC WEBHOOK: KatPay calls this when payment is confirmed
    // POST /payment/callback
    // NOTE: No JWT auth — but signature is verified before any action.
    // ──────────────────────────────────────────────────────────────────────────
    public function handleCallback(): void {
        // Always return JSON
        header('Content-Type: application/json');

        // 1. Read raw body BEFORE any parsing
        $rawBody   = file_get_contents('php://input');
        $signature = $this->getHeader('X-KatPay-Signature');
        $timestamp = $this->getHeader('X-KatPay-Timestamp');
        $deliveryId= $this->getHeader('X-KatPay-Delivery-ID');
        $event     = $this->getHeader('X-KatPay-Event');

        // 2. Validate required headers exist
        if (empty($signature) || empty($timestamp)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing security headers']);
            return;
        }

        // 3. Verify HMAC-SHA256 signature
        $katpay = new KatPayService();
        if (!$katpay->verifyWebhookSignature($rawBody, $signature, $timestamp)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            $this->logCallback('INVALID_SIGNATURE', $rawBody, $deliveryId);
            return;
        }

        // 4. Replay attack check — reject if timestamp older than 5 minutes
        if (!$katpay->isTimestampFresh($timestamp)) {
            http_response_code(401);
            echo json_encode(['error' => 'Timestamp too old']);
            $this->logCallback('REPLAY_DETECTED', $rawBody, $deliveryId);
            return;
        }

        // 5. Parse payload
        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || empty($payload['data'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid payload']);
            return;
        }

        $callbackEvent = $payload['event'] ?? $payload['event_type'] ?? '';
        $data          = $payload['data'];

        // 6. Route by event type
        if ($callbackEvent === 'virtual_account.credit' || $callbackEvent === 'virtual_account.payment_received') {
            // Static virtual account deposit
            $this->handleVirtualAccountCredit($data, $rawBody, $deliveryId, $katpay);
            return;
        }

        if ($callbackEvent !== 'transfer_payment.completed') {
            // Unknown event — acknowledge and ignore gracefully
            http_response_code(200);
            echo json_encode(['received' => true, 'note' => 'Unhandled event type: ' . $callbackEvent]);
            return;
        }


        $merchantRef    = $data['merchant_reference'] ?? '';
        $callbackStatus = $data['status']             ?? '';

        if (empty($merchantRef)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing merchant_reference']);
            return;
        }

        $db = db();

        // 7. Idempotency check — if we already processed this delivery_id, acknowledge silently
        if (!empty($deliveryId)) {
            $stmt = $db->prepare("SELECT id FROM wallet_topups WHERE delivery_id = ?");
            $stmt->execute([$deliveryId]);
            if ($stmt->fetchColumn()) {
                http_response_code(200);
                echo json_encode(['received' => true, 'note' => 'Already processed']);
                return;
            }
        }

        // 8. Find the topup record
        $stmt = $db->prepare("SELECT * FROM wallet_topups WHERE merchant_reference = ? FOR UPDATE");

        // Wrap in transaction for atomicity
        $db->beginTransaction();
        try {
            $stmt->execute([$merchantRef]);
            $topup = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$topup) {
                $db->rollBack();
                http_response_code(200); // Acknowledge — don't retry unknown refs
                echo json_encode(['received' => true, 'note' => 'Unknown reference']);
                $this->logCallback('UNKNOWN_REF:' . $merchantRef, $rawBody, $deliveryId);
                return;
            }

            // 9. Double-credit guard — if already credited, stop
            if (!empty($topup['credited_tx_id'])) {
                $db->rollBack();
                http_response_code(200);
                echo json_encode(['received' => true, 'note' => 'Already credited']);
                return;
            }

            // 10. Status guard — only process if topup is still pending/processing
            if (!in_array($topup['status'], ['pending', 'processing'], true)) {
                $db->rollBack();
                http_response_code(200);
                echo json_encode(['received' => true, 'note' => 'Topup already finalised']);
                return;
            }

            // 11. Independent verification — call KatPay verify API
            try {
                $verifiedData = $katpay->verifyByMerchantRef($merchantRef);
            } catch (RuntimeException $e) {
                $db->rollBack();
                // Return 500 so KatPay will retry
                http_response_code(500);
                echo json_encode(['error' => 'Verification failed — will retry']);
                $this->logCallback('VERIFY_ERROR:' . $e->getMessage(), $rawBody, $deliveryId);
                return;
            }

            $verifiedStatus  = $verifiedData['status']          ?? '';
            $amountRequested = (float) ($topup['amount']);
            $amountReceived  = (float) ($verifiedData['amount_received'] ?? 0);

            // 12. Only credit if KatPay also says completed
            if ($verifiedStatus !== 'completed') {
                // Update status to match KatPay's verified status
                $db->prepare("
                    UPDATE wallet_topups SET
                      status = ?, delivery_id = ?, callback_payload = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$verifiedStatus, $deliveryId ?: null, $rawBody, $topup['id']]);
                $db->commit();
                http_response_code(200);
                echo json_encode(['received' => true, 'note' => 'Payment not completed: ' . $verifiedStatus]);
                return;
            }

            // 13. Partial payment handling (Option C — hold for admin)
            if ($amountReceived < $amountRequested) {
                $db->prepare("
                    UPDATE wallet_topups SET
                      status = 'partial',
                      amount_received = ?,
                      delivery_id = ?,
                      callback_payload = ?,
                      admin_note = ?,
                      updated_at = NOW()
                    WHERE id = ?
                ")->execute([
                    $amountReceived,
                    $deliveryId ?: null,
                    $rawBody,
                    'Partial payment received. Expected ₦' . $amountRequested . ', got ₦' . $amountReceived . '. Awaiting admin review.',
                    $topup['id'],
                ]);
                $db->commit();
                http_response_code(200);
                echo json_encode(['received' => true, 'note' => 'Partial payment — held for admin']);
                // TODO: Send admin notification here
                return;
            }

            // 14. Credit the wallet atomically
            // Credit the exact amount requested (even if overpayment — excess stays with KatPay)
            $creditAmount = $amountRequested;

            $walletService = new WalletService($db);
            $tx = $walletService->creditAtomically(
                (int) $topup['user_id'],
                $creditAmount,
                'Wallet Top-up via KatPay (Ref: ' . $merchantRef . ')',
                null
            );

            // 15. Mark topup as completed
            $db->prepare("
                UPDATE wallet_topups SET
                  status = 'completed',
                  amount_received = ?,
                  delivery_id = ?,
                  callback_payload = ?,
                  credited_tx_id = ?,
                  completed_at = NOW(),
                  updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $amountReceived,
                $deliveryId ?: null,
                $rawBody,
                $tx['id'],
                $topup['id'],
            ]);

            $db->commit();

            http_response_code(200);
            echo json_encode(['received' => true]);

        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->logCallback('EXCEPTION:' . $e->getMessage(), $rawBody, $deliveryId);
            http_response_code(500);
            echo json_encode(['error' => 'Internal error — will retry']);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // USER: Get top-up history
    // GET /user/wallet/topup
    // ──────────────────────────────────────────────────────────────────────────
    public function getHistory(): void {
        $userId = AuthMiddleware::getUserId();
        $db     = db();
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $cStmt = $db->prepare("SELECT COUNT(*) FROM wallet_topups WHERE user_id = ?");
        $cStmt->execute([$userId]);
        $total = (int) $cStmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT
              id, merchant_reference, amount, amount_received, currency,
              status, payment_account, checkout_url, expires_at, completed_at, created_at
            FROM wallet_topups
            WHERE user_id = :uid
            ORDER BY created_at DESC
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode JSON fields and cast numerics
        foreach ($rows as &$row) {
            $row['amount']          = (float) $row['amount'];
            $row['amount_received'] = $row['amount_received'] !== null ? (float) $row['amount_received'] : null;
            $row['payment_account'] = $row['payment_account'] ? json_decode($row['payment_account'], true) : null;
        }
        unset($row);

        Response::success([
            'data'       => $rows,
            'pagination' => [
                'current_page' => $page,
                'per_page'     => $limit,
                'total'        => $total,
                'total_pages'  => (int) ceil($total / $limit),
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // USER: Get status of a single top-up
    // GET /user/wallet/topup/{ref}
    // ──────────────────────────────────────────────────────────────────────────
    public function getStatus(string $ref): void {
        $userId = AuthMiddleware::getUserId();
        $db     = db();

        $stmt = $db->prepare("
            SELECT
              id, merchant_reference, amount, amount_received, currency,
              status, payment_account, checkout_url, expires_at, completed_at, created_at
            FROM wallet_topups
            WHERE merchant_reference = ? AND user_id = ?
        ");
        $stmt->execute([$ref, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            Response::error('Top-up not found', 404);
            return;
        }

        $row['amount']          = (float) $row['amount'];
        $row['amount_received'] = $row['amount_received'] !== null ? (float) $row['amount_received'] : null;
        $row['payment_account'] = $row['payment_account'] ? json_decode($row['payment_account'], true) : null;

        Response::success($row);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE: Handle virtual_account.credit webhook event
    // Called when a user transfers money to their static virtual account
    // ──────────────────────────────────────────────────────────────────────────
    private function handleVirtualAccountCredit(
        array $data,
        string $rawBody,
        string $deliveryId,
        KatPayService $katpay
    ): void {
        $db = db();

        // Extract merchant reference with robust fallbacks (supports documented and undocumented keys)
        $merchantRef = $data['merchant_reference'] 
            ?? ($data['transaction']['reference'] ?? '')
            ?? ($data['virtual_account']['provider_reference'] ?? '');

        // Extract amount credited with robust fallbacks
        $amountCredited = (float) (
            $data['amount_credited'] 
            ?? ($data['amount'] ?? 0)
            ?? ($data['transaction']['order_amount'] ?? 0)
        );


        if (empty($merchantRef) || $amountCredited <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid virtual account credit payload']);
            return;
        }

        // Parse user_id from merchant_reference ("GVU_42" → 42)
        if (!preg_match('/^GVU_(\d+)$/', $merchantRef, $m)) {
            http_response_code(200);
            echo json_encode(['received' => true, 'note' => 'Unknown virtual account reference']);
            return;
        }
        $userId = (int) $m[1];

        // Idempotency check on delivery_id
        if (!empty($deliveryId)) {
            $stmt = $db->prepare("SELECT id FROM wallet_topups WHERE delivery_id = ?");
            $stmt->execute([$deliveryId]);
            if ($stmt->fetchColumn()) {
                http_response_code(200);
                echo json_encode(['received' => true, 'note' => 'Already processed']);
                return;
            }
        }

        // Create a wallet_topups record for audit trail (type: virtual_account_credit)
        $vaRef = 'VAC_' . $userId . '_' . time() . '_' . substr(uniqid(), -6);

        $db->beginTransaction();
        try {
            // Verify user exists
            $uStmt = $db->prepare("SELECT id FROM users WHERE id = ?");
            $uStmt->execute([$userId]);
            if (!$uStmt->fetchColumn()) {
                $db->rollBack();
                http_response_code(200);
                echo json_encode(['received' => true, 'note' => 'User not found']);
                return;
            }

            // Insert topup record for audit
            $db->prepare("
                INSERT INTO wallet_topups
                  (user_id, merchant_reference, amount, amount_received, currency, status,
                   delivery_id, callback_payload, created_at, updated_at)
                VALUES
                  (?, ?, ?, ?, 'NGN', 'processing', ?, ?, NOW(), NOW())
            ")->execute([
                $userId, $vaRef, $amountCredited, $amountCredited,
                $deliveryId ?: null, $rawBody,
            ]);
            $topupId = (int) $db->lastInsertId();

            // Credit the wallet atomically
            $walletService = new \Services\WalletService($db);
            $tx = $walletService->creditAtomically(
                $userId,
                $amountCredited,
                'Virtual Account Deposit (Ref: ' . $merchantRef . ')',
                null
            );

            // Mark as completed
            $db->prepare("
                UPDATE wallet_topups SET
                  status = 'completed', credited_tx_id = ?, completed_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ")->execute([$tx['id'], $topupId]);

            // Record last_credit_at on the virtual account
            $vaService = new \Services\VirtualAccountService($db);
            $vaService->recordCredit($userId);

            $db->commit();

            http_response_code(200);
            echo json_encode(['received' => true]);

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $this->logCallback('VA_CREDIT_EXCEPTION:' . $e->getMessage(), $rawBody, $deliveryId);
            http_response_code(500);
            echo json_encode(['error' => 'Internal error — will retry']);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE: Log a callback event for debugging/audit
    // ──────────────────────────────────────────────────────────────────────────
    private function logCallback(string $note, string $rawBody, string $deliveryId): void {
        try {
            $logDir  = LOG_PATH . '/katpay';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $logFile = $logDir . '/callbacks_' . date('Y-m-d') . '.log';
            $entry   = '[' . date('Y-m-d H:i:s') . '] [' . $note . '] [delivery:' . $deliveryId . '] '
                     . substr($rawBody, 0, 500) . "\n---\n";
            file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Logging must never crash the response
        }
    }

    private function getHeader(string $name): string {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!empty($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, $name) === 0) {
                    return $v;
                }
            }
        }
        return '';
    }
}
