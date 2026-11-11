<?php
namespace Controllers;

use Helpers\Response;
use PDO;

final class PublicEnrollmentBankController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = db();
    }

    /**
     * GET /enrollment-banks
     * Public endpoint returning active enrollment banks for user dropdowns.
     */
    public function list(): void
    {
        $stmt = $this->db->query("SELECT name FROM enrollment_banks WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
        $banks = $stmt->fetchAll(PDO::FETCH_COLUMN);

        Response::success(['banks' => $banks]);
    }
}
