<?php
declare(strict_types=1);

namespace QuickOtp;

use RuntimeException;

final class System
{
    public const VERSION = '1.0.0-beta-3';
    public const REPOSITORY = 'hgn389/Quick-Otp-Email-Check-PHP';

    public function __construct(private readonly Database $db, private readonly string $directory)
    {
    }

    public function status(): array
    {
        $meta = $this->db->one('SELECT installed_at FROM qotp_meta WHERE id=1');
        return ['version' => 'v' . self::VERSION . '-php', 'php_version' => PHP_VERSION, 'database' => 'MySQL/MariaDB', 'database_version' => $this->db->query('SELECT VERSION()')->fetchColumn(), 'installed_at' => $meta['installed_at'], 'github_repository' => self::REPOSITORY, 'update_mode' => 'source_zip_auto'] + (new Updater(dirname($this->directory)))->capability();
    }

    public static function validVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:-beta[-_][1-9]\d*)?$/D', $version) === 1;
    }

    public static function newer(string $latest, string $current): bool
    {
        return self::validVersion($latest) && self::validVersion($current) && version_compare($latest, $current, '>');
    }

    public static function release(array $releases, ?string $current = null): ?array
    {
        $latest = null;
        $allowBeta = str_contains($current ?? self::VERSION, '-beta');
        foreach ($releases as $release) {
            if (($release['draft'] ?? true) || !preg_match('/^v(\d+\.\d+\.\d+(?:-beta[-_][1-9]\d*)?)$/D', $release['tag_name'] ?? '', $match)) {
                continue;
            }
            $version = $match[1];
            $beta = str_contains($version, '-beta');
            if (($beta && (!$allowBeta || ($release['prerelease'] ?? false) !== true))
                || (!$beta && ($release['prerelease'] ?? true))) {
                continue;
            }
            $assetName = 'Quick-Otp-Email-Check-PHP_v' . $version . '.zip';
            $base = 'https://github.com/' . self::REPOSITORY . '/releases/download/v' . $version . '/';
            $asset = null;
            $checksums = false;
            foreach ($release['assets'] ?? [] as $item) {
                if (($item['name'] ?? '') === $assetName && ($item['browser_download_url'] ?? '') === $base . $assetName) {
                    $asset = $item;
                }
                if (($item['name'] ?? '') === 'checksums-php.txt' && ($item['browser_download_url'] ?? '') === $base . 'checksums-php.txt') {
                    $checksums = true;
                }
            }
            if (!$asset || !$checksums || ($latest && !self::newer($version, $latest['number']))) {
                continue;
            }
            $latest = ['number' => $version, 'version' => 'v' . $version . '-php', 'asset_name' => $assetName, 'asset_size' => (int) ($asset['size'] ?? 0), 'download_url' => $base . $assetName, 'checksums_url' => $base . 'checksums-php.txt', 'release_url' => 'https://github.com/' . self::REPOSITORY . '/releases/tag/v' . $version];
        }
        return $latest;
    }

    public function updates(bool $force = false): array
    {
        $path = $this->directory . '/releases.json';
        $data = null;
        if (!$force && is_file($path) && !is_link($path) && filemtime($path) > time() - 300 && filesize($path) <= 2097152) {
            $data = json_decode((string) file_get_contents($path), true);
        }
        if (!is_array($data)) {
            $handle = curl_init('https://api.github.com/repos/' . self::REPOSITORY . '/releases?per_page=30');
            $body = '';
            curl_setopt_array($handle, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'Quick-OTP-Mail-PHP/' . self::VERSION, CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
                CURLOPT_WRITEFUNCTION => static function ($curl, string $part) use (&$body): int {
                    if (strlen($body) + strlen($part) > 2097152) {
                        return 0;
                    }
                    $body .= $part;
                    return strlen($part);
                }]);
            $ok = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            if ($ok === false || $status !== 200) {
                throw new HttpError(502, 'Không kiểm tra được bản PHP trên GitHub. Hãy thử lại sau.');
            }
            $data = json_decode($body, true);
            if (!is_array($data) || !array_is_list($data)) {
                throw new RuntimeException('Invalid release response');
            }
            $cache = @fopen($path, 'c+b');
            if (is_resource($cache)) {
                if (flock($cache, LOCK_EX)) {
                    chmod($path, 0600);
                    ftruncate($cache, 0);
                    fwrite($cache, $body);
                    fflush($cache);
                    flock($cache, LOCK_UN);
                }
                fclose($cache);
            }
        }
        $latest = self::release($data);
        return ['configured' => true, 'current' => 'v' . self::VERSION . '-php', 'latest' => $latest, 'update_available' => $latest !== null && self::newer($latest['number'], self::VERSION), 'mode' => 'source_zip_auto'] + (new Updater(dirname($this->directory)))->capability();
    }
}
