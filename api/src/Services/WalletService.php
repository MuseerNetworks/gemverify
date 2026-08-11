<?php
namespace Services;

use PDO;
use RuntimeException;
use PDOException;

class InsufficientBalanceException extends RuntimeException {}
class DuplicateTransactionException extends RuntimeException {
    public array $existing;
    public function __construct(string $message, array $existing) {
        parent::__construct($message);
        $this->existing = $existing;
    }
}

class WalletService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getBalance(int $userId): float {
        $stmt = $this->db->prepare("SELECT balance FROM wallets WHERE user_id = :userId");
        $stmt->execute(['userId' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (float)$row['balance'] : 0.0;
    }

    public function hasEnoughBalance(int $userId, float $amount): bool {
        return $this->getBalance($userId) >= $amount;
    }

    public function deductAtomically(int $userId, float $amount, string $description, ?int $requestId, string $idempotencyKey): array {
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE idempotency_key = :idKey LIMIT 1");
        $stmt->execute(['idKey' => $idempotencyKey]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return $existing;
        }

        try {
            $inOuterTx = $this->db->inTransaction();
            if (!$inOuterTx) {
                $this->db->beginTransaction();
            }

            $stmt = $this->db->prepare("SELECT balance FROM wallets WHERE user_id = :userId FOR UPDATE");
            $stmt->execute(['userId' => $userId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$wallet) {
                $this->db->rollBack();
                throw new RuntimeException("Wallet not found for user ID: $userId");
            }

            if ((float)$wallet['balance'] < $amount) {
                $this->db->rollBack();
                throw new InsufficientBalanceException("Insufficient balance.");
            }

            $stmt = $this->db->prepare("UPDATE wallets SET balance = balance - :amount WHERE user_id = :userId AND balance >= :minAmount");
            $stmt->execute([
                'amount' => $amount,
                'userId' => $userId,
                'minAmount' => $amount
            ]);

            if ($stmt->rowCount() !== 1) {
                $this->db->rollBack();
                throw new RuntimeException("Failed to update wallet balance.");
            }

            $txRef = ReferenceService::generateTransactionReference();
            $balanceBefore = (float)$wallet['balance'];
            $balanceAfter = $balanceBefore - $amount;

            $stmt = $this->db->prepare("
                INSERT INTO transactions (user_id, related_request_id, reference, type, amount, balance_before, balance_after, description, status, idempotency_key, created_at)
                VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, 'completed', ?, NOW())
            ");
            $stmt->execute([
                $userId,
                $requestId,
                $txRef,
                $amount,
                $balanceBefore,
                $balanceAfter,
                $description,
                $idempotencyKey
            ]);

            $txId = $this->db->lastInsertId();
            if (!$inOuterTx) {
                $this->db->commit();
            }

            return [
                'id' => (int)$txId,
                'user_id' => $userId,
                'request_id' => $requestId,
                'reference' => $txRef,
                'type' => 'debit',
                'amount' => $amount,
                'description' => $description,
                'idempotency_key' => $idempotencyKey
            ];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new RuntimeException("Database error during deduction: " . $e->getMessage());
        }
    }

    public function creditAtomically(int $userId, float $amount, string $description, ?int $requestId): array {
        try {
            $inOuterTx = $this->db->inTransaction();
            if (!$inOuterTx) {
                $this->db->beginTransaction();
            }

            $balStmt = $this->db->prepare("SELECT balance FROM wallets WHERE user_id = :userId");
            $balStmt->execute(['userId' => $userId]);
            $balanceBefore = (float)$balStmt->fetchColumn();
            $balanceAfter = $balanceBefore + $amount;

            $stmt = $this->db->prepare("UPDATE wallets SET balance = balance + :amount WHERE user_id = :userId");
            $stmt->execute([
                'amount' => $amount,
                'userId' => $userId
            ]);

            $txRef = ReferenceService::generateTransactionReference();
            $stmt = $this->db->prepare("
                INSERT INTO transactions (user_id, related_request_id, reference, type, amount, balance_before, balance_after, description, status, created_at)
                VALUES (:userId, :requestId, :ref, 'credit', :amount, :bBefore, :bAfter, :description, 'completed', NOW())
            ");
            $stmt->execute([
                'userId' => $userId,
                'requestId' => $requestId,
                'ref' => $txRef,
                'amount' => $amount,
                'bBefore' => $balanceBefore,
                'bAfter' => $balanceAfter,
                'description' => $description
            ]);

            $txId = $this->db->lastInsertId();
            if (!$inOuterTx) {
                $this->db->commit();
            }

            return [
                'id' => (int)$txId,
                'user_id' => $userId,
                'request_id' => $requestId,
                'reference' => $txRef,
                'type' => 'credit',
                'amount' => $amount,
                'description' => $description
            ];
        } catch (PDOException $e) {
            if (!$inOuterTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new RuntimeException("Database error during credit: " . $e->getMessage());
        }
    }
}



