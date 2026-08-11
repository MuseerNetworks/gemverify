<?php
namespace Controllers;

use Helpers\Response;
use Services\WalletService;
use Services\ReferenceService;
use Services\AuditService;
use Services\NotificationService;
use Exception;
use PDO;

class GemPrintController {
    private string $jsonPath;
    private PDO $db;

    public function __construct() {
        $this->jsonPath = STORAGE_BASE_PATH . DIRECTORY_SEPARATOR . 'gemprint_data.json';
        $this->db = db();
    }

    private function loadData(): array {
        if (!file_exists($this->jsonPath)) {
            // Default initial dataset
            $initial = [
                'delivery_fee' => 1500,
                'products' => [
                    ['id' => 'p1', 'name' => 'A4 Paper Ream (80gsm)', 'description' => '500 sheets, bright white multipurpose copier paper.', 'category' => 'Paper', 'price' => 4500, 'stock' => 120, 'sku' => 'GP-PAP-A4'],
                    ['id' => 'p2', 'name' => 'A3 Paper Ream (80gsm)', 'description' => '500 sheets, ideal for posters and large layouts.', 'category' => 'Paper', 'price' => 7200, 'stock' => 60, 'sku' => 'GP-PAP-A3'],
                    ['id' => 'p3', 'name' => 'Photo Paper Glossy A4 (50pk)', 'description' => 'High-gloss finish for vivid photo printing.', 'category' => 'Paper', 'price' => 6000, 'stock' => 40, 'sku' => 'GP-PAP-PHG'],
                    ['id' => 'p4', 'name' => 'Certificate Paper A4 (50pk)', 'description' => 'Textured premium stock for certificates and awards.', 'category' => 'Business Materials', 'price' => 5200, 'stock' => 45, 'sku' => 'GP-BUS-CERT'],
                    ['id' => 'p5', 'name' => 'PVC Blank Cards (100pk)', 'description' => 'Standard CR80 size, compatible with most ID printers.', 'category' => 'Cards & IDs', 'price' => 8500, 'stock' => 200, 'sku' => 'GP-PVC-BLK'],
                    ['id' => 'p6', 'name' => 'PVC ID Card Material (Inkjet, 50pk)', 'description' => 'Inkjet-printable PVC sheets for DIY ID production.', 'category' => 'Cards & IDs', 'price' => 9200, 'stock' => 150, 'sku' => 'GP-PVC-INK'],
                    ['id' => 'p7', 'name' => 'Lamination Pouches A4 (100pk)', 'description' => '250-micron gloss pouches for document protection.', 'category' => 'Cards & IDs', 'price' => 5500, 'stock' => 90, 'sku' => 'GP-LAM-A4'],
                    ['id' => 'p8', 'name' => 'Lamination Pouches ID-size (100pk)', 'description' => 'Card-size pouches for ID and badge lamination.', 'category' => 'Cards & IDs', 'price' => 3400, 'stock' => 180, 'sku' => 'GP-LAM-ID'],
                    ['id' => 'p9', 'name' => 'Printing Ink Black (100ml)', 'description' => 'Pigment-based ink, compatible with major printer brands.', 'category' => 'Ink & Toner', 'price' => 3800, 'stock' => 75, 'sku' => 'GP-INK-BLK'],
                    ['id' => 'p10', 'name' => 'Printing Ink Colour Set (4x60ml)', 'description' => 'CMY + black dye ink set for inkjet printers.', 'category' => 'Ink & Toner', 'price' => 9500, 'stock' => 50, 'sku' => 'GP-INK-CLR'],
                    ['id' => 'p11', 'name' => 'Printer Toner Cartridge', 'description' => 'Standard-yield laser toner cartridge.', 'category' => 'Ink & Toner', 'price' => 18500, 'stock' => 30, 'sku' => 'GP-TON-STD'],
                    ['id' => 'p12', 'name' => 'Spiral Binding Coils (50pk)', 'description' => 'Assorted sizes, PVC plastic coil binding spines.', 'category' => 'Binding', 'price' => 4200, 'stock' => 65, 'sku' => 'GP-BND-SPR'],
                    ['id' => 'p13', 'name' => 'Binding Combs (100pk)', 'description' => 'Plastic comb binding spines, assorted sizes.', 'category' => 'Binding', 'price' => 5000, 'stock' => 55, 'sku' => 'GP-BND-CMB'],
                    ['id' => 'p14', 'name' => 'ID Card Holders (50pk)', 'description' => 'Clear rigid holders with attachment slot.', 'category' => 'Accessories', 'price' => 3200, 'stock' => 300, 'sku' => 'GP-ACC-HLD'],
                    ['id' => 'p15', 'name' => 'Badge Clips (100pk)', 'description' => 'Retractable badge reels with metal clip.', 'category' => 'Accessories', 'price' => 2100, 'stock' => 250, 'sku' => 'GP-ACC-CLP'],
                    ['id' => 'p16', 'name' => 'Receipt Books (10pk)', 'description' => 'Duplicate carbonless receipt books, 50 sets each.', 'category' => 'Business Materials', 'price' => 4600, 'stock' => 80, 'sku' => 'GP-BUS-RCT'],
                    ['id' => 'p17', 'name' => 'Business Card Material 250gsm (100 sheets)', 'description' => 'Premium matte card stock, 10-up per sheet.', 'category' => 'Business Materials', 'price' => 6800, 'stock' => 70, 'sku' => 'GP-BUS-BCM'],
                    ['id' => 'p18', 'name' => 'Printing Accessories Kit', 'description' => 'Trimmer, corner rounder and stapler bundle.', 'category' => 'Accessories', 'price' => 7400, 'stock' => 20, 'sku' => 'GP-ACC-KIT']
                ],
                'orders' => [],
                'print_jobs' => []
            ];
            file_put_contents($this->jsonPath, json_encode($initial, JSON_PRETTY_PRINT));
            return $initial;
        }
        return json_decode(file_get_contents($this->jsonPath), true) ?? [];
    }

    private function saveData(array $data): void {
        file_put_contents($this->jsonPath, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function isServiceActive(): bool {
        $stmt = $this->db->prepare("SELECT is_active FROM services WHERE slug = ?");
        $stmt->execute(['gemprint']);
        $svc = $stmt->fetch(PDO::FETCH_ASSOC);
        return $svc ? ((int)$svc['is_active'] === 1) : false;
    }

    // ── ADMIN ENDPOINTS ──────────────────────────────────────────────────────

    public function getAdminConfig(): void {
        try {
            $data = $this->loadData();
            $data['enabled'] = $this->isServiceActive();
            Response::success($data);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function updateAdminConfig(): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        try {
            $data = $this->loadData();
            if (isset($input['delivery_fee'])) {
                $data['delivery_fee'] = (int)$input['delivery_fee'];
            }
            $this->saveData($data);

            if (isset($input['enabled'])) {
                $active = $input['enabled'] ? 1 : 0;
                $stmt = $this->db->prepare("UPDATE services SET is_active = ? WHERE slug = ?");
                $stmt->execute([$active, 'gemprint']);

                // Audit log
                $adminId = $_SERVER['ADMIN_ID'] ?? 1;
                $stmtAudit = $this->db->prepare("INSERT INTO audit_logs (actor_type, actor_id, action, notes) VALUES ('admin', ?, 'SERVICE_UPDATED', ?)");
                $stmtAudit->execute([$adminId, "GemPrint status toggled: " . ($active ? 'ENABLED' : 'DISABLED')]);
            }

            Response::success(['success' => true, 'message' => 'Configuration updated successfully']);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function addProduct(): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($input['name']) || empty($input['price']) || empty($input['category'])) {
            Response::error('Name, price and category are required.', [], 400);
            return;
        }
        try {
            $data = $this->loadData();
            $id = 'p' . (count($data['products']) + 1) . '_' . uniqid();
            $newProduct = [
                'id' => $id,
                'name' => trim($input['name']),
                'description' => trim($input['description'] ?? ''),
                'category' => trim($input['category']),
                'price' => (float)$input['price'],
                'stock' => (int)($input['stock'] ?? 0),
                'sku' => trim($input['sku'] ?? ('GP-' . strtoupper(substr(uniqid(), -6))))
            ];
            $data['products'][] = $newProduct;
            $this->saveData($data);
            Response::success(['success' => true, 'product' => $newProduct]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function updateProduct(array $params): void {
        $id = $params['id'] ?? null;
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        try {
            $data = $this->loadData();
            $found = false;
            foreach ($data['products'] as &$p) {
                if ($p['id'] === $id) {
                    if (isset($input['name'])) $p['name'] = trim($input['name']);
                    if (isset($input['description'])) $p['description'] = trim($input['description']);
                    if (isset($input['category'])) $p['category'] = trim($input['category']);
                    if (isset($input['price'])) $p['price'] = (float)$input['price'];
                    if (isset($input['stock'])) $p['stock'] = (int)$input['stock'];
                    if (isset($input['sku'])) $p['sku'] = trim($input['sku']);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                Response::notFound('Product not found.');
                return;
            }
            $this->saveData($data);
            Response::success(['success' => true, 'message' => 'Product updated successfully']);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function deleteProduct(array $params): void {
        $id = $params['id'] ?? null;
        try {
            $data = $this->loadData();
            $initialCount = count($data['products']);
            $data['products'] = array_values(array_filter($data['products'], fn($p) => $p['id'] !== $id));
            if (count($data['products']) === $initialCount) {
                Response::notFound('Product not found.');
                return;
            }
            $this->saveData($data);
            Response::success(['success' => true, 'message' => 'Product deleted successfully']);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function updateOrderStatus(array $params): void {
        $id = $params['id'] ?? null;
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $status = $input['status'] ?? null;
        if (!$status) {
            Response::error('Status is required.', [], 400);
            return;
        }
        try {
            $data = $this->loadData();
            $found = false;
            foreach ($data['orders'] as &$o) {
                if ($o['id'] === $id) {
                    $o['status'] = $status;
                    $o['paymentStatus'] = ($status === 'Cancelled' ? 'Refunded' : ($status === 'Pending Payment' ? 'Pending Payment' : 'Paid'));
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                Response::notFound('Order not found.');
                return;
            }
            $this->saveData($data);
            Response::success(['success' => true, 'message' => 'Order status updated successfully']);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function updateJobStatus(array $params): void {
        $id = $params['id'] ?? null;
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $status = $input['status'] ?? null;
        if (!$status) {
            Response::error('Status is required.', [], 400);
            return;
        }
        try {
            $data = $this->loadData();
            $found = false;
            foreach ($data['print_jobs'] as &$j) {
                if ($j['id'] === $id) {
                    $j['status'] = $status;
                    $j['paymentStatus'] = ($status === 'Cancelled' ? 'Refunded' : 'Paid');
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                Response::notFound('Print job not found.');
                return;
            }
            $this->saveData($data);
            Response::success(['success' => true, 'message' => 'Print job status updated successfully']);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    // ── USER ENDPOINTS ────────────────────────────────────────────────────────

    public function getUserConfig(): void {
        try {
            $data = $this->loadData();
            Response::success([
                'delivery_fee' => $data['delivery_fee'],
                'products' => $data['products'],
                'enabled' => $this->isServiceActive()
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function getUserHistory(): void {
        try {
            $userId = \Middleware\AuthMiddleware::getUserId();
            $data = $this->loadData();

            $userOrders = array_values(array_filter($data['orders'], fn($o) => (int)$o['user_id'] === $userId));
            $userJobs = array_values(array_filter($data['print_jobs'], fn($j) => (int)$j['user_id'] === $userId));

            Response::success([
                'orders' => $userOrders,
                'print_jobs' => $userJobs
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 500);
        }
    }

    public function placeUserOrder(): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = \Middleware\AuthMiddleware::getUserId();
        
        $total = (float)($input['total'] ?? 0);
        $cart = $input['cart'] ?? [];
        $shipping = $input['shipping'] ?? [];
        $idempotencyKey = $input['idempotency_key'] ?? ('GP-ORD-IDEM-' . uniqid());

        if ($total <= 0 || empty($cart)) {
            Response::error('Invalid order or empty cart.', [], 400);
            return;
        }

        try {
            $wallet = new WalletService($this->db);
            $desc = "Payment for GemPrint Materials Order: " . count($cart) . " items";

            // Deduct from wallet atomically
            $tx = $wallet->deductAtomically($userId, $total, $desc, null, $idempotencyKey);

            // Save order in JSON file
            $data = $this->loadData();
            $orderId = 'GP-' . str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            
            $newOrder = [
                'id' => $orderId,
                'user_id' => $userId,
                'date' => date('d/m/Y'),
                'items' => $cart,
                'subtotal' => $total - $data['delivery_fee'],
                'delivery' => $data['delivery_fee'],
                'total' => $total,
                'paymentStatus' => 'Paid',
                'status' => 'Payment Confirmed',
                'customer' => $shipping['name'] ?? 'User #' . $userId,
                'method' => $shipping['method'] ?? 'Delivery',
                'shipping' => $shipping
            ];

            $data['orders'][] = $newOrder;
            $this->saveData($data);

            Response::success([
                'success' => true,
                'reference' => $orderId,
                'order' => $newOrder,
                'wallet_balance_after' => $tx['balance_after']
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 400);
        }
    }

    public function submitUserJob(): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = \Middleware\AuthMiddleware::getUserId();

        $price = (float)($input['price'] ?? 0);
        $details = $input['details'] ?? [];
        $idempotencyKey = $input['idempotency_key'] ?? ('GP-JOB-IDEM-' . uniqid());

        if ($price <= 0 || empty($details['service'])) {
            Response::error('Invalid printing service details.', [], 400);
            return;
        }

        try {
            $wallet = new WalletService($this->db);
            $desc = "Payment for GemPrint Printing Job: " . $details['service'];

            // Deduct from wallet atomically
            $tx = $wallet->deductAtomically($userId, $price, $desc, null, $idempotencyKey);

            // Save job in JSON file
            $data = $this->loadData();
            $jobId = 'PJ-' . str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

            $newJob = [
                'id' => $jobId,
                'user_id' => $userId,
                'date' => date('d/m/Y'),
                'service' => $details['service'],
                'size' => $details['size'] ?? 'A4',
                'type' => $details['type'] ?? 'Plain 80gsm',
                'color' => $details['color'] ?? 'Black & White',
                'qty' => (int)($details['qty'] ?? 1),
                'finishing' => $details['finishing'] ?? 'None',
                'delivery' => $details['delivery'] ?? 'Pickup',
                'price' => $price,
                'paymentStatus' => 'Paid',
                'status' => 'Payment Confirmed'
            ];

            $data['print_jobs'][] = $newJob;
            $this->saveData($data);

            Response::success([
                'success' => true,
                'reference' => $jobId,
                'job' => $newJob,
                'wallet_balance_after' => $tx['balance_after']
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), [], 400);
        }
    }
}
