<?php
declare(strict_types=1);

namespace QuickOtp;

use DateTimeImmutable;
use DateTimeZone;
use PDOException;

final class App
{
    private readonly Database $db;
    private readonly Auth $auth;
    private readonly Settings $settings;
    private readonly Secrets $secrets;
    private readonly System $system;
    private $maintenance;

    public function __construct(array $config, private readonly string $directory)
    {
        $this->db = new Database($config);
        $key = base64_decode($config['app_key'] ?? '', true);
        $this->secrets = new Secrets($key === false ? '' : $key);
        $this->auth = new Auth($this->db, $config['dummy_password_hash']);
        $this->settings = new Settings($this->db, $this->secrets);
        $storage = $directory . '/storage';
        $this->system = new System($this->db, $storage);
    }

    public function run(string $path): never
    {
        if ($path === '/backup.html' || $path === '/backup.js' || $path === '/api/v1/backups' || str_starts_with($path, '/api/v1/backups/')) {
            throw new HttpError(404, 'Không tìm thấy trang.');
        }
        $this->maintenance = $GLOBALS['quickotp_request_lock'] ?? fopen($this->directory . '/storage/maintenance.lock', 'c+b');
        if ($this->maintenance === false || !flock($this->maintenance, LOCK_SH | LOCK_NB)) {
            header('Retry-After: 2');
            throw new HttpError(503, 'Website đang cập nhật. Vui lòng thử lại.');
        }
        if ($path === '/health/live' || $path === '/health/ready') {
            Http::method('GET');
            $this->db->query('SELECT 1');
            Http::json(['status' => $path === '/health/live' ? 'ok' : 'ready']);
        }
        if ($path === '/api/v1/auth/login') {
            Http::method('POST');
            Http::json($this->auth->login(Http::body()));
        }
        if ($path === '/login.html') {
            Http::method('GET', 'HEAD');
            $this->page('login.html');
        }
        $api = str_starts_with($path, '/api/');
        $session = $this->auth->requireSession($api);
        if ($api) {
            $this->auth->requireCsrf($session);
        }
        if ((bool) $session['must_change_password'] && !str_starts_with($path, '/api/v1/auth/') && $path !== '/change-password.html') {
            if (!$api) {
                Http::redirect('/change-password.html');
            }
            throw new HttpError(403, 'password change required');
        }
        switch ($path) {
            case '/api/v1/auth/session':
                Http::method('GET');
                Http::json(['username' => $session['username'], 'must_change_password' => (bool) $session['must_change_password'], 'csrf_token' => $session['csrf_token']]);
            case '/api/v1/auth/logout':
                Http::method('POST');
                $this->auth->logout($session);
                http_response_code(204);
                exit;
            case '/api/v1/auth/password':
                Http::method('POST');
                Http::json($this->auth->changePassword($session, Http::body()));
            case '/api/v1/settings':
                Http::method('GET', 'PUT');
                Http::json($_SERVER['REQUEST_METHOD'] === 'GET' ? $this->settings->get() : $this->settings->save(Http::body()));
            case '/api/v1/settings/mail':
                Http::method('GET', 'PUT');
                Http::json($_SERVER['REQUEST_METHOD'] === 'GET' ? Settings::mailResponse($this->settings->storedMail()) : $this->settings->saveMail(Http::body()));
            case '/api/v1/settings/mail/accounts':
                Http::method('GET', 'POST', 'DELETE');
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    Http::json(['items' => $this->settings->mailAccounts()]);
                }
                $input = Http::body();
                if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
                    $id = Settings::mailId($input);
                    if ($id === null) {
                        throw new HttpError(400, 'Chọn tài khoản email cần xóa.');
                    }
                    Http::json($this->settings->deleteMail($id));
                }
                Http::json($this->settings->saveMail($input, true), 201);
            case '/api/v1/settings/mail/password':
                Http::method('POST');
                $input = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) === 0 && !isset($_SERVER['HTTP_TRANSFER_ENCODING']) ? [] : Http::body();
                Http::json($this->settings->mailPassword(Settings::mailId($input)));
            case '/api/v1/settings/mail/test':
                Http::method('POST');
                try {
                    $mail = $this->settings->mailRequest(Http::body());
                    Http::json((new Imap($mail))->check());
                } catch (HttpError $error) {
                    throw $error;
                } catch (\Throwable $error) {
                    throw new HttpError(502, $error instanceof \RuntimeException && !$error instanceof PDOException ? $error->getMessage() : 'Không kiểm tra được kết nối IMAP.');
                }
            case '/api/v1/generator/email':
                Http::method('POST');
                Http::json($this->generate(Http::body()));
            case '/api/v1/history/page':
                Http::method('GET');
                Http::json($this->history());
            case '/api/v1/history':
                Http::method('GET');
                Http::json($this->db->query('SELECT email,generator_type,created_at FROM qotp_generated_emails ORDER BY id DESC LIMIT 200')->fetchAll());
            case '/api/v1/watches':
                Http::method('POST', 'DELETE');
                if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
                    Validation::email(Validation::text($_GET, 'email', 254));
                    http_response_code(204);
                    exit;
                }
                Http::json(['email' => Validation::email(Validation::text(Http::body(), 'email', 254)), 'status' => 'waiting', 'source' => 'imap']);
            case '/api/v1/messages/latest':
            case '/api/v1/otp/latest':
                Http::method('GET');
                $email = Validation::email(Validation::text($_GET, 'email', 254));
                [$state, $error] = $this->syncMail($email);
                $message = $this->db->one('SELECT id,recipient,sender,subject,body_text,otp,received_at FROM qotp_messages WHERE recipient=? ORDER BY received_at DESC,id DESC LIMIT 1', [$email]);
                if ($message) {
                    $message['id'] = (int) $message['id'];
                }
                if ($path === '/api/v1/messages/latest') {
                    Http::json(['email' => $email, 'message' => $message, 'mail_connection' => $state, 'mail_error' => $error]);
                }
                Http::json(['email' => $email, 'status' => $state, 'mail_connection' => $state, 'mail_error' => $error, 'otp' => ($message['otp'] ?? '') ?: null, 'source' => 'stored']);
            case '/api/v1/system/status':
                Http::method('GET');
                Http::json($this->system->status());
            case '/api/v1/system/update':
                Http::method('GET');
                Http::json($this->system->updates());
            case '/api/v1/system/update/install':
                Http::method('POST');
                $this->admin($session);
                $input = Http::body();
                $password = Validation::text($input, 'current_password', 72);
                $this->auth->verifyCurrent($session, $password);
                if (($input['confirmation'] ?? '') !== 'UPDATE') {
                    throw new HttpError(400, 'Xác nhận trước khi cập nhật.');
                }
                $updates = $this->system->updates(true);
                if (!$updates['update_available'] || Validation::text($input, 'version', 32) !== $updates['latest']['number']) {
                    throw new HttpError(409, 'Phiên bản đã thay đổi hoặc chưa có bản mới. Kiểm tra cập nhật lại.');
                }
                $updater = new Updater($this->directory);
                Http::json($updater->install($updates['latest'], $this->maintenance, function () use ($password): string {
                    // A logout/password change during download must cancel the installation.
                    $current = $this->auth->requireSession(true);
                    $this->auth->requireCsrf($current);
                    $this->admin($current);
                    $this->auth->verifyCurrent($current, $password);
                    return (new Backup($this->db, $this->secrets, 'v' . System::VERSION . '-php'))->make($password);
                }));
            case '/':
            case '/index.php':
            case '/index.html':
                $this->page('index.html');
            case '/quick-otp.html':
            case '/settings.html':
            case '/system.html':
            case '/change-password.html':
                $this->page(substr($path, 1));
            default:
                throw new HttpError(404, 'Không tìm thấy trang.');
        }
    }

    private function page(string $name): never
    {
        Http::method('GET', 'HEAD');
        header('Content-Type: text/html; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
            readfile($this->directory . '/views/' . $name);
        }
        exit;
    }

    private function admin(array $session): void
    {
        if (strtolower($session['username']) !== 'admin') {
            throw new HttpError(403, 'Chỉ tài khoản Admin được cập nhật website.');
        }
    }

    private function generate(array $input): array
    {
        $domain = Validation::domain(Validation::text($input, 'domain', 254));
        $type = Validation::text($input, 'type', 32, 'vietnamese_name_number');
        $prefix = Validation::text($input, 'prefix', 256);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $local = Generator::local($type, $prefix);
            $email = Validation::email($local . '@' . $domain);
            $createdAt = now();
            try {
                $this->db->query('INSERT INTO qotp_generated_emails(email,local_part,domain,generator_type,created_at) VALUES (?,?,?,?,?)', [$email, $local, $domain, $type, $createdAt]);
                return ['email' => $email, 'watching' => false, 'generated_at' => $createdAt];
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? 0) !== 1062 || $attempt === 4) {
                    throw $error;
                }
            }
        }
        throw new HttpError(500, 'Không tạo được địa chỉ email.');
    }

    private function history(): array
    {
        $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        $pageSize = filter_var($_GET['page_size'] ?? '10', FILTER_VALIDATE_INT);
        if ($page === false || !in_array($pageSize, [10, 20, 50, 100], true)) {
            throw new HttpError(400, 'Chọn trang hợp lệ và 10, 20, 50 hoặc 100 dòng.');
        }
        $start = $_GET['day_start'] ?? gmdate('Y-m-d\T00:00:00\Z');
        $end = $_GET['day_end'] ?? gmdate('Y-m-d\T00:00:00\Z', time() + 86400);
        $start = normalizedTime(Validation::text(['start' => $start], 'start', 64));
        $end = normalizedTime(Validation::text(['end' => $end], 'end', 64));
        $duration = strtotime($end) - strtotime($start);
        if ($duration <= 0 || $duration > 93600) {
            throw new HttpError(400, 'Khoảng ngày không hợp lệ.');
        }
        $total = (int) $this->db->query('SELECT COUNT(*) FROM qotp_generated_emails')->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;
        $items = $this->db->query("SELECT email,generator_type,created_at FROM qotp_generated_emails ORDER BY created_at DESC,id DESC LIMIT $pageSize OFFSET $offset")->fetchAll();
        $today = (int) $this->db->query('SELECT COUNT(*) FROM qotp_generated_emails WHERE created_at>=? AND created_at<?', [$start, $end])->fetchColumn();
        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total, 'total_pages' => $totalPages, 'today_total' => $today];
    }

    private function syncMail(string $email): array
    {
        $stored = $this->settings->storedMailForRecipient($email);
        if ($stored === null || $stored['password_encrypted'] === '') {
            return ['not_configured', 'Chưa có mailbox chính cho domain của địa chỉ này. Thêm tài khoản trong Settings → Email Config.'];
        }
        $accountId = (int) $stored['id'];
        $path = $this->directory . '/storage/imap-' . $accountId . '.lock';
        $lock = fopen($path, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return ['syncing', ''];
        }
        try {
            // Isolate polling locks and bounded caches so different domains do not block each other.
            $cachePath = $this->directory . '/storage/imap-cache-' . $accountId . '.json';
            $key = hash('sha256', json_encode($stored, JSON_THROW_ON_ERROR) . $email);
            if (is_file($cachePath) && filesize($cachePath) < 4096) {
                $cache = json_decode((string) file_get_contents($cachePath), true);
                if (($cache['key'] ?? '') === $key && ($cache['time'] ?? 0) > microtime(true) - 2 && is_array($cache['result'] ?? null)) {
                    return $cache['result'];
                }
            }
            try {
                $mail = Settings::mailResponse($stored);
                $mail['password'] = $this->secrets->decrypt($stored['password_encrypted']);
                $incoming = (new Imap($mail))->latest($email);
                if ($this->settings->storedMailForRecipient($email) !== $stored) {
                    return ['syncing', ''];
                }
                if ($incoming !== null) {
                    $this->db->query('INSERT INTO qotp_messages(source_key,recipient,sender,subject,body_text,otp,received_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE source_key=VALUES(source_key)', [$incoming['source_key'], $incoming['recipient'], $incoming['sender'], $incoming['subject'], $incoming['body_text'], $incoming['otp'], $incoming['received_at']]);
                }
                $result = ['connected', ''];
            } catch (\Throwable $error) {
                $result = ['error', $error instanceof \RuntimeException && !$error instanceof PDOException ? $error->getMessage() : 'Không đồng bộ được thư IMAP.'];
            }
            file_put_contents($cachePath, json_encode(['key' => $key, 'time' => microtime(true), 'result' => $result], JSON_THROW_ON_ERROR), LOCK_EX);
            chmod($cachePath, 0600);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

}
