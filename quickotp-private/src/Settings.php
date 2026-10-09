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
        return ['default_domain' => 'yourdomain.com', 'default_domains' => ['yourdomain.com'], 'generator_type' => 'vietnamese_name_number', 'default_prefix' => '', 'auto_fill_watch' => true, 'auto_start_watch' => false, 'appearance' => 'system'];
    }

    public function get(): array
    {
        $row = $this->db->one('SELECT * FROM qotp_app_settings WHERE id=1');
        if ($row === null) {
            return self::defaults();
        }
        return ['default_domain' => $row['default_domain'], 'default_domains' => json_decode($row['default_domains'], true, 8, JSON_THROW_ON_ERROR), 'generator_type' => $row['generator_type'], 'default_prefix' => $row['default_prefix'], 'auto_fill_watch' => (bool) $row['auto_fill_watch'], 'auto_start_watch' => (bool) $row['auto_start_watch'], 'appearance' => $row['appearance']];
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

    public function save(array $input): array
    {
        $settings = self::validate($input);
        $this->db->query('INSERT INTO qotp_app_settings(id,default_domain,default_domains,generator_type,default_prefix,auto_fill_watch,auto_start_watch,appearance,updated_at) VALUES (1,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE default_domain=VALUES(default_domain),default_domains=VALUES(default_domains),generator_type=VALUES(generator_type),default_prefix=VALUES(default_prefix),auto_fill_watch=VALUES(auto_fill_watch),auto_start_watch=VALUES(auto_start_watch),appearance=VALUES(appearance),updated_at=VALUES(updated_at)', [$settings['default_domain'], json_encode($settings['default_domains'], JSON_THROW_ON_ERROR), $settings['generator_type'], $settings['default_prefix'], (int) $settings['auto_fill_watch'], (int) $settings['auto_start_watch'], $settings['appearance'], now()]);
        return $settings;
    }

    public function storedMail(): array
    {
        return $this->db->one('SELECT * FROM qotp_mail_config WHERE id=1') ?? ['provider' => 'yandex', 'host' => 'imap.yandex.com', 'port' => 993, 'username' => '', 'folder' => 'INBOX', 'password_encrypted' => ''];
    }

    public static function mailResponse(array $mail): array
    {
        return ['provider' => $mail['provider'], 'host' => $mail['host'], 'port' => (int) $mail['port'], 'username' => $mail['username'], 'folder' => $mail['folder'], 'has_password' => $mail['password_encrypted'] !== '', 'configured' => $mail['password_encrypted'] !== '', 'encryption' => 'TLS'];
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
        return compact('provider', 'host', 'port', 'username', 'folder', 'password');
    }

    public function mailRequest(array $input): array
    {
        $mail = self::validateMail($input);
        if ($mail['password'] === '') {
            $stored = $this->storedMail();
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

    public function saveMail(array $input): array
    {
        $mail = $this->mailRequest($input);
        $mail['password_encrypted'] = $this->secrets->encrypt($mail['password']);
        $this->db->query('INSERT INTO qotp_mail_config(id,provider,host,port,username,folder,password_encrypted,updated_at) VALUES (1,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE provider=VALUES(provider),host=VALUES(host),port=VALUES(port),username=VALUES(username),folder=VALUES(folder),password_encrypted=VALUES(password_encrypted),updated_at=VALUES(updated_at)', [$mail['provider'], $mail['host'], $mail['port'], $mail['username'], $mail['folder'], $mail['password_encrypted'], now()]);
        return self::mailResponse($mail);
    }

    public function mailPassword(): array
    {
        $mail = $this->storedMail();
        return self::mailResponse($mail) + ['password' => $mail['password_encrypted'] !== '' ? $this->secrets->decrypt($mail['password_encrypted']) : ''];
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
