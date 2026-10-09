<?php
declare(strict_types=1);

namespace QuickOtp;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

final class Http
{
    public static function headers(): void
    {
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; script-src 'self'; connect-src 'self'; form-action 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cache-Control: no-store');
        header('X-LiteSpeed-Cache-Control: no-cache');
    }

    public static function secure(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    public static function sameOrigin(): void
    {
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
            throw new HttpError(403, 'Yêu cầu từ website khác bị từ chối.');
        }
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            $origin = (self::secure() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
            if (!hash_equals(strtolower($origin), strtolower($_SERVER['HTTP_ORIGIN']))) {
                throw new HttpError(403, 'Origin không hợp lệ.');
            }
        }
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function method(string ...$allowed): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            throw new HttpError(405, 'method not allowed');
        }
    }

    public static function body(): array
    {
        if (!preg_match('~^application/json(?:\s*;|$)~i', $_SERVER['CONTENT_TYPE'] ?? '')) {
            throw new HttpError(415, 'Yêu cầu phải dùng JSON.');
        }
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if ($raw === false || strlen($raw) > 65536) {
            throw new HttpError(413, 'Yêu cầu quá lớn.');
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError(400, 'JSON không hợp lệ.');
        }
        if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) {
            throw new HttpError(400, 'Yêu cầu không hợp lệ.');
        }
        return $data;
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }

    public static function binary(string $name, string $data): never
    {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }
}

function now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
}

function normalizedTime(string $value): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
        throw new HttpError(400, 'Thời gian không hợp lệ.');
    }
    try {
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
            throw new \Exception();
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    } catch (\Exception) {
        throw new HttpError(400, 'Thời gian không hợp lệ.');
    }
}

final class Validation
{
    public const GENERATORS = ['vietnamese_name_number', 'usa_name_number', 'canada_name_number', 'random_username', 'random_letters_number', 'random_crypto', 'custom_prefix'];

    public static function text(array $input, string $key, int $maximum = 1024, string $default = ''): string
    {
        $value = $input[$key] ?? $default;
        if (!is_string($value) || strlen($value) > $maximum || !mb_check_encoding($value, 'UTF-8')) {
            throw new HttpError(400, 'Giá trị ' . $key . ' không hợp lệ.');
        }
        return $value;
    }

    public static function domain(string $value): string
    {
        $value = strtolower(ltrim(trim($value), '@'));
        if (strlen($value) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $value)) {
            throw new HttpError(400, 'Nhập tên miền hợp lệ.');
        }
        return $value;
    }

    public static function email(string $value): string
    {
        $value = strtolower(trim($value));
        $parts = explode('@', $value);
        if (strlen($value) > 254 || count($parts) !== 2 || !preg_match('/^[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?$/D', $parts[0])) {
            throw new HttpError(400, 'Nhập địa chỉ email hợp lệ.');
        }
        self::domain($parts[1]);
        return $value;
    }

    public static function password(string $password): void
    {
        if (mb_strlen($password) < 10) {
            throw new HttpError(400, 'new password must contain at least 10 characters');
        }
        if (strlen($password) > 72 || str_contains($password, "\0")) {
            throw new HttpError(400, 'new password must not exceed 72 bytes');
        }
    }

    public static function ip(string $value): string
    {
        $binary = @inet_pton($value);
        if ($binary === false) {
            throw new HttpError(400, 'IP không hợp lệ.');
        }
        if (strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $binary = substr($binary, 12);
        }
        return (string) inet_ntop($binary);
    }
}

final class Database
{
    public readonly PDO $pdo;

    public function __construct(array $config)
    {
        $host = $config['db_host'] ?? 'localhost';
        $name = $config['db_name'] ?? '';
        $port = $config['db_port'] ?? 3306;
        if (!is_string($host) || !preg_match('/^[a-zA-Z0-9.:-]+$/D', $host)
            || !is_string($name) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $name)
            || !is_int($port) || $port < 1 || $port > 65535) {
            throw new HttpError(400, 'Thông tin database không hợp lệ.');
        }
        $this->pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $config['db_user'], $config['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    }

    public function query(string $sql, array $values = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);
        return $statement;
    }

    public function one(string $sql, array $values = []): ?array
    {
        return $this->query($sql, $values)->fetch() ?: null;
    }

    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}

final class Secrets
{
    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('Invalid encryption key');
        }
    }

    public function encrypt(string $value): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, 'quickotp/php/mail/v1', 16);
        if ($ciphertext === false) {
            throw new RuntimeException('Cannot encrypt mail password');
        }
        return base64_encode($nonce . $ciphertext . $tag);
    }

    public function decrypt(string $value): string
    {
        $data = base64_decode($value, true);
        if ($data === false || strlen($data) < 28) {
            throw new RuntimeException('Invalid encrypted mail password');
        }
        $plain = openssl_decrypt(substr($data, 12, -16), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, -16), 'quickotp/php/mail/v1');
        if ($plain === false) {
            throw new RuntimeException('Cannot decrypt mail password');
        }
        return $plain;
    }

    public function backupKey(): string
    {
        return base64_encode($this->key);
    }
}
