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

    public function __construct(private readonly Database $db, private readonly Secrets $secrets, private readonly string $version)
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
                        throw new HttpError(413, 'Bản phục hồi cập nhật giới hạn 50.000 bản ghi và 16 MiB dữ liệu. Dùng export database trong CyberPanel cho dữ liệu lớn hơn.');
                    }
                    $result[$name][] = $row;
                }
            }
            return $result;
        });
        $plain = json_encode(['format' => 'quickotp-php', 'schema' => 1, 'version' => $this->version, 'created_at' => now(), 'mail_key' => $this->secrets->backupKey(), 'tables' => $tables], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return self::seal($plain, $password);
    }

}
