<?php
namespace Controllers\Admin;

use Helpers\Response;
use Middleware\AdminMiddleware;
use PDO;

final class EnrollmentBankController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = db();
    }

    public function list(): void
    {
        AdminMiddleware::requireRole('admin');

        $stmt = $this->db->query("SELECT id, name, is_active, sort_order, created_at, updated_at FROM enrollment_banks ORDER BY sort_order ASC, name ASC");
        $banks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success(['banks' => $banks]);
    }

    public function create(): void
    {
        AdminMiddleware::requireRole('admin');

        $data = $this->input();
        $name = trim((string)($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            Response::error('Bank name is required and cannot exceed 100 characters.', 422);
        }

        // Check uniqueness case-insensitively
        $check = $this->db->prepare("SELECT id FROM enrollment_banks WHERE LOWER(name) = LOWER(?)");
        $check->execute([$name]);
        if ($check->fetchColumn()) {
            Response::error('A bank with this name already exists.', 409);
        }

        $isActive = isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1;

        if (isset($data['sort_order']) && is_numeric($data['sort_order'])) {
            $sortOrder = (int)$data['sort_order'];
        } else {
            $maxOrder = (int)$this->db->query("SELECT COALESCE(MAX(sort_order), 0) FROM enrollment_banks")->fetchColumn();
            $sortOrder = $maxOrder + 1;
        }

        $stmt = $this->db->prepare("INSERT INTO enrollment_banks (name, is_active, sort_order) VALUES (?, ?, ?)");
        $stmt->execute([$name, $isActive, $sortOrder]);

        Response::success(['id' => (int)$this->db->lastInsertId()], 'Bank added successfully.', 201);
    }

    public function update(int $id): void
    {
        AdminMiddleware::requireRole('admin');

        $stmt = $this->db->prepare("SELECT * FROM enrollment_banks WHERE id = ?");
        $stmt->execute([$id]);
        $bank = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bank) {
            Response::notFound('Bank not found.');
        }

        $data = $this->input();
        $fields = [];
        $params = [];

        if (isset($data['name'])) {
            $name = trim((string)$data['name']);
            if ($name === '' || mb_strlen($name) > 100) {
                Response::error('Bank name cannot be empty and cannot exceed 100 characters.', 422);
            }
            // Check uniqueness with other banks
            $check = $this->db->prepare("SELECT id FROM enrollment_banks WHERE LOWER(name) = LOWER(?) AND id != ?");
            $check->execute([$name, $id]);
            if ($check->fetchColumn()) {
                Response::error('Another bank with this name already exists.', 409);
            }
            $fields[] = "name = ?";
            $params[] = $name;
        }

        if (isset($data['is_active'])) {
            $isActive = (int)$data['is_active'] ? 1 : 0;
            if ($isActive === 0 && (int)$bank['is_active'] === 1) {
                // Check if any other active bank exists
                $checkActive = $this->db->prepare("SELECT COUNT(*) FROM enrollment_banks WHERE is_active = 1 AND id != ?");
                $checkActive->execute([$id]);
                if ((int)$checkActive->fetchColumn() === 0) {
                    Response::error('At least one bank must remain active for user enrollment.', 422);
                }
            }
            $fields[] = "is_active = ?";
            $params[] = $isActive;
        }

        if (isset($data['sort_order']) && is_numeric($data['sort_order'])) {
            $fields[] = "sort_order = ?";
            $params[] = (int)$data['sort_order'];
        }

        if (empty($fields)) {
            Response::error('No fields provided to update.', 422);
        }

        $params[] = $id;
        $sql = "UPDATE enrollment_banks SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($params);

        Response::success([], 'Bank updated successfully.');
    }

    public function delete(int $id): void
    {
        AdminMiddleware::requireRole('admin');

        $stmt = $this->db->prepare("SELECT * FROM enrollment_banks WHERE id = ?");
        $stmt->execute([$id]);
        $bank = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bank) {
            Response::notFound('Bank not found.');
        }

        if ((int)$bank['is_active'] === 1) {
            $check = $this->db->prepare("SELECT COUNT(*) FROM enrollment_banks WHERE is_active = 1 AND id != ?");
            $check->execute([$id]);
            if ((int)$check->fetchColumn() === 0) {
                Response::error('Cannot delete the only remaining active bank.', 422);
            }
        }

        $this->db->prepare("DELETE FROM enrollment_banks WHERE id = ?")->execute([$id]);

        Response::success([], 'Bank deleted successfully.');
    }

    private function input(): array
    {
        return json_decode(file_get_contents('php://input'), true) ?: [];
    }
}
