<?php
declare(strict_types=1);

namespace QuickOtp;

use RuntimeException;

final class Backup
{
    public const MAX_FILE = 33554432;
    public const MAX_PLAIN = 16777216;
    public const MAX_ROWS = 50000;
    public const MAGIC = 'QOTPPH01';
    public const TABLES = [
        'users' => ['id', 'username', 'password_hash', 'must_change_password', 'created_at', 'updated_at', 'last_login_at'],
        'app_settings' => ['id', 'default_domain', 'generator_type', 'default_prefix', 'default_domains', 'auto_fill_watch', 'auto_start_watch', 'appearance', 'updated_at'],
        'generated_emails' => ['id', 'email', 'local_part', 'domain', 'generator_type', 'created_at'],
        'messages' => ['id', 'source_key', 'recipient', 'sender', 'subject', 'body_text', 'otp', 'received_at'],
        'mail_config' => ['id', 'provider', 'host', 'port', 'username', 'folder', 'password_encrypted', 'updated_at'],
        'login_failures' => ['ip_address', 'attempts', 'updated_at'],
        'blocked_ips' => ['ip_address', 'created_at'],
    ];

    public function __construct(private readonly Database $db, private readonly Secrets $secrets, private readonly string $directory, private readonly string $version)
    {
    }

    private static function password(string $password): void
    {
        if (mb_strlen($password) < 10 || strlen($password) > 256) {
            throw new HttpError(400, 'Mật khẩu backup cần ít nhất 10 ký tự, tối đa 256 byte.');
        }
    }

    public static function seal(string $plain, string $password): string
    {
        self::password($password);
        if (strlen($plain) > self::MAX_PLAIN) {
            throw new HttpError(413, 'Dữ liệu backup vượt giới hạn 16 MiB.');
        }
        $salt = random_bytes(16);
        $nonce = random_bytes(12);
        $header = self::MAGIC . $salt . $nonce;
        $key = hash_pbkdf2('sha256', $password, $salt, 600000, 32, true);
        $compressed = gzencode($plain, 6);
        if ($compressed === false) {
            throw new RuntimeException('Cannot compress backup');
        }
        $tag = '';
        $encrypted = openssl_encrypt($compressed, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $header, 16);
        if ($encrypted === false) {
            throw new RuntimeException('Cannot encrypt backup');
        }
        $result = $header . $encrypted . $tag;
        if (strlen($result) > self::MAX_FILE) {
            throw new HttpError(413, 'File backup vượt giới hạn 32 MiB.');
        }
        return $result;
    }

    public static function open(string $file, string $password): array
    {
        self::password($password);
        if (strlen($file) < 52 || strlen($file) > self::MAX_FILE || substr($file, 0, 8) !== self::MAGIC) {
            throw new HttpError(400, 'Chọn backup của bản PHP. File backup bản Go dùng định dạng khác.');
        }
        $header = substr($file, 0, 36);
        $key = hash_pbkdf2('sha256', $password, substr($header, 8, 16), 600000, 32, true);
        $compressed = openssl_decrypt(substr($file, 36, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($header, 24, 12), substr($file, -16), $header);
        if ($compressed === false) {
            throw new HttpError(400, 'Sai mật khẩu backup hoặc file đã bị thay đổi.');
        }
        $plain = @gzdecode($compressed, self::MAX_PLAIN + 1);
        if ($plain === false || strlen($plain) > self::MAX_PLAIN) {
            throw new HttpError(400, 'Nội dung backup hỏng hoặc vượt giới hạn.');
        }
        try {
            $manifest = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError(400, 'Nội dung backup không hợp lệ.');
        }
        if (!is_array($manifest)) {
            throw new HttpError(400, 'Nội dung backup không hợp lệ.');
        }
        return $manifest;
    }

    public function make(string $password): string
    {
        $tables = $this->db->transaction(function (): array {
            $result = [];
            $count = 0;
            $size = 0;
            foreach (self::TABLES as $name => $columns) {
                $statement = $this->db->query('SELECT ' . implode(',', $columns) . ' FROM qotp_' . $name);
                $result[$name] = [];
                while ($row = $statement->fetch()) {
                    $count++;
                    $size += strlen(json_encode($row, JSON_THROW_ON_ERROR)) + 1;
                    if ($count > self::MAX_ROWS || $size > self::MAX_PLAIN - 4096) {
                        throw new HttpError(413, 'Backup nhanh giới hạn 50.000 bản ghi và 16 MiB dữ liệu. Dùng export database trong CyberPanel cho dữ liệu lớn hơn.');
                    }
                    $result[$name][] = $row;
                }
            }
            return $result;
        });
        $plain = json_encode(['format' => 'quickotp-php', 'schema' => 1, 'version' => $this->version, 'created_at' => now(), 'mail_key' => $this->secrets->backupKey(), 'tables' => $tables], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return self::seal($plain, $password);
    }

    public function validate(array $manifest): array
    {
        if (($manifest['format'] ?? '') !== 'quickotp-php' || ($manifest['schema'] ?? 0) !== 1 || !is_array($manifest['tables'] ?? null)
            || !is_string($manifest['version'] ?? null) || strlen($manifest['version']) > 64 || !is_string($manifest['created_at'] ?? null)) {
            throw new HttpError(400, 'Backup không tương thích.');
        }
        normalizedTime($manifest['created_at']);
        $key = is_string($manifest['mail_key'] ?? null) ? base64_decode($manifest['mail_key'], true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new HttpError(400, 'Khóa mã hóa trong backup không hợp lệ.');
        }
        $sourceSecrets = new Secrets($key);
        $tables = $manifest['tables'];
        $names = array_keys($tables);
        $expectedNames = array_keys(self::TABLES);
        sort($names);
        sort($expectedNames);
        if ($names !== $expectedNames) {
            throw new HttpError(400, 'Backup thiếu bảng hoặc chứa bảng không được hỗ trợ.');
        }
        $count = 0;
        $size = 0;
        foreach (self::TABLES as $name => $columns) {
            if (!is_array($tables[$name]) || !array_is_list($tables[$name])) {
                throw new HttpError(400, 'Dữ liệu bảng không hợp lệ.');
            }
            $seen = [];
            $unique = [];
            foreach ($tables[$name] as &$row) {
                if (!is_array($row)) {
                    throw new HttpError(400, 'Bản ghi không hợp lệ.');
                }
                $keys = array_keys($row);
                $expected = $columns;
                sort($keys);
                sort($expected);
                if ($keys !== $expected) {
                    throw new HttpError(400, 'Backup chứa cột không hợp lệ.');
                }
                $count++;
                $size += strlen(json_encode($row, JSON_THROW_ON_ERROR));
                if ($count > self::MAX_ROWS || $size > self::MAX_PLAIN) {
                    throw new HttpError(400, 'Backup vượt giới hạn dữ liệu.');
                }
                foreach ($row as $field => $value) {
                    if ($value === null && $field !== 'last_login_at') {
                        throw new HttpError(400, 'Backup chứa giá trị trống không hợp lệ.');
                    }
                    if (!is_int($value) && !is_string($value) && $value !== null) {
                        throw new HttpError(400, 'Giá trị backup không hợp lệ.');
                    }
                    if (is_string($value) && (!mb_check_encoding($value, 'UTF-8') || strlen($value) > 262144)) {
                        throw new HttpError(400, 'Dữ liệu văn bản backup không hợp lệ.');
                    }
                    if (str_ends_with($field, '_at') && $value !== null) {
                        if (!is_string($value)) {
                            throw new HttpError(400, 'Thời gian backup không hợp lệ.');
                        }
                        $row[$field] = normalizedTime($value);
                    }
                }
                $primary = $row['id'] ?? $row['ip_address'];
                if (isset($seen[(string) $primary])) {
                    throw new HttpError(400, 'Backup chứa bản ghi trùng lặp.');
                }
                $seen[(string) $primary] = true;
                if (isset($row['id']) && (!is_int($row['id']) || $row['id'] < 1 || $row['id'] > 9007199254740991)) {
                    throw new HttpError(400, 'ID backup không hợp lệ.');
                }
                switch ($name) {
                    case 'users':
                        $username = Validation::text($row, 'username', 64);
                        $hash = Validation::text($row, 'password_hash', 255);
                        $info = password_get_info($hash);
                        if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', $username) || $info['algoName'] !== 'bcrypt' || ($info['options']['cost'] ?? 0) < 4 || $info['options']['cost'] > 14 || !in_array($row['must_change_password'], [0, 1], true)) {
                            throw new HttpError(400, 'Tài khoản trong backup không hợp lệ.');
                        }
                        $dedupe = strtolower($username);
                        break;
                    case 'app_settings':
                        if ($row['id'] !== 1 || count($tables[$name]) !== 1 || !in_array($row['auto_fill_watch'], [0, 1], true) || !in_array($row['auto_start_watch'], [0, 1], true)) {
                            throw new HttpError(400, 'Cài đặt trong backup không hợp lệ.');
                        }
                        $settings = $row;
                        $settings['default_domains'] = json_decode(Validation::text($row, 'default_domains', 30000), true, 8, JSON_THROW_ON_ERROR);
                        $settings['auto_fill_watch'] = (bool) $row['auto_fill_watch'];
                        $settings['auto_start_watch'] = (bool) $row['auto_start_watch'];
                        Settings::validate($settings);
                        $dedupe = '1';
                        break;
                    case 'generated_emails':
                        $email = Validation::email(Validation::text($row, 'email', 254));
                        if ($email !== $row['email'] || $row['local_part'] . '@' . $row['domain'] !== $email || !in_array($row['generator_type'], Validation::GENERATORS, true)) {
                            throw new HttpError(400, 'Địa chỉ đã tạo trong backup không hợp lệ.');
                        }
                        $dedupe = $email;
                        break;
                    case 'messages':
                        $recipient = Validation::email(Validation::text($row, 'recipient', 254));
                        if ($recipient !== $row['recipient'] || !preg_match('/^[a-f0-9]{64}$/D', Validation::text($row, 'source_key', 64)) || !preg_match('/^(?:[0-9]{4,8})?$/D', Validation::text($row, 'otp', 8))) {
                            throw new HttpError(400, 'Thư trong backup không hợp lệ.');
                        }
                        Validation::text($row, 'sender', 8192);
                        Validation::text($row, 'subject', 8192);
                        Validation::text($row, 'body_text', 262144);
                        $dedupe = $row['source_key'];
                        break;
                    case 'mail_config':
                        if ($row['id'] !== 1 || count($tables[$name]) > 1 || !is_int($row['port'])) {
                            throw new HttpError(400, 'Cấu hình IMAP trong backup không hợp lệ.');
                        }
                        $password = $sourceSecrets->decrypt(Validation::text($row, 'password_encrypted', 2048));
                        if ($password === '') {
                            throw new HttpError(400, 'Backup chứa App Password trống.');
                        }
                        Settings::validateMail($row + ['password' => $password]);
                        $row['password_encrypted'] = $this->secrets->encrypt($password);
                        $dedupe = '1';
                        break;
                    default:
                        $ip = Validation::ip(Validation::text($row, 'ip_address', 45));
                        if ($name === 'login_failures' && (!is_int($row['attempts']) || $row['attempts'] < 0 || $row['attempts'] > 1000000)) {
                            throw new HttpError(400, 'Bộ đếm đăng nhập không hợp lệ.');
                        }
                        $row['ip_address'] = $ip;
                        $dedupe = $ip;
                }
                if (isset($unique[$dedupe])) {
                    throw new HttpError(400, 'Backup chứa giá trị trùng lặp.');
                }
                $unique[$dedupe] = true;
            }
            unset($row);
        }
        if (count($tables['app_settings']) !== 1 || !array_filter($tables['users'], fn ($user) => strtolower($user['username']) === 'admin')) {
            throw new HttpError(400, 'Backup phải có tài khoản Admin và cài đặt.');
        }
        $manifest['tables'] = $tables;
        $manifest['mail_key'] = $this->secrets->backupKey();
        return $manifest;
    }

    public function summary(array $manifest): array
    {
        $counts = array_map('count', $manifest['tables']);
        $counts['domains'] = count(json_decode($manifest['tables']['app_settings'][0]['default_domains'], true));
        return ['version' => $manifest['version'], 'created_at' => $manifest['created_at'], 'counts' => $counts, 'blocked_ips' => $counts['blocked_ips']];
    }

    public function recoveryFiles(): array
    {
        $files = [];
        foreach (glob($this->directory . '/recovery-*.qotp') ?: [] as $path) {
            if (!is_link($path) && is_file($path) && preg_match('/^recovery-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}\.qotp$/D', basename($path))) {
                $files[] = ['name' => basename($path), 'size' => filesize($path), 'created_at' => gmdate('Y-m-d\TH:i:s\Z', filemtime($path))];
            }
        }
        usort($files, fn ($a, $b) => strcmp($b['name'], $a['name']));
        return $files;
    }

    public function downloadRecovery(string $name): string
    {
        if (!preg_match('/^recovery-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}\.qotp$/D', $name)) {
            throw new HttpError(400, 'Tên bản dự phòng không hợp lệ.');
        }
        $path = $this->directory . '/' . $name;
        if (is_link($path) || !is_file($path) || filesize($path) > self::MAX_FILE) {
            throw new HttpError(404, 'Không tìm thấy bản dự phòng.');
        }
        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException('Cannot read recovery file');
        }
        return $data;
    }

    public function restore(array $manifest, string $password): string
    {
        // Validation happens before any SQL writes, then the replacement is atomic.
        $manifest = $this->validate($manifest);
        $recovery = $this->make($password);
        $name = 'recovery-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6)) . '.qotp';
        $path = $this->directory . '/' . $name;
        $handle = @fopen($path, 'x+b');
        if ($handle === false || !chmod($path, 0600)) {
            throw new RuntimeException('Cannot create recovery file');
        }
        try {
            $offset = 0;
            while ($offset < strlen($recovery)) {
                $written = fwrite($handle, substr($recovery, $offset, 65536));
                if (!$written) {
                    throw new RuntimeException('Cannot save recovery file');
                }
                $offset += $written;
            }
            fflush($handle);
        } finally {
            fclose($handle);
        }
        $this->db->transaction(function () use ($manifest): void {
            $blocked = $this->db->query('SELECT * FROM qotp_blocked_ips')->fetchAll();
            $this->db->query('DELETE FROM qotp_sessions');
            $this->db->query('DELETE FROM qotp_reauth_failures');
            foreach (array_reverse(array_keys(self::TABLES)) as $name) {
                $this->db->query('DELETE FROM qotp_' . $name);
            }
            foreach (self::TABLES as $name => $columns) {
                $sql = 'INSERT INTO qotp_' . $name . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
                foreach ($manifest['tables'][$name] as $row) {
                    $this->db->query($sql, array_map(fn ($column) => $row[$column], $columns));
                }
            }
            foreach ($blocked as $row) {
                $this->db->query('INSERT INTO qotp_blocked_ips(ip_address,created_at) VALUES (?,?) ON DUPLICATE KEY UPDATE ip_address=VALUES(ip_address)', [$row['ip_address'], $row['created_at']]);
            }
        });
        return $name;
    }
}
