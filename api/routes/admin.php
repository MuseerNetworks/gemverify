<?php
/**
 * GemVerify API — Admin Routes
 */

use Controllers\Admin\RequestController;
use Controllers\Admin\ResultController;
use Controllers\Admin\RefundController;
use Controllers\Admin\StatsController;
use Controllers\Admin\ApiTransactionController;

// ── First-Admin Setup (PUBLIC — no auth required) ────────────────────────────
addRoute('GET',  '/admin/setup', function () { (new \Controllers\AuthController())->checkSetupRequired(); });
addRoute('POST', '/admin/setup', function () { (new \Controllers\AuthController())->createFirstAdmin(); });

// ── Admin Account Management (super_admin only) ───────────────────────────────
addRoute('GET',   '/admin/admins',                 function ()    { (new \Controllers\Admin\AdminManagementController())->listAdmins(); });
addRoute('POST',  '/admin/admins',                 function ()    { (new \Controllers\Admin\AdminManagementController())->createAdmin(); });
addRoute('PATCH', '/admin/admins/{id}/role',       function ($p)  { (new \Controllers\Admin\AdminManagementController())->updateRole((int)$p['id']); });
addRoute('PATCH', '/admin/admins/{id}/active',     function ($p)  { (new \Controllers\Admin\AdminManagementController())->toggleActive((int)$p['id']); });

// Admin Stats & Services Management
addRoute('GET',   '/admin/stats',                           function () { (new StatsController())->getStats(); });
addRoute('GET',   '/admin/users',                           function () { (new StatsController())->getUsers(); });
addRoute('GET',   '/admin/transactions',                    function () { (new StatsController())->getTransactions(); });
addRoute('GET',   '/admin/services',                        function () { (new StatsController())->getServices(); });
addRoute('POST',  '/admin/services/seed',                   function () { (new StatsController())->seedDatabase(); });
addRoute('PATCH', '/admin/services/{id}',                   function ($p) { 
    \Middleware\AdminMiddleware::requireRole('admin'); 
    (new StatsController())->updateService((int)$p['id']); 
});
addRoute('PATCH', '/admin/services/{id}/pricing/{pid}',     function ($p) { 
    \Middleware\AdminMiddleware::requireRole('admin'); 
    (new StatsController())->updateServicePrice((int)$p['id'], (int)$p['pid']); 
});

// Request Management
addRoute('GET',   '/admin/requests',                        function () { (new RequestController())->getAll(); });
addRoute('GET',   '/admin/requests/{reference}',            function ($p) { (new RequestController())->getDetail($p['reference']); });
addRoute('PATCH', '/admin/requests/{reference}/status',     function ($p) { 
    \Middleware\AdminMiddleware::requireRole('admin'); 
    (new RequestController())->changeStatus($p['reference']); 
});
addRoute('PATCH', '/admin/requests/{reference}/assign',     function ($p) { 
    \Middleware\AdminMiddleware::requireRole('admin'); 
    (new RequestController())->assignAdmin($p['reference']); 
});
addRoute('POST',  '/admin/requests/{reference}/notes',      function ($p) { 
    \Middleware\AdminMiddleware::requireRole('support'); 
    (new RequestController())->addNote($p['reference']); 
});
addRoute('POST',  '/admin/requests/{reference}/info-request', function ($p) { 
    \Middleware\AdminMiddleware::requireRole('admin'); 
    (new RequestController())->requestInfo($p['reference']); 
});
addRoute('POST',  '/admin/requests/{reference}/reject',     function ($p) { 
    \Middleware\AdminMiddleware::requireRole('admin'); 
    (new RequestController())->rejectRequest($p['reference']); 
});

// Result Files Management
addRoute('POST',  '/admin/requests/{reference}/result',     function ($p) { 
    \Middleware\AdminMiddleware::requireRole('admin'); 
    (new ResultController())->uploadResult($p['reference']); 
});
addRoute('GET',   '/admin/requests/{reference}/result',     function ($p) { (new ResultController())->getResult($p['reference']); });
addRoute('GET',   '/admin/results/{id}/download',           function ($p) { (new ResultController())->downloadResultAdmin((int)$p['id']); });
addRoute('GET',   '/admin/requests/{reference}/documents/{doc_id}', function ($p) {
    \Middleware\AdminMiddleware::requireRole('support');
    (new RequestController())->downloadDocument($p['reference'], (int)$p['doc_id']);
});

// Refund Management
addRoute('POST',  '/admin/requests/{reference}/refund',     function ($p) { 
    \Middleware\AdminMiddleware::requireRole('super_admin'); 
    (new RefundController())->processRefund($p['reference']); 
});
addRoute('GET',   '/admin/requests/{reference}/refund',     function ($p) { (new RefundController())->getRefund($p['reference']); });

// ── API Transactions (TechHub service requests) ─────────────────────────────
addRoute('GET',   '/admin/api-transactions',                function ()    { (new ApiTransactionController())->listTransactions(); });
addRoute('GET',   '/admin/api-transactions/stats',          function ()    { (new ApiTransactionController())->getStats(); });
addRoute('GET',   '/admin/api-transactions/{ref}',          function ($p)  { (new ApiTransactionController())->getDetail($p['ref']); });
addRoute('PATCH', '/admin/api-transactions/{ref}/status',   function ($p)  {
    \Middleware\AdminMiddleware::requireRole('admin');
    (new ApiTransactionController())->overrideStatus($p['ref']);
});
addRoute('POST',  '/admin/api-transactions/{ref}/refund-flag', function ($p) {
    \Middleware\AdminMiddleware::requireRole('admin');
    (new ApiTransactionController())->flagForRefund($p['ref']);
});
addRoute('POST',  '/admin/api-transactions/batch-refund',      function () {
    \Middleware\AdminMiddleware::requireRole('super_admin');
    (new ApiTransactionController())->batchRefund();
});

// GemPrint (Admin)
addRoute('GET',    '/admin/gemprint/config',                     function ()    { 
    \Middleware\AdminMiddleware::requireRole('admin'); 
    (new \Controllers\GemPrintController())->getAdminConfig(); 
});
addRoute('PATCH',  '/admin/gemprint/config',                     function ()    { 
    \Middleware\AdminMiddleware::requireRole('admin'); 
    (new \Controllers\GemPrintController())->updateAdminConfig(); 
});
addRoute('POST',   '/admin/gemprint/products',                   function ()    { 
    \Middleware\AdminMiddleware::requireRole('admin'); 
    (new \Controllers\GemPrintController())->addProduct(); 
});
addRoute('PATCH',  '/admin/gemprint/products/{id}',              function ($p)  { 
    \Middleware\AdminMiddleware::requireRole('admin'); 
    (new \Controllers\GemPrintController())->updateProduct($p); 
});
addRoute('DELETE', '/admin/gemprint/products/{id}',              function ($p)  { 
    \Middleware\AdminMiddleware::requireRole('admin'); 
    (new \Controllers\GemPrintController())->deleteProduct($p); 
});
addRoute('PATCH',  '/admin/gemprint/orders/{id}/status',          function ($p)  { 
    \Middleware\AdminMiddleware::requireRole('admin'); 
    (new \Controllers\GemPrintController())->updateOrderStatus($p); 
});
addRoute('PATCH',  '/admin/gemprint/jobs/{id}/status',            function ($p)  {
    \Middleware\AdminMiddleware::requireRole('admin');
    (new \Controllers\GemPrintController())->updateJobStatus($p);
});

// ── Virtual Account — Manual Resolution (super_admin only) ───────────────────
// Use when a user is stuck at "Setting up account..." because KatPay already
// has their details from a previous registration. Paste the account details
// from the KatPay merchant dashboard.
addRoute('PATCH', '/admin/users/{id}/virtual-account', function ($p) {
    (new \Controllers\Admin\WalletAdminController())->manualResolveVirtualAccount((int) $p['id']);
});

// ── Wallet Top-up Orders (Admin) ─────────────────────────────────────────────
addRoute('GET',  '/admin/wallet/topups',                          function ()    { (new \Controllers\Admin\WalletAdminController())->listTopUps(); });
addRoute('GET',  '/admin/wallet/topups/{ref}',                    function ($p)  { (new \Controllers\Admin\WalletAdminController())->getTopUp($p['ref']); });
addRoute('POST', '/admin/wallet/topups/{ref}/credit',             function ($p)  { (new \Controllers\Admin\WalletAdminController())->manualCredit($p['ref']); });

// ── Provider Balances (Admin) ────────────────────────────────────────────────
addRoute('GET',  '/admin/provider-balances',                     function ()    { (new \Controllers\Admin\ProviderBalanceController())->getBalances(); });

// ── Admin Withdrawals (KatPay Payouts) ───────────────────────────────────────
addRoute('GET',  '/admin/banks',                                 function ()    { (new \Controllers\Admin\WithdrawalController())->getBanks(); });
addRoute('GET',  '/admin/withdrawals',                           function ()    { (new \Controllers\Admin\WithdrawalController())->listWithdrawals(); });
addRoute('POST', '/admin/withdrawals',                           function ()    { (new \Controllers\Admin\WithdrawalController())->createWithdrawal(); });

// ── KatPay Webhook (PUBLIC — no auth, signature-verified internally) ──────────
// This route MUST be registered last as a fallback to avoid auth middleware
addRoute('POST', '/payment/callback',                             function ()    { (new \Controllers\TopUpController())->handleCallback(); });

