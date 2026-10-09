<?php
declare(strict_types=1);

define('QUICKOTP_RUNTIME', true);
$bootstrap = is_file(__DIR__ . '/quickotp-private/bootstrap.php')
    ? __DIR__ . '/quickotp-private/bootstrap.php'
    : dirname(__DIR__) . '/quickotp-private/bootstrap.php';
require_once $bootstrap;

\QuickOtp\Http::headers();
try {
    $private = quickotpPrivateDirectory();
    if (!is_file($private . '/config.php')) {
        if (str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/api/')) {
            throw new \QuickOtp\HttpError(503, 'Chưa cài đặt. Mở /install.php để bắt đầu.');
        }
        \QuickOtp\Http::redirect('/install.php');
    }
    $config = require $private . '/config.php';
    if (!is_array($config)) {
        throw new \RuntimeException('Invalid configuration');
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!is_string($path) || str_contains($path, "\0")) {
        throw new \QuickOtp\HttpError(400, 'Đường dẫn không hợp lệ.');
    }
    $decodedPath = rawurldecode($path);
    if (str_contains($decodedPath, '..') || preg_match('~^/(?:\.|quickotp-private(?:/|$)|vendor(?:/|$)|storage(?:/|$))~', $decodedPath)) {
        throw new \QuickOtp\HttpError(403, 'Không được truy cập thư mục riêng.');
    }
    (new \QuickOtp\App($config, $private))->run($path);
} catch (\QuickOtp\HttpError $error) {
    \QuickOtp\Http::json(['error' => $error->getMessage()], $error->status);
} catch (\Throwable $error) {
    // Keep connection details, configuration and credentials out of responses/logs.
    error_log('Quick OTP request failed: ' . get_class($error));
    \QuickOtp\Http::json(['error' => 'Không xử lý được yêu cầu. Kiểm tra kết nối database và quyền thư mục quickotp-private/storage.'], 500);
}
