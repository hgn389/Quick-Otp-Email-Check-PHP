<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('QUICKOTP_RUNTIME')) {
    http_response_code(403);
    exit;
}
if (!defined('QUICKOTP_RUNTIME')) {
    define('QUICKOTP_RUNTIME', true);
}

// Lock before loading application classes so requests cannot mix two source versions.
if (PHP_SAPI !== 'cli') {
    $storage = __DIR__ . '/storage';
    if (!is_dir($storage)) {
        @mkdir($storage, 0700, true);
    }
    $requestLock = is_link($storage) || is_link($storage . '/maintenance.lock') ? false : @fopen($storage . '/maintenance.lock', 'c+b');
    if ($requestLock === false || !flock($requestLock, LOCK_SH | LOCK_NB) || is_file($storage . '/update-pending.json')) {
        http_response_code(503);
        header('Retry-After: 2');
        header('Cache-Control: no-store');
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Website đang bảo trì/cập nhật. Vui lòng thử lại sau.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    @chmod($storage . '/maintenance.lock', 0600);
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
