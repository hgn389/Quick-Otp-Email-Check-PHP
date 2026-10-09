<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('QUICKOTP_RUNTIME')) {
    http_response_code(403);
    exit;
}
if (!defined('QUICKOTP_RUNTIME')) {
    define('QUICKOTP_RUNTIME', true);
}

function quickotpBootstrapUnavailable(string $message, string $code, bool $retry = false): never
{
    http_response_code(503);
    if ($retry) {
        header('Retry-After: 2');
    }
    header('Cache-Control: no-store');
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message, 'code' => $code], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Lock before loading application classes so requests cannot mix two source versions.
if (PHP_SAPI !== 'cli') {
    $storage = __DIR__ . '/storage';
    $lockPath = $storage . '/maintenance.lock';
    if (is_link($storage) || is_link($lockPath)) {
        quickotpBootstrapUnavailable('Thư mục storage hoặc file khóa không được dùng symlink. Kiểm tra cấu trúc bộ cài.', 'invalid_storage_path');
    }
    if (!is_dir($storage)) {
        @mkdir($storage, 0700, true);
    }
    $requestLock = is_dir($storage) ? @fopen($lockPath, 'c+b') : false;
    if ($requestLock === false) {
        quickotpBootstrapUnavailable('PHP không mở/tạo được quickotp-private/storage/maintenance.lock. Kiểm tra chủ sở hữu, quyền ghi và dung lượng đĩa. Nếu vừa cài mới, mở File Manager của website trong CyberPanel và bấm Fix Permissions, rồi tải lại trang.', 'storage_unavailable');
    }
    if (!flock($requestLock, LOCK_SH | LOCK_NB)) {
        quickotpBootstrapUnavailable('Website đang cập nhật. Vui lòng thử lại sau.', 'maintenance_busy', true);
    }
    if (is_file($storage . '/update-pending.json') || is_link($storage . '/update-pending.json')) {
        quickotpBootstrapUnavailable('Lần cập nhật trước chưa hoàn tất. Cần phục hồi mã nguồn theo hướng dẫn trong CYBERPANEL.md trước khi mở lại website.', 'update_recovery_required');
    }
    @chmod($lockPath, 0600);
    $GLOBALS['quickotp_request_lock'] = $requestLock;
}

require_once __DIR__ . '/src/Core.php';
require_once __DIR__ . '/src/Layout.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Settings.php';
require_once __DIR__ . '/src/Imap.php';
require_once __DIR__ . '/src/Backup.php';
require_once __DIR__ . '/src/System.php';
require_once __DIR__ . '/update-recovery.php';
require_once __DIR__ . '/src/Updater.php';
require_once __DIR__ . '/src/App.php';

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    \ZBateson\MailMimeParser\MailMimeParser::addGlobalPhpDiContainerDefinition([
        'maxMimePartDepth' => 16,
        'maxMessagePartCount' => 100,
        'maxHeaderTokenCount' => 4096,
        'maxMessageHeaderTokenCount' => 16000,
    ]);
}

function quickotpPrivateDirectory(): string
{
    $private = realpath(__DIR__);
    if ($private === false || is_link(__DIR__)) {
        throw new \RuntimeException('Invalid private directory');
    }
    \QuickOtp\Layout::publicDirectory($private);
    return $private;
}
