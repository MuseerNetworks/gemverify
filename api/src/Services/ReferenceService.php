<?php
namespace Services;

use PDO;

class ReferenceService {
    public static function generateRequestReference(PDO $db): string {
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM manual_requests");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cnt = $row ? (int)$row['cnt'] : 0;
        return 'GV-' . date('Y') . '-' . str_pad((string)($cnt + 1), 8, '0', STR_PAD_LEFT);
    }

    public static function generateTransactionReference(): string {
        return 'GVT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
    }

    public static function generateIdempotencyKey(): string {
        return bin2hex(random_bytes(16));
    }
}



