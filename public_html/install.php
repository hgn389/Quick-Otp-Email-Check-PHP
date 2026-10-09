<?php
declare(strict_types=1);

define('QUICKOTP_RUNTIME', true);
$siblingBootstrap = dirname(__DIR__) . '/quickotp-private/bootstrap.php';
$nestedBootstrap = __DIR__ . '/quickotp-private/bootstrap.php';
$siblingAvailable = @is_file($siblingBootstrap);
$nestedAvailable = @is_file($nestedBootstrap);
$duplicatePrivate = $siblingAvailable && $nestedAvailable;
$bootstrap = $siblingAvailable ? $siblingBootstrap : $nestedBootstrap;
if ($duplicatePrivate || (!$siblingAvailable && !$nestedAvailable) || !@is_readable($bootstrap)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-LiteSpeed-Cache-Control: no-cache');
    exit($duplicatePrivate
        ? 'Có hai thư mục quickotp-private. Dừng triển khai và giữ đúng bản chứa config.php cùng storage hiện tại theo hướng dẫn chuyển thư mục trong README.'
        : 'Không đọc được quickotp-private. Giải nén bộ cài vào thư mục gốc website để public_html và quickotp-private nằm ngang hàng; kiểm tra quyền PHP và open_basedir.');
}
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
$values = [];
try {
    $private = quickotpPrivateDirectory();
} catch (RuntimeException) {
    http_response_code(503);
    exit('Kiểm tra document root của website. File index.php và install.php phải nằm ngay trong public_html; thư mục quickotp-private nằm ngang hàng public_html trong thư mục gốc website.');
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
        foreach (['install_password' => 256, 'db_host' => 253, 'db_port' => 5, 'db_name' => 64, 'db_user' => 128, 'db_password' => 1024, 'admin_password' => 72, 'admin_password_confirm' => 72] as $name => $limit) {
            if (is_string($_POST[$name] ?? null) && strlen($_POST[$name]) <= $limit) {
                $values[$name] = $_POST[$name];
            }
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
$passwordConfigured = Installer::installationPassword($private) !== '';
$ready = $ready && $passwordConfigured;
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
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cài đặt Quick OTP Mail PHP</title><link rel="stylesheet" href="/auth.css?v=php-1.0.0-beta-3"><link rel="stylesheet" href="/install.css?v=php-1.0.0-beta-3"></head>
<body><main class="auth-shell install-shell"><div class="auth-brand"><span class="brand-mark">✉</span><span>Quick OTP Mail · PHP</span></div><section class="auth-card install-card"><h1>Cài đặt website</h1><p>Kết nối database MySQL/MariaDB và tạo tài khoản Admin.</p>
<details class="install-requirements" <?= !$ready ? 'open' : '' ?>><summary><?= $ready ? 'Máy chủ đã sẵn sàng cài đặt' : 'Kiểm tra yêu cầu máy chủ' ?></summary><ul class="install-checks">
<?php foreach ($checks as $label => $ok): ?><li class="<?= $ok ? 'check-ok' : 'check-error' ?>"><?= $ok ? '✓' : '✕' ?> <?= esc($label) ?></li><?php endforeach; ?>
</ul></details>
<?php if (!$passwordConfigured): ?><div class="install-error" role="alert">Chưa đặt mật khẩu cài đặt. Trong File Manager, sao chép <code>quickotp-private/install-password.example.php</code> thành <code>install-password.php</code> cùng thư mục, sửa <code>return '';</code> thành mật khẩu riêng tối thiểu 16 ký tự, tối đa 256 byte, rồi tải lại trang. File này không được đưa lên GitHub.</div><?php endif; ?>
<?php if ($protectionError !== ''): ?><div class="install-error" role="alert"><?= esc($protectionError) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="install-error" role="alert"><?= esc($error) ?></div><?php endif; ?>
<form method="post" action="/install.php" autocomplete="off"><input type="hidden" name="csrf" value="<?= esc($_SESSION['setup_csrf']) ?>">
<label for="installPassword">Mật khẩu cài đặt</label><input id="installPassword" name="install_password" type="password" minlength="16" maxlength="256" autocomplete="off" required value="<?= esc($values['install_password'] ?? '') ?>">
<p class="install-note">Quản trị viên đặt mật khẩu này trước trong <code>quickotp-private/install-password.php</code>. Mật khẩu cài đặt khác với mật khẩu Admin bên dưới.</p>
<div class="install-grid"><div><label for="dbHost">Database host</label><input id="dbHost" name="db_host" maxlength="253" required value="<?= esc($values['db_host'] ?? 'localhost') ?>"></div><div><label for="dbPort">Port</label><input id="dbPort" name="db_port" type="number" min="1" max="65535" required value="<?= esc($values['db_port'] ?? '3306') ?>"></div></div>
<label for="dbName">Tên database</label><input id="dbName" name="db_name" maxlength="64" required value="<?= esc($values['db_name'] ?? '') ?>"><label for="dbUser">Database user</label><input id="dbUser" name="db_user" maxlength="128" required value="<?= esc($values['db_user'] ?? '') ?>"><label for="dbPassword">Database password</label><input id="dbPassword" name="db_password" type="password" autocomplete="new-password" maxlength="1024" required value="<?= esc($values['db_password'] ?? '') ?>">
<label for="adminPassword">Mật khẩu Admin</label><input id="adminPassword" name="admin_password" type="password" autocomplete="new-password" minlength="10" maxlength="72" required value="<?= esc($values['admin_password'] ?? '') ?>"><label for="adminConfirm">Nhập lại mật khẩu Admin</label><input id="adminConfirm" name="admin_password_confirm" type="password" autocomplete="new-password" minlength="10" maxlength="72" required value="<?= esc($values['admin_password_confirm'] ?? '') ?>">
<p class="install-note">Tên đăng nhập là <strong>admin</strong>. Database phải được tạo trước trong CyberPanel. Dùng website hoặc subdomain riêng để các đường dẫn hoạt động từ thư mục gốc.</p>
<button id="installButton" type="submit" <?= !$ready ? 'disabled' : '' ?>>Cài đặt Quick OTP Mail</button></form></section><div class="auth-foot">© 2026 Quick OTP Mail · v<?= esc(System::VERSION) ?>-php</div></main></body></html>
