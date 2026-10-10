<?php
declare(strict_types=1);

namespace QuickOtp;

final class Settings
{
    public function __construct(private readonly Database $db, private readonly Secrets $secrets)
    {
    }

    public static function defaults(): array
    {
        return ['default_domain' => 'yourdomain.com', 'default_domains' => ['yourdomain.com'], 'generator_type' => 'vietnamese_name_number', 'default_prefix' => '', 'auto_fill_watch' => true, 'auto_start_watch' => false, 'appearance' => 'dark'];
    }

    public function get(): array
    {
        $row = $this->db->one('SELECT * FROM qotp_app_settings WHERE id=1');
        if ($row === null) {
            return self::defaults();
        }
        return $this->withMailDomains(['default_domain' => $row['default_domain'], 'default_domains' => json_decode($row['default_domains'], true, 8, JSON_THROW_ON_ERROR), 'generator_type' => $row['generator_type'], 'default_prefix' => $row['default_prefix'], 'auto_fill_watch' => (bool) $row['auto_fill_watch'], 'auto_start_watch' => (bool) $row['auto_start_watch'], 'appearance' => $row['appearance']], $this->storedMails());
    }

    public static function validate(array $input): array
    {
        $domain = Validation::domain(Validation::text($input, 'default_domain', 254));
        $domains = $input['default_domains'] ?? [$domain];
        if (!is_array($domains) || !array_is_list($domains) || count($domains) < 1 || count($domains) > 100) {
            throw new HttpError(400, 'Lưu từ 1 đến 100 tên miền mặc định.');
        }
        $domains = array_values(array_unique(array_map(function ($value): string {
            if (!is_string($value)) {
                throw new HttpError(400, 'Tên miền không hợp lệ.');
            }
            return Validation::domain($value);
        }, $domains)));
        if (!in_array($domain, $domains, true)) {
            throw new HttpError(400, 'Domain mặc định phải nằm trong danh sách.');
        }
        $type = Validation::text($input, 'generator_type', 32);
        $appearance = Validation::text($input, 'appearance', 8);
        if (!in_array($type, Validation::GENERATORS, true) || !in_array($appearance, ['light', 'dark', 'system'], true)) {
            throw new HttpError(400, 'Tùy chọn giao diện hoặc username không hợp lệ.');
        }
        $prefix = Generator::clean(Validation::text($input, 'default_prefix', 256));
        if ($type === 'custom_prefix' && $prefix === '') {
            throw new HttpError(400, 'Nhập tiền tố username.');
        }
        foreach (['auto_fill_watch', 'auto_start_watch'] as $key) {
            if (!isset($input[$key]) || !is_bool($input[$key])) {
                throw new HttpError(400, 'Tùy chọn tự động không hợp lệ.');
            }
        }
        return ['default_domain' => $domain, 'default_domains' => $domains, 'generator_type' => $type, 'default_prefix' => $prefix, 'auto_fill_watch' => $input['auto_fill_watch'], 'auto_start_watch' => $input['auto_start_watch'], 'appearance' => $appearance];
    }

    private function atomic(callable $work): mixed
    {
        return $this->db->pdo->inTransaction() ? $work() : $this->db->transaction($work);
    }

    private function lockSettings(): void
    {
        // Serialize account/domain changes, including allocation of account IDs.
        $this->db->one('SELECT id FROM qotp_app_settings WHERE id=1 FOR UPDATE');
    }

    private function withMailDomains(array $settings, array $accounts): array
    {
        $domains = $settings['default_domains'];
        foreach ($accounts as $account) {
            $domain = self::mailDomain($account['username']);
            if ($domain !== '' && !in_array($domain, $domains, true)) {
                if ($domains === ['yourdomain.com'] && $settings['default_domain'] === 'yourdomain.com') {
                    $domains = [];
                    $settings['default_domain'] = $domain;
                }
                $domains[] = $domain;
            }
        }
        $settings['default_domains'] = $domains;
        return $settings;
    }

    private function writeSettings(array $settings): void
    {
        $this->db->query('INSERT INTO qotp_app_settings(id,default_domain,default_domains,generator_type,default_prefix,auto_fill_watch,auto_start_watch,appearance,updated_at) VALUES (1,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE default_domain=VALUES(default_domain),default_domains=VALUES(default_domains),generator_type=VALUES(generator_type),default_prefix=VALUES(default_prefix),auto_fill_watch=VALUES(auto_fill_watch),auto_start_watch=VALUES(auto_start_watch),appearance=VALUES(appearance),updated_at=VALUES(updated_at)', [$settings['default_domain'], json_encode($settings['default_domains'], JSON_THROW_ON_ERROR), $settings['generator_type'], $settings['default_prefix'], (int) $settings['auto_fill_watch'], (int) $settings['auto_start_watch'], $settings['appearance'], now()]);
    }

    public function save(array $input): array
    {
        $settings = self::validate($input);
        return $this->atomic(function () use ($settings): array {
            $this->lockSettings();
            $settings = self::validate($this->withMailDomains($settings, $this->storedMails()));
            $this->writeSettings($settings);
            return $settings;
        });
    }

    public static function emptyMail(): array
    {
        return ['id' => 0, 'provider' => 'yandex', 'host' => 'imap.yandex.com', 'port' => 993, 'username' => '', 'folder' => 'INBOX', 'password_encrypted' => '', 'updated_at' => ''];
    }

    public function storedMails(): array
    {
        return $this->db->query('SELECT * FROM qotp_mail_config ORDER BY id')->fetchAll();
    }

    public function storedMail(?int $id = null): array
    {
        $mail = $id === null
            ? $this->db->one('SELECT * FROM qotp_mail_config ORDER BY id LIMIT 1')
            : $this->db->one('SELECT * FROM qotp_mail_config WHERE id=?', [$id]);
        if ($mail === null && $id !== null) {
            throw new HttpError(404, 'Không tìm thấy tài khoản email. Tải lại danh sách.');
        }
        return $mail ?? self::emptyMail();
    }

    public function mailAccounts(): array
    {
        return array_map(self::mailResponse(...), $this->storedMails());
    }

    public static function mailId(array $input): ?int
    {
        if (!array_key_exists('id', $input)) {
            return null;
        }
        if (!is_int($input['id']) || $input['id'] < 1 || $input['id'] > 255) {
            throw new HttpError(400, 'ID tài khoản email không hợp lệ.');
        }
        return $input['id'];
    }

    public static function mailDomain(string $username): string
    {
        try {
            return explode('@', Validation::email($username), 2)[1];
        } catch (HttpError) {
            // Legacy IMAP logins need not have been email addresses.
            return '';
        }
    }

    public static function mailResponse(array $mail): array
    {
        return ['id' => (int) ($mail['id'] ?? 0), 'domain' => self::mailDomain($mail['username']), 'provider' => $mail['provider'], 'host' => $mail['host'], 'port' => (int) $mail['port'], 'username' => $mail['username'], 'folder' => $mail['folder'], 'has_password' => $mail['password_encrypted'] !== '', 'configured' => $mail['password_encrypted'] !== '', 'encryption' => 'TLS', 'updated_at' => $mail['updated_at'] ?? ''];
    }

    public static function validateMail(array $input): array
    {
        $provider = Validation::text($input, 'provider', 8);
        if (!in_array($provider, ['yandex', 'custom'], true)) {
            throw new HttpError(400, 'Provider không hợp lệ.');
        }
        $host = Validation::domain(Validation::text($input, 'host', 253));
        $port = $input['port'] ?? 993;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new HttpError(400, 'Port IMAP không hợp lệ.');
        }
        $username = trim(Validation::text($input, 'username', 254));
        $folder = trim(Validation::text($input, 'folder', 256));
        $password = Validation::text($input, 'password', 1024);
        if ($username === '' || $folder === '' || preg_match('/[\x00-\x1f\x7f]/', $username . $folder) || preg_match('/[\x00\r\n]/', $password)) {
            throw new HttpError(400, 'Username, folder hoặc App Password không hợp lệ.');
        }
        if (str_contains($username, '@')) {
            $username = Validation::email($username);
        }
        return compact('provider', 'host', 'port', 'username', 'folder', 'password');
    }

    public function mailRequest(array $input, bool $create = false): array
    {
        $id = self::mailId($input);
        if ($create && $id !== null) {
            throw new HttpError(400, 'Không gửi ID khi thêm tài khoản mới.');
        }
        $mail = self::validateMail($input);
        $stored = $create ? self::emptyMail() : $this->storedMail($id);
        $mail['id'] = (int) $stored['id'];
        if ($mail['password'] === '') {
            if ($stored['password_encrypted'] === '') {
                throw new HttpError(400, 'Nhập App Password để kết nối.');
            }
            foreach (['provider', 'host', 'port', 'username'] as $field) {
                if (strtolower((string) $mail[$field]) !== strtolower((string) $stored[$field])) {
                    throw new HttpError(400, 'Nhập lại App Password khi đổi máy chủ hoặc tài khoản.');
                }
            }
            $mail['password'] = $this->secrets->decrypt($stored['password_encrypted']);
        }
        return $mail;
    }

    public function saveMail(array $input, bool $create = false): array
    {
        return $this->atomic(function () use ($input, $create): array {
            $this->lockSettings();
            $mail = $this->mailRequest($input, $create);
            $accounts = $this->storedMails();
            $domain = self::mailDomain($mail['username']);
            if ($mail['id'] === 0 && $domain === '') {
                throw new HttpError(400, 'Nhập địa chỉ mailbox đầy đủ, ví dụ mailbox@example.com.');
            }
            foreach ($accounts as $account) {
                if ((int) $account['id'] === $mail['id']) {
                    continue;
                }
                if (strcasecmp($account['username'], $mail['username']) === 0
                    || ($domain !== '' && self::mailDomain($account['username']) === $domain)) {
                    throw new HttpError(409, 'Domain này đã có mailbox chính. Chọn Sửa ở tài khoản hiện có.');
                }
            }
            if ($mail['id'] === 0) {
                if (count($accounts) >= 100) {
                    throw new HttpError(400, 'Tối đa 100 tài khoản email.');
                }
                $used = array_map(static fn (array $account): int => (int) $account['id'], $accounts);
                for ($id = 1; $id <= 255; $id++) {
                    if (!in_array($id, $used, true)) {
                        $mail['id'] = $id;
                        break;
                    }
                }
            }
            $mail['password_encrypted'] = $this->secrets->encrypt($mail['password']);
            $mail['updated_at'] = now();
            $this->db->query('INSERT INTO qotp_mail_config(id,provider,host,port,username,folder,password_encrypted,updated_at) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE provider=VALUES(provider),host=VALUES(host),port=VALUES(port),username=VALUES(username),folder=VALUES(folder),password_encrypted=VALUES(password_encrypted),updated_at=VALUES(updated_at)', [$mail['id'], $mail['provider'], $mail['host'], $mail['port'], $mail['username'], $mail['folder'], $mail['password_encrypted'], $mail['updated_at']]);
            $settings = self::validate($this->get());
            $this->writeSettings($settings);
            return self::mailResponse($mail) + ['settings' => $settings];
        });
    }

    public function deleteMail(int $id): array
    {
        return $this->atomic(function () use ($id): array {
            $this->lockSettings();
            $this->storedMail($id);
            $this->writeSettings(self::validate($this->get()));
            $this->db->query('DELETE FROM qotp_mail_config WHERE id=?', [$id]);
            // Keep domains and generated-address history until the admin removes them explicitly.
            return ['items' => $this->mailAccounts()];
        });
    }

    public function mailPassword(?int $id = null): array
    {
        $mail = $this->storedMail($id);
        return self::mailResponse($mail) + ['password' => $mail['password_encrypted'] !== '' ? $this->secrets->decrypt($mail['password_encrypted']) : ''];
    }

    public static function selectMail(array $accounts, string $email): ?array
    {
        $domain = explode('@', Validation::email($email), 2)[1];
        foreach ($accounts as $account) {
            if (self::mailDomain($account['username']) === $domain) {
                return $account;
            }
        }
        // Preserve the previous single-mailbox catch-all setup for additional alias domains.
        return count($accounts) === 1 ? $accounts[0] : null;
    }

    public function storedMailForRecipient(string $email): ?array
    {
        return self::selectMail($this->storedMails(), $email);
    }

}

final class Generator
{
    public static function clean(string $value): string
    {
        $value = str_replace(['đ', 'Đ'], 'd', mb_strtolower(trim($value)));
        $value = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return substr((string) preg_replace('/[^a-z0-9]/', '', strtolower($value)), 0, 55);
    }

    public static function local(string $type, string $prefix = ''): string
    {
        if (!in_array($type, Validation::GENERATORS, true)) {
            throw new HttpError(400, 'unsupported username style');
        }
        if ($type === 'random_crypto' || $type === 'random_letters_number') {
            $alphabet = $type === 'random_crypto' ? 'abcdefghijklmnopqrstuvwxyz0123456789' : 'abcdefghijklmnopqrstuvwxyz';
            $value = '';
            for ($i = 0; $i < ($type === 'random_crypto' ? 16 : 7); $i++) {
                $value .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            return $value . ($type === 'random_crypto' ? '' : random_int(10, 99999));
        }
        if ($type === 'custom_prefix') {
            $value = self::clean($prefix);
            if ($value === '') {
                throw new HttpError(400, 'enter a valid prefix');
            }
        } else {
            $names = json_decode(file_get_contents(dirname(__DIR__) . '/names.json'), true, 8, JSON_THROW_ON_ERROR);
            $pair = match ($type) {
                'vietnamese_name_number' => ['vietnameseSurnames', 'vietnameseNames'],
                'usa_name_number' => ['usaGivenNames', 'usaSurnames'],
                'canada_name_number' => ['canadaGivenNames', 'canadaSurnames'],
                default => ['usernameAdjectives', 'usernameNouns'],
            };
            $value = $names[$pair[0]][random_int(0, count($names[$pair[0]]) - 1)] . $names[$pair[1]][random_int(0, count($names[$pair[1]]) - 1)];
        }
        return $value . random_int(10, 99999);
    }
}
