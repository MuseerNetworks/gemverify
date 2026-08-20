<?php
/**
 * GemVerify API — User Routes
 */

use Controllers\ServiceController;
use Controllers\WalletController;
use Controllers\UserController;
use Controllers\ManualRequestController;
use Controllers\ApiRequestController;
use Controllers\ApiStatusController;
use Controllers\TopUpController;

// Services catalog & pricing
addRoute('GET', '/services',                      function () { (new ServiceController())->getAll(); });
addRoute('GET', '/services/{slug}',              function ($p) { (new ServiceController())->getBySlug($p['slug']); });
addRoute('GET', '/services/{slug}/price',        function ($p) { (new ServiceController())->getPrice($p['slug']); });

// User Profile & Stats
addRoute('GET',   '/user/profile',               function () { (new UserController())->getProfile(); });
addRoute('PATCH', '/user/profile',               function () { (new UserController())->updateProfile(); });
addRoute('GET',   '/user/stats',                 function () { (new UserController())->getStats(); });
addRoute('GET',   '/user/notifications',         function () { (new UserController())->getNotifications(); });
addRoute('PATCH', '/user/notifications/{id}/read', function ($p) { (new UserController())->markNotificationRead((int)$p['id']); });

// Wallet & Transactions
addRoute('GET', '/user/wallet',                  function () { (new WalletController())->getWallet(); });
addRoute('GET', '/user/transactions',            function () { (new WalletController())->getTransactions(); });

// Manual Requests (User)
addRoute('POST', '/manual/submit',               function () { (new ManualRequestController())->submit(); });
addRoute('POST', '/manual/submit/bulk',          function () { (new ManualRequestController())->submitBulk(); });
addRoute('GET',  '/manual/requests',             function () { (new ManualRequestController())->getRequests(); });
addRoute('GET',  '/manual/requests/{reference}', function ($p) { (new ManualRequestController())->getRequest($p['reference']); });
addRoute('POST', '/manual/requests/{reference}/info', function ($p) { (new ManualRequestController())->submitAdditionalInfo($p['reference']); });
addRoute('GET',  '/manual/requests/{reference}/documents/{doc_id}', function ($p) { (new ManualRequestController())->downloadDocument($p['reference'], (int)$p['doc_id']); });
addRoute('GET',  '/manual/requests/{reference}/result', function ($p) { (new ManualRequestController())->downloadResult($p['reference']); });

// API Services (TechHub-backed — sync PDF + async ticket)
addRoute('POST', '/api-services/submit',                          function ()    { (new ApiRequestController())->submit(); });
addRoute('POST', '/api/api-services/submit',                      function ()    { (new ApiRequestController())->submit(); });
addRoute('GET',  '/api-services/requests',                        function ()    { (new ApiStatusController())->listRequests(); });
addRoute('GET',  '/api-services/requests/{ref}',                  function ($p)  { (new ApiStatusController())->getRequest($p['ref']); });
addRoute('POST', '/api-services/requests/{ref}/poll',             function ($p)  { (new ApiStatusController())->pollStatus($p['ref']); });
addRoute('GET',  '/api-services/requests/{ref}/pdf',              function ($p)  { (new ApiStatusController())->downloadPdf($p['ref']); });

// GemPrint (User)
addRoute('GET',  '/gemprint/config',                              function ()    { (new \Controllers\GemPrintController())->getUserConfig(); });
addRoute('GET',  '/gemprint/history',                             function ()    { (new \Controllers\GemPrintController())->getUserHistory(); });
addRoute('POST', '/gemprint/order',                               function ()    { (new \Controllers\GemPrintController())->placeUserOrder(); });
addRoute('POST', '/gemprint/job',                                 function ()    { (new \Controllers\GemPrintController())->submitUserJob(); });

// Wallet Top-up (KatPay)
addRoute('POST', '/user/wallet/topup',                            function ()    { (new TopUpController())->initiate(); });
addRoute('GET',  '/user/wallet/topup',                            function ()    { (new TopUpController())->getHistory(); });
addRoute('GET',  '/user/wallet/topup/{ref}',                      function ($p)  { (new TopUpController())->getStatus($p['ref']); });

