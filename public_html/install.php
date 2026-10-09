<?php
declare(strict_types=1);

define('QUICKOTP_RUNTIME', true);
$bootstrap = is_file(__DIR__ . '/quickotp-private/bootstrap.php')
    ? __DIR__ . '/quickotp-private/bootstrap.php'
    : dirname(__DIR__) . '/quickotp-private/bootstrap.php';
require_once $bootstrap;
require_once dirname($bootstrap) . '/src/Installer.php';

use QuickOtp\Http;
use QuickOtp\HttpError;
use QuickOtp\Installer;
use QuickOtp\System;

Http::headers();
// Native form posts need a non-opaque Origin; external sites still receive no referrer.
header('Referrer-Policy: same-origin');
$error = '';
try {
    $private = quickotpPrivateDirectory();
} catch (RuntimeException) {
    http_response_code(503);
    exit('Kiểm tra document root của website. File index.php và install.php phải nằm ngay trong thư mục gốc public_html; không giải nén thêm một thư mục lồng bên ngoài.');
}
if (is_file($private . '/config.php')) {
    Http::redirect('/login.html');
}
$checks = Installer::requirements($private);
$sessionDirectory = $private . '/storage/install-sessions';
if (!is_dir($sessionDirectory)) {
    @mkdir($sessionDirectory, 0700, true);
}
if (!is_dir($sessionDirectory) || !is_writable($sessionDirectory)) {
    http_response_code(503);
    exit('Cấp quyền ghi cho thư mục quickotp-private/storage bằng tài khoản chủ website.');
}
session_save_path($sessionDirectory);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('quickotp_setup');
session_set_cookie_params(['lifetime' => 1800, 'path' => '/', 'secure' => Http::secure(), 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (!isset($_SESSION['setup_csrf'], $_SESSION['setup_expires']) || $_SESSION['setup_expires'] < time()) {
    session_regenerate_id(true);
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
    $_SESSION['setup_expires'] = time() + 1800;
}
try {
    Http::method('GET', 'POST');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Http::sameOrigin();
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['setup_csrf'], $_POST['csrf'])) {
            throw new HttpError(403, 'Phiên cài đặt hết hạn. Tải lại trang và thử lại.');
        }
        Installer::install($private, $_POST);
        $_SESSION = [];
        session_destroy();
        setcookie('quickotp_setup', '', ['expires' => 1, 'path' => '/', 'secure' => Http::secure(), 'httponly' => true, 'samesite' => 'Strict']);
        Http::redirect('/login.html?installed=1');
    }
} catch (HttpError $exception) {
    http_response_code($exception->status);
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    http_response_code(500);
    error_log('Quick OTP installation failed: ' . get_class($exception));
    $error = 'Không hoàn tất được cài đặt. Kiểm tra quyền CREATE/INSERT của database user và quyền ghi thư mục riêng.';
}
$ready = !in_array(false, $checks, true);
$legacyToken = file_exists($private . '/install-token.txt') || is_link($private . '/install-token.txt');
$protectionError = '';
try {
    \QuickOtp\Layout::verifyProtection($private);
} catch (Throwable $exception) {
    $ready = false;
    $protectionError = $exception instanceof HttpError ? $exception->getMessage() : 'Không kiểm tra được bảo vệ thư mục riêng. Kiểm tra quyền ghi và rewrite của website.';
}
function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cài đặt Quick OTP Mail PHP</title><link rel="stylesheet" href="/auth.css?v=php-1.0.0-beta_1"><link rel="stylesheet" href="/install.css?v=php-1.0.0-beta_1"></head>
<body><main class="auth-shell install-shell"><div class="auth-brand"><span class="brand-mark">✉</span><span>Quick OTP Mail · PHP</span></div><section class="auth-card install-card"><h1>Cài đặt website</h1><p>Kết nối database MySQL/MariaDB và tạo tài khoản Admin.</p>
<details class="install-requirements" <?= !$ready ? 'open' : '' ?>><summary><?= $ready ? 'Máy chủ đã sẵn sàng cài đặt' : 'Kiểm tra yêu cầu máy chủ' ?></summary><ul class="install-checks">
<?php foreach ($checks as $label => $ok): ?><li class="<?= $ok ? 'check-ok' : 'check-error' ?>"><?= $ok ? '✓' : '✕' ?> <?= esc($label) ?></li><?php endforeach; ?>
</ul></details>
<?php if ($protectionError !== ''): ?><div class="install-error" role="alert"><?= esc($protectionError) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="install-error" role="alert"><?= esc($error) ?></div><?php endif; ?>
<form method="post" action="/install.php" autocomplete="off"><input type="hidden" name="csrf" value="<?= esc($_SESSION['setup_csrf']) ?>">
<?php if ($legacyToken): ?><label for="installToken">Mã cài đặt cũ</label><input id="installToken" name="install_token" type="password" minlength="20" maxlength="256" autocomplete="off" required><?php endif; ?>
<div class="install-grid"><div><label for="dbHost">Database host</label><input id="dbHost" name="db_host" value="localhost" maxlength="253" required></div><div><label for="dbPort">Port</label><input id="dbPort" name="db_port" type="number" value="3306" min="1" max="65535" required></div></div>
<label for="dbName">Tên database</label><input id="dbName" name="db_name" maxlength="64" required><label for="dbUser">Database user</label><input id="dbUser" name="db_user" maxlength="128" required><label for="dbPassword">Database password</label><input id="dbPassword" name="db_password" type="password" autocomplete="new-password" maxlength="1024" required>
<label for="adminPassword">Mật khẩu Admin</label><input id="adminPassword" name="admin_password" type="password" autocomplete="new-password" minlength="10" maxlength="72" required><label for="adminConfirm">Nhập lại mật khẩu Admin</label><input id="adminConfirm" name="admin_password_confirm" type="password" autocomplete="new-password" minlength="10" maxlength="72" required>
<p class="install-note">Tên đăng nhập là <strong>admin</strong>. Database phải được tạo trước trong CyberPanel. Dùng website hoặc subdomain riêng để các đường dẫn hoạt động từ thư mục gốc.</p>
<button id="installButton" type="submit" <?= !$ready ? 'disabled' : '' ?>>Cài đặt Quick OTP Mail</button></form></section><div class="auth-foot">© 2026 Quick OTP Mail · v<?= esc(System::VERSION) ?>-php</div></main></body></html>
