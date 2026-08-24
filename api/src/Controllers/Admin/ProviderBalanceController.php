<?php
namespace Controllers\Admin;

use Helpers\Response;
use Middleware\AdminMiddleware;
use Services\KatPayService;
use Services\TechHubService;
use PDO;

require_once __DIR__ . '/../../../config/database.php';

/**
 * ProviderBalanceController
 *
 * Provides real-time provider balances (KatPay payment gateway & TechHub verification API) to Admin Panel.
 */
class ProviderBalanceController {

    private KatPayService $katpay;
    private TechHubService $techhub;

    public function __construct() {
        $this->katpay  = new KatPayService();
        $this->techhub = new TechHubService();
    }

    /**
     * GET /admin/provider-balances
     * Fetch live balances from configured providers.
     */
    public function getBalances(): void {
        try {
            AdminMiddleware::requireRole('admin');

            $katpayBalance  = 0.0;
            $katpayStatus   = 'Healthy';
            $katpayError    = null;

            // 1. Fetch KatPay Balance
            try {
                $kpData = $this->katpay->getMerchantBalance();
                $katpayBalance = (float) ($kpData['available_balance'] ?? $kpData['balance'] ?? $kpData['amount'] ?? 0);
            } catch (\Throwable $e) {
                $katpayStatus = 'Config Error / Offline';
                $katpayError  = $e->getMessage();
            }

            // 2. Fetch TechHub Balance
            $techhubBalance = 0.0;
            $techhubStatus  = 'Healthy';
            $techhubError   = null;

            try {
                // If TechHubService has getBalance or mock check
                if (method_exists($this->techhub, 'getBalance')) {
                    $thData = $this->techhub->getBalance();
                    $techhubBalance = (float) ($thData['balance'] ?? $thData['amount'] ?? 0);
                } else {
                    $techhubBalance = 0.0;
                }
            } catch (\Throwable $e) {
                $techhubStatus = 'Offline';
                $techhubError  = $e->getMessage();
            }

            $totalBalance = $katpayBalance + $techhubBalance;
            $nowStr = date('Y-m-d H:i:s');

            $providers = [
                [
                    'name'              => 'KatPay Payment Gateway',
                    'category'          => 'Virtual Accounts & Collections',
                    'available_balance' => $katpayBalance,
                    'threshold'         => 10000.0,
                    'status'            => $katpayStatus,
                    'last_sync'         => $nowStr,
                    'error'             => $katpayError
                ],
                [
                    'name'              => 'TechHub Verification API',
                    'category'          => 'Automated Identity Verification',
                    'available_balance' => $techhubBalance,
                    'threshold'         => 5000.0,
                    'status'            => $techhubStatus,
                    'last_sync'         => $nowStr,
                    'error'             => $techhubError
                ]
            ];

            Response::success([
                'total_balance' => $totalBalance,
                'last_sync'     => $nowStr,
                'providers'     => $providers
            ]);

        } catch (\Throwable $e) {
            Response::error('Failed to query provider balances: ' . $e->getMessage(), [], 500);
        }
    }
}
