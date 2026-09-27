<?php
namespace Controllers\Admin;

use Helpers\Response;
use Middleware\AdminMiddleware;
use PDO;

final class AnnouncementAdminController
{
    public function list(): void
    {
        AdminMiddleware::requireRole('super_admin');
        $rows = db()->query("SELECT a.*, ad.name AS created_by_name FROM announcements a LEFT JOIN admins ad ON ad.id = a.created_by ORDER BY a.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        Response::success(['announcements' => $rows]);
    }

    public function create(): void
    {
        AdminMiddleware::requireRole('super_admin');
        $data = $this->input();
        [$title, $body] = $this->validatedContent($data);
        $db = db();
        $db->prepare("INSERT INTO announcements (title, body, status, created_by, created_at, updated_at) VALUES (?, ?, 'draft', ?, NOW(), NOW())")
            ->execute([$title, $body, AdminMiddleware::getAdminId()]);
        Response::success(['id' => (int) $db->lastInsertId()], 'Announcement saved as draft.', 201);
    }

    public function update(int $id): void
    {
        AdminMiddleware::requireRole('super_admin');
        $data = $this->input();
        [$title, $body] = $this->validatedContent($data);
        $stmt = db()->prepare('UPDATE announcements SET title = ?, body = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$title, $body, $id]);
        if ($stmt->rowCount() === 0) { Response::notFound('Announcement not found.'); }
        Response::success([], 'Announcement updated.');
    }

    public function publish(int $id): void
    {
        AdminMiddleware::requireRole('super_admin');
        $db = db();
        $db->beginTransaction();
        try {
            $check = $db->prepare('SELECT id FROM announcements WHERE id = ? FOR UPDATE');
            $check->execute([$id]);
            if (!$check->fetchColumn()) { $db->rollBack(); Response::notFound('Announcement not found.'); }
            $db->exec("UPDATE announcements SET status = 'paused', updated_at = NOW() WHERE status = 'published'");
            $db->prepare("UPDATE announcements SET status = 'published', published_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$id]);
            $db->commit();
            Response::success([], 'Announcement published.');
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function pause(int $id): void
    {
        AdminMiddleware::requireRole('super_admin');
        $stmt = db()->prepare("UPDATE announcements SET status = 'paused', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) { Response::notFound('Announcement not found.'); }
        Response::success([], 'Announcement paused.');
    }

    private function input(): array { return json_decode(file_get_contents('php://input'), true) ?: []; }

    private function validatedContent(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200 || $body === '' || mb_strlen($body) > 4000) {
            Response::error('Title (1-200 characters) and message (1-4000 characters) are required.', 422);
        }
        return [$title, $body];
    }
}
