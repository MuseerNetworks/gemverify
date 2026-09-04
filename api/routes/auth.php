<?php
/**
 * GemVerify API — Auth Routes
 */

use Controllers\AuthController;

// User auth
addRoute('POST', '/auth/register',     function () { \Middleware\RateLimitMiddleware::enforce('register', 3, 60); (new AuthController())->register(); });
addRoute('POST', '/auth/login',        function () { \Middleware\RateLimitMiddleware::enforce('login', 5, 60); (new AuthController())->login(); });
addRoute('POST', '/auth/logout',       function () { (new AuthController())->logout(); });
addRoute('POST', '/auth/heartbeat',    function () { (new AuthController())->heartbeat(); });
addRoute('POST', '/auth/refresh',      function () { (new AuthController())->refreshToken(); });
addRoute('GET',  '/auth/me',           function () { (new AuthController())->me(); });
addRoute('POST', '/auth/set-pin',      function () { (new AuthController())->setPin(); });
addRoute('POST', '/auth/change-pin',   function () { (new AuthController())->changePin(); });
addRoute('POST', '/auth/change-password', function () { (new AuthController())->changePassword(); });
addRoute('POST', '/auth/forgot-password', function () { \Middleware\RateLimitMiddleware::enforce('forgot-password', 3, 600); (new AuthController())->forgotPassword(); });
addRoute('POST', '/auth/reset-password',  function () { \Middleware\RateLimitMiddleware::enforce('reset-password', 5, 600); (new AuthController())->resetPassword(); });

// Admin auth
addRoute('POST', '/admin/auth/login',  function () { \Middleware\RateLimitMiddleware::enforce('admin-login', 5, 60); (new AuthController())->adminLogin(); });
