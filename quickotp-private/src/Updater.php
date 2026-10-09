<?php
declare(strict_types=1);

namespace QuickOtp;

use RuntimeException;
use ZipArchive;

final class Updater
{
    public const MAX_ZIP = 33554432;
    public const MAX_EXPANDED = 134217728;
    public const MAX_FILE = 8388608;

    public function __construct(private readonly string $private, private readonly ?\Closure $fetch = null, private readonly ?\Closure $afterReplace = null)
    {
    }

    public function capability(): array
    {
        $reason = '';
        try {
            $public = Layout::publicDirectory($this->private);
        } catch (RuntimeException) {
            return ['can_install' => false, 'install_reason' => 'Cấu trúc website không hợp lệ hoặc có symlink.'];
        }
        if (!class_exists(ZipArchive::class) || !function_exists('token_get_all')) {
            $reason = 'Cài extension zip và tokenizer cho PHP của website để cập nhật trực tiếp.';
        } elseif (realpath($this->private) !== $this->private || basename($this->private) !== 'quickotp-private'
            || is_link($public)) {
            $reason = 'Cập nhật trực tiếp cần cấu trúc website hợp lệ và không dùng symlink.';
        } elseif (realpath($this->private . '/storage') !== $this->private . '/storage'
            || !is_writable($this->private) || !is_writable($this->private . '/storage')
            || !is_writable($public)) {
            $reason = 'PHP user cần quyền ghi public_html, quickotp-private và storage. Dùng user website, không cấp quyền 777.';
        } elseif (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL) && (!function_exists('opcache_invalidate')
            || (ini_get('opcache.restrict_api') !== '' && !str_starts_with($public . '/', ini_get('opcache.restrict_api'))))) {
            $reason = 'Hosting đang chặn làm mới OPcache; dùng cập nhật thủ công hoặc điều chỉnh quyền OPcache.';
        }
        return ['can_install' => $reason === '', 'install_reason' => $reason];
    }

    public static function download(string $url, string $destination, int $maximum): void
    {
        for ($redirects = 0; $redirects < 5; $redirects++) {
            $parts = parse_url($url);
            if (strlen($url) > 8192 || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
                || !in_array($parts['host'] ?? '', ['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443) {
                throw new HttpError(502, 'GitHub trả về đường dẫn tải không hợp lệ.');
            }
            $file = fopen($destination, 'w+b');
            if ($file === false) {
                throw new RuntimeException('Cannot prepare download');
            }
            $bytes = 0;
            $location = '';
            $handle = curl_init($url);
            curl_setopt_array($handle, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'Quick-OTP-Mail-PHP/' . System::VERSION,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$location): int {
                    if (str_starts_with(strtolower($header), 'location:')) {
                        $location = trim(substr($header, 9));
                    }
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $part) use ($file, &$bytes, $maximum): int {
                    $bytes += strlen($part);
                    return $bytes > $maximum ? 0 : (int) fwrite($file, $part);
                }]);
            try {
                $ok = curl_exec($handle);
                $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            } finally {
                curl_close($handle);
                fclose($file);
            }
            if ($ok === false) {
                throw new HttpError(502, 'Không tải được gói cập nhật hoặc file vượt giới hạn. Thử lại sau.');
            }
            if ($status === 200) {
                return;
            }
            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
                $url = $location;
                continue;
            }
            throw new HttpError(502, 'Không tải được bộ cài PHP từ GitHub.');
        }
        throw new HttpError(502, 'Đường dẫn tải chuyển tiếp quá nhiều lần.');
    }

    public function stage(string $zipPath, string $stage, string $version): array
    {
        if (!System::validVersion($version) || filesize($zipPath) > self::MAX_ZIP) {
            throw new HttpError(400, 'Gói cập nhật không hợp lệ.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new HttpError(400, 'Không đọc được ZIP cập nhật.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 4000) {
                throw new HttpError(400, 'ZIP vượt giới hạn số file.');
            }
            $prefix = 'Quick-Otp-Email-Check-PHP_v' . $version . '/';
            $entries = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if (!str_starts_with($name, $prefix) || str_contains($name, '\\') || str_contains($name, "\0")) {
                    throw new HttpError(400, 'ZIP chứa đường dẫn không hợp lệ.');
                }
                $relative = substr($name, strlen($prefix));
                $normalized = rtrim($relative, '/');
                if ($normalized === '' || !preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D', $normalized)
                    || array_intersect(explode('/', $normalized), ['.', '..']) || isset($entries[$normalized])) {
                    throw new HttpError(400, 'ZIP chứa đường dẫn trùng lặp hoặc không hợp lệ.');
                }
                $zip->getExternalAttributesIndex($i, $system, $attributes);
                $type = ($attributes >> 16) & 0170000;
                if (($system === ZipArchive::OPSYS_UNIX && !in_array($type, [0, 0100000, 0040000], true))
                    || ($stat['encryption_method'] ?? 0) !== 0 || $stat['size'] > self::MAX_FILE
                    || ($total += $stat['size']) > self::MAX_EXPANDED) {
                    throw new HttpError(400, 'ZIP chứa symlink, file mã hóa hoặc dữ liệu vượt giới hạn.');
                }
                $entries[$normalized] = ['index' => $i, 'directory' => str_ends_with($relative, '/'), 'size' => $stat['size']];
            }
            if (!isset($entries['update.json']) || $entries['update.json']['size'] > 1048576) {
                throw new HttpError(400, 'Bản này không hỗ trợ cập nhật trực tiếp. Dùng hướng dẫn cập nhật thủ công.');
            }
            $manifest = json_decode((string) $zip->getFromIndex($entries['update.json']['index']), true, 32, JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? null) !== 1 || ($manifest['version'] ?? null) !== $version
                || !is_int($manifest['php_min'] ?? null) || $manifest['php_min'] > PHP_VERSION_ID
                || ($manifest['schema_sha256'] ?? '') !== hash_file('sha256', $this->private . '/schema.sql')
                || !is_array($manifest['files'] ?? null) || count($manifest['files']) > 4000
                || ($manifest['files']['quickotp-private/schema.sql'] ?? null) !== ($manifest['schema_sha256'] ?? null)) {
                throw new HttpError(400, 'Bản mới không tương thích PHP/database hiện tại. Cập nhật thủ công theo Release.');
            }
            foreach ($entries as $relative => $entry) {
                if (!$entry['directory'] && $relative !== 'update.json' && !isset($manifest['files'][$relative])) {
                    throw new HttpError(400, 'ZIP chứa file ngoài manifest.');
                }
            }
            $files = [];
            foreach ($manifest['files'] as $relative => $hash) {
                $entry = $entries[$relative] ?? null;
                if (!$entry || $entry['directory'] || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                    throw new HttpError(400, 'Manifest thiếu file hoặc checksum không hợp lệ.');
                }
                $data = $zip->getFromIndex($entry['index']);
                if (!is_string($data) || strlen($data) !== $entry['size'] || !hash_equals($hash, hash('sha256', $data))) {
                    throw new HttpError(400, 'Checksum file trong ZIP không đúng.');
                }
                if (UpdateRecovery::allowed($relative)) {
                    if (str_ends_with($relative, '.php')) {
                        try {
                            token_get_all($data, TOKEN_PARSE);
                        } catch (\ParseError) {
                            throw new HttpError(400, 'Gói cập nhật chứa mã PHP không hợp lệ.');
                        }
                    }
                    $target = $stage . '/' . $relative;
                    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) {
                        throw new RuntimeException('Cannot create staging directory');
                    }
                    if (file_put_contents($target, $data, LOCK_EX) !== strlen($data) || !chmod($target, 0600)) {
                        throw new RuntimeException('Cannot stage update');
                    }
                    $files[$relative] = $target;
                } elseif (!in_array($relative, ['README.md', 'CYBERPANEL.md', 'SECURITY_REVIEW.md', 'CHANGELOG.md', 'VERSION', 'quickotp-private/storage/.gitkeep'], true)) {
                    throw new HttpError(400, 'ZIP muốn thay file cấu hình hoặc dữ liệu riêng.');
                }
            }
            foreach (['public_html/index.php', 'public_html/install.php', 'public_html/.htaccess', 'quickotp-private/bootstrap.php',
                'quickotp-private/update-recovery.php', 'quickotp-private/src/Updater.php', 'quickotp-private/src/System.php',
                'quickotp-private/src/App.php', 'quickotp-private/vendor/autoload.php', 'quickotp-private/schema.sql'] as $required) {
                if (!isset($files[$required])) {
                    throw new HttpError(400, 'Gói cập nhật thiếu mã nguồn cần thiết.');
                }
            }
            $systemSource = (string) file_get_contents($files['quickotp-private/src/System.php']);
            if (!preg_match("/const VERSION = '" . preg_quote($version, '/') . "';/", $systemSource)) {
                throw new HttpError(400, 'Phiên bản mã nguồn không khớp gói cập nhật.');
            }
            $manifestPath = $stage . '/quickotp-private/update-manifest.json';
            if (file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Cannot stage update manifest');
            }
            $files['quickotp-private/update-manifest.json'] = $manifestPath;
            return $files;
        } finally {
            $zip->close();
        }
    }

    public function install(array $latest, $requestLock, callable $databaseBackup): array
    {
        $capability = $this->capability();
        if (!$capability['can_install']) {
            throw new HttpError(409, $capability['install_reason']);
        }
        $version = $latest['number'] ?? '';
        $name = 'Quick-Otp-Email-Check-PHP_v' . $version . '.zip';
        $base = 'https://github.com/' . System::REPOSITORY . '/releases/download/v' . $version . '/';
        if (!System::newer($version, System::VERSION) || ($latest['download_url'] ?? '') !== $base . $name
            || ($latest['checksums_url'] ?? '') !== $base . 'checksums-php.txt' || !is_resource($requestLock)) {
            throw new HttpError(409, 'Không có bản cập nhật hợp lệ mới hơn.');
        }
        $storage = $this->private . '/storage';
        if (is_link($storage . '/updates') || is_link($storage . '/update.lock')) {
            throw new RuntimeException('Invalid update storage');
        }
        $mutex = fopen($storage . '/update.lock', 'c+b');
        if ($mutex === false || !flock($mutex, LOCK_EX | LOCK_NB)) {
            throw new HttpError(409, 'Một yêu cầu cập nhật đang chạy.');
        }
        @chmod($storage . '/update.lock', 0600);
        $work = $storage . '/updates/' . bin2hex(random_bytes(16));
        $backup = $work . '/backup';
        $pending = $storage . '/update-pending.json';
        $journalReady = false;
        $replacing = false;
        $exclusive = false;
        $completed = false;
        $ownsPending = false;
        try {
            if (is_file($pending)) {
                throw new HttpError(409, 'Cần phục hồi lần cập nhật trước theo hướng dẫn.');
            }
            if (!mkdir($work . '/stage', 0700, true) || !mkdir($backup, 0700)) {
                throw new RuntimeException('Cannot prepare update directory');
            }
            if (!copy($this->private . '/update-recovery.php', $backup . '/restore.php') || !chmod($backup . '/restore.php', 0600)) {
                throw new RuntimeException('Cannot prepare standalone recovery');
            }
            @set_time_limit(180);
            ignore_user_abort(true);
            $fetch = $this->fetch ?? self::download(...);
            $fetch($base . 'checksums-php.txt', $work . '/checksums.txt', 65536);
            $matches = [];
            foreach (file($work . '/checksums.txt', FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^([a-fA-F0-9]{64})\s+\*?' . preg_quote($name, '/') . '$/D', $line, $match)) {
                    $matches[] = strtolower($match[1]);
                }
            }
            if (count($matches) !== 1) {
                throw new HttpError(400, 'Thiếu checksum duy nhất của bộ cài PHP.');
            }
            $fetch($base . $name, $work . '/package.zip', self::MAX_ZIP);
            if (filesize($work . '/package.zip') > self::MAX_ZIP || !hash_equals($matches[0], (string) hash_file('sha256', $work . '/package.zip'))) {
                throw new HttpError(400, 'Checksum ZIP không đúng. Chưa thay đổi website.');
            }
            $files = $this->stage($work . '/package.zip', $work . '/stage', $version);
            $root = dirname($this->private);
            $public = Layout::publicDirectory($this->private);
            $flat = $public === $root;
            $newBytes = 0;
            $oldBytes = 0;
            foreach ($files as $relative => $source) {
                $target = UpdateRecovery::target($root, $relative, $flat);
                $parent = dirname($target);
                while (!is_dir($parent)) {
                    $parent = dirname($parent);
                }
                if (!is_writable($parent) || (is_file($target) && !is_readable($target))) {
                    throw new HttpError(409, 'PHP user không có quyền sao lưu/thay mã nguồn. Chưa thay đổi website.');
                }
                $newBytes += filesize($source);
                $oldBytes += is_file($target) ? filesize($target) : 0;
            }
            $privateFree = disk_free_space($storage);
            $publicFree = disk_free_space($public);
            if (($privateFree !== false && $privateFree < $newBytes + $oldBytes + Backup::MAX_FILE + self::MAX_FILE)
                || ($publicFree !== false && $publicFree < $newBytes + self::MAX_FILE)) {
                throw new HttpError(409, 'Không đủ dung lượng cho sao lưu và cập nhật an toàn. Chưa thay đổi website.');
            }
            register_shutdown_function(static function () use (&$completed, &$replacing, &$ownsPending, $pending, $backup): void {
                if (!$completed && $ownsPending) {
                    try {
                        if ($replacing) {
                            UpdateRecovery::restore($backup);
                        } elseif (is_file($pending)) {
                            unlink($pending);
                        }
                    } catch (\Throwable) {
                        // Keep maintenance marker and standalone recovery files on failure.
                    }
                }
            });
            UpdateRecovery::writeJson($pending, ['backup' => substr($backup, strlen($this->private) + 1), 'phase' => 'preparing']);
            $ownsPending = true;
            @chmod($pending, 0600);
            // New requests now stop before loading classes; let existing requests finish.
            $deadline = microtime(true) + 15;
            do {
                $exclusive = flock($requestLock, LOCK_EX | LOCK_NB);
                if (!$exclusive) {
                    usleep(50000);
                }
            } while (!$exclusive && microtime(true) < $deadline);
            if (!$exclusive) {
                throw new HttpError(409, 'Website đang xử lý yêu cầu khác. Thử cập nhật lại sau.');
            }
            $data = $databaseBackup();
            if (!is_string($data) || !str_starts_with($data, Backup::MAGIC)
                || file_put_contents($backup . '/database.qotp', $data, LOCK_EX) !== strlen($data)) {
                throw new RuntimeException('Cannot save database backup');
            }
            chmod($backup . '/database.qotp', 0600);
            $journal = ['root' => $root, 'flat' => $flat, 'version' => $version, 'files' => []];
            foreach ($files as $relative => $source) {
                $target = UpdateRecovery::target($root, $relative, $flat);
                $old = ['exists' => is_file($target)];
                if ($old['exists']) {
                    $old['sha256'] = hash_file('sha256', $target);
                    $old['mode'] = fileperms($target) & 0777;
                    UpdateRecovery::replace($target, $backup . '/files/' . $relative, 0600);
                }
                $journal['files'][$relative] = $old;
            }
            UpdateRecovery::writeJson($backup . '/journal.json', $journal);
            chmod($backup . '/journal.json', 0600);
            chmod($backup . '/restore.php', 0600);
            $journalReady = true;
            UpdateRecovery::writeJson($pending, ['backup' => substr($backup, strlen($this->private) + 1), 'phase' => 'replacing']);
            $replacing = true;
            foreach ($files as $relative => $source) {
                $target = UpdateRecovery::target($root, $relative, $flat);
                UpdateRecovery::replace($source, $target, str_starts_with($relative, 'public_html/') ? 0644 : 0600);
                if ($this->afterReplace !== null) {
                    ($this->afterReplace)($relative);
                }
            }
            foreach ($files as $relative => $source) {
                if (!hash_equals((string) hash_file('sha256', $source), (string) hash_file('sha256', UpdateRecovery::target($root, $relative, $flat)))) {
                    throw new RuntimeException('Installed file verification failed');
                }
            }
            if (!unlink($pending)) {
                throw new RuntimeException('Cannot leave maintenance');
            }
            $completed = true;
            $replacing = false;
            return ['status' => 'updated', 'version' => 'v' . $version . '-php', 'recovery_directory' => substr($backup, strlen($this->private) + 1), 'database_backup_password' => 'admin_password_at_update'];
        } catch (\Throwable $error) {
            if ($journalReady && $replacing && $exclusive) {
                try {
                    UpdateRecovery::restore($backup);
                    $replacing = false;
                } catch (\Throwable) {
                    throw new HttpError(500, 'Chưa phục hồi được. Dùng restore.php trong thư mục storage/updates theo hướng dẫn; website đang bảo trì.');
                }
            } elseif ($ownsPending && !$replacing && is_file($pending)) {
                @unlink($pending);
            }
            if ($error instanceof HttpError) {
                throw $error;
            }
            throw new HttpError(500, 'Cập nhật chưa hoàn tất; mã nguồn cũ được giữ hoặc đã phục hồi. Kiểm tra dung lượng và quyền thư mục rồi thử lại.');
        } finally {
            // Downloads/staging contain no runtime secrets and are not needed for recovery.
            if (is_dir($work)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($work, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($iterator as $item) {
                    if (str_starts_with($item->getPathname(), $backup . '/') || $item->getPathname() === $backup) {
                        continue;
                    }
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
            }
            fclose($mutex);
        }
    }
}
