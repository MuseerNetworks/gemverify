<?php
namespace Services;

use PDO;

class AuditService {
    public const REQUEST_CREATED = 'request_created';
    public const PAYMENT_CONFIRMED = 'payment_confirmed';
    public const STATUS_CHANGED = 'status_changed';
    public const RESULT_UPLOADED = 'result_uploaded';
    public const RESULT_REPLACED = 'result_replaced';
    public const REQUEST_COMPLETED = 'request_completed';
    public const REQUEST_REJECTED = 'request_rejected';
    public const REFUND_ISSUED = 'refund_issued';
    public const INFO_REQUESTED = 'info_requested';
    public const INFO_SUBMITTED = 'info_submitted';
    public const ADMIN_NOTE_ADDED = 'admin_note_added';
    public const ADMIN_ASSIGNED = 'admin_assigned';

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function log(
        string $action, 
        ?int $requestId, 
        string $actorType, 
        ?int $actorId, 
        mixed $oldValue = null, 
        mixed $newValue = null, 
        ?string $notes = null, 
        ?string $ipAddress = null
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO audit_logs (action, request_id, actor_type, actor_id, old_value, new_value, notes, ip_address, created_at)
            VALUES (:action, :requestId, :actorType, :actorId, :oldValue, :newValue, :notes, :ipAddress, NOW())
        ");

        $stmt->execute([
            'action' => $action,
            'requestId' => $requestId,
            'actorType' => $actorType,
            'actorId' => $actorId,
            'oldValue' => $oldValue !== null ? json_encode($oldValue) : null,
            'newValue' => $newValue !== null ? json_encode($newValue) : null,
            'notes' => $notes,
            'ipAddress' => $ipAddress
        ]);
    }
}



