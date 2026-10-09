<?php
declare(strict_types=1);

namespace QuickOtp;

use RuntimeException;

final class Installer
{
    public static function requirements(string $private): array
    {
        $checks = ['PHP 8.3 trở lên' => PHP_VERSION_ID >= 80300];
        foreach (['pdo_mysql', 'openssl', 'mbstring', 'iconv', 'zlib', 'curl'] as $extension) {
            $checks['Extension ' . $extension] = extension_loaded($extension);
        }
        $checks['Thư viện giải mã MIME'] = is_file($private . '/vendor/autoload.php');
        $checks['Quyền ghi thư mục website'] = is_writable(Layout::publicDirectory($private));
        $checks['Quyền ghi quickotp-private'] = is_writable($private);
        $checks['Quyền ghi thư mục storage'] = is_dir($private . '/storage') && is_writable($private . '/storage');
        return $checks;
    }

    public static function install(string $private, array $input): void
    {
        if (in_array(false, self::requirements($private), true)) {
            throw new HttpError(400, 'Máy chủ chưa đáp ứng yêu cầu cài đặt.');
        }
        Layout::verifyProtection($private);
        $password = Validation::text($input, 'admin_password', 72);
        Validation::password($password);
        if ($password !== Validation::text($input, 'admin_password_confirm', 72)) {
            throw new HttpError(400, 'Hai mật khẩu Admin không khớp.');
        }
        $port = filter_var($input['db_port'] ?? '3306', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new HttpError(400, 'Port database không hợp lệ.');
        }
        $config = ['db_host' => trim(Validation::text($input, 'db_host', 253)), 'db_port' => $port,
            'db_name' => trim(Validation::text($input, 'db_name', 64)), 'db_user' => trim(Validation::text($input, 'db_user', 128)),
            'db_password' => Validation::text($input, 'db_password', 1024), 'app_key' => base64_encode(random_bytes(32)),
            'dummy_password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12])];
        $lock = fopen($private . '/storage/install.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new HttpError(409, 'Một phiên cài đặt khác đang chạy.');
        }
        $createdConfig = false;
        try {
            if (is_file($private . '/config.php')) {
                throw new HttpError(409, 'Website đã được cài đặt.');
            }
            try {
                $db = new Database($config);
            } catch (\PDOException) {
                throw new HttpError(400, 'Không kết nối được database. Kiểm tra host, port, tên database, user và mật khẩu.');
            }
            foreach (explode(';', (string) file_get_contents($private . '/schema.sql')) as $statement) {
                if (trim($statement) !== '') {
                    $db->pdo->exec($statement);
                }
            }
            if ($db->one('SELECT id FROM qotp_meta LIMIT 1') || $db->one('SELECT id FROM qotp_users LIMIT 1')) {
                throw new HttpError(409, 'Database đã có dữ liệu Quick OTP. Chọn database mới hoặc khôi phục config.php.');
            }
            $db->transaction(function () use ($db, $config, $password, $private, &$createdConfig): void {
                $time = now();
                $db->query('INSERT INTO qotp_users(username,password_hash,must_change_password,created_at,updated_at) VALUES (\'admin\',?,0,?,?)', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $time, $time]);
                $settings = new Settings($db, new Secrets(base64_decode($config['app_key'], true)));
                $settings->save(Settings::defaults());
                $db->query('INSERT INTO qotp_meta(id,schema_version,installed_at) VALUES (1,1,?)', [$time]);
                $handle = @fopen($private . '/config.php', 'x+b');
                if ($handle === false) {
                    throw new RuntimeException('Cannot write configuration');
                }
                $createdConfig = true;
                try {
                    if (!chmod($private . '/config.php', 0600)) {
                        throw new RuntimeException('Cannot secure configuration');
                    }
                    $content = "<?php\ndeclare(strict_types=1);\nif (PHP_SAPI !== 'cli' && !defined('QUICKOTP_RUNTIME')) { http_response_code(403); exit; }\nreturn " . var_export($config, true) . ";\n";
                    $offset = 0;
                    while ($offset < strlen($content)) {
                        $written = fwrite($handle, substr($content, $offset));
                        if (!$written) {
                            throw new RuntimeException('Cannot write configuration');
                        }
                        $offset += $written;
                    }
                    fflush($handle);
                    if (function_exists('fsync')) {
                        fsync($handle);
                    }
                } finally {
                    fclose($handle);
                }
            });
        } catch (\Throwable $error) {
            if ($createdConfig) {
                unlink($private . '/config.php');
            }
            throw $error;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (is_file($private . '/install-token.txt')) {
            unlink($private . '/install-token.txt');
        }
    }
}
