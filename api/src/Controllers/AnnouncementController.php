<?php
namespace Controllers;

use Helpers\Response;
use Middleware\AuthMiddleware;
use PDO;

final class AnnouncementController
{
    public function active(): void
    {
        AuthMiddleware::getUserId();
        try {
            $stmt = db()->query("SELECT id, title, body, cta_label, cta_url, published_at FROM announcements WHERE status = 'published' ORDER BY published_at DESC, id DESC LIMIT 1");
            $announcement = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            Response::success(['announcement' => $announcement]);
        } catch (\Throwable) {
            // A missing migration must not block a customer login.
            Response::success(['announcement' => null]);
        }
    }
}
