<?php
declare(strict_types=1);

namespace QuickOtp;

use RuntimeException;

final class UpdateRecovery
{
    public static function writeJson(string $path, array $data): void
    {
        $temporary = $path . '.qotp-' . bin2hex(random_bytes(8));
        $file = fopen($temporary, 'x+b');
        if ($file === false) {
            throw new RuntimeException('Cannot write update metadata');
        }
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            if (fwrite($file, $json) !== strlen($json) || !fflush($file) || !fsync($file) || !chmod($temporary, 0600)) {
                throw new RuntimeException('Cannot persist update metadata');
            }
            fclose($file);
            $file = null;
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Cannot publish update metadata');
            }
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public static function allowed(string $path): bool
    {
        if (!preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D', $path)
            || array_intersect(explode('/', $path), ['.', '..'])) {
            return false;
        }
        if (preg_match('~^public_html/(?:[A-Za-z0-9_-]+\.(?:css|js|svg|png|ico)|index\.php|install\.php|\.htaccess|\.user\.ini)$~D', $path)) {
            return true;
        }
        return preg_match('~^quickotp-private/(?:src/[^/].*|views/[^/].*|vendor/[^/].*|bootstrap\.php|update-recovery\.php|update-manifest\.json|schema\.sql|names\.json|composer\.json|composer\.lock|config\.example\.php)$~D', $path) === 1;
    }

    public static function target(string $root, string $relative, bool $flat = false): string
    {
        if (!self::allowed($relative) || is_link($root)) {
            throw new RuntimeException('Invalid update path');
        }
        if ($flat && str_starts_with($relative, 'public_html/')) {
            $relative = substr($relative, strlen('public_html/'));
        }
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= '/' . $part;
            if (is_link($path)) {
                throw new RuntimeException('Update paths cannot contain symlinks');
            }
        }
        if (file_exists($path) && !is_file($path)) {
            throw new RuntimeException('Update target is not a regular file');
        }
        return $path;
    }

    public static function replace(string $source, string $target, int $mode): void
    {
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, str_contains($target, '/public_html/') ? 0755 : 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('Cannot create update directory');
        }
        $temporary = $target . '.qotp-' . bin2hex(random_bytes(8));
        try {
            $output = fopen($temporary, 'x+b');
            $input = fopen($source, 'rb');
            if ($output === false || $input === false) {
                throw new RuntimeException('Cannot open update file');
            }
            try {
                $copied = stream_copy_to_stream($input, $output);
                if ($copied === false || $copied !== filesize($source) || !fflush($output)
                    || !fsync($output) || !chmod($temporary, $mode)) {
                    throw new RuntimeException('Cannot write update file');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            if (!rename($temporary, $target)) {
                throw new RuntimeException('Cannot replace update file');
            }
            if (str_ends_with($target, '.php') && function_exists('opcache_invalidate')) {
                @opcache_invalidate($target, true);
            }
            clearstatcache(true, $target);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public static function restore(string $backup): void
    {
        $private = dirname($backup, 4);
        $root = dirname($private);
        if (realpath($backup) !== $backup || basename($private) !== 'quickotp-private'
            || !preg_match('~^' . preg_quote($private . '/storage/updates/', '~') . '[a-f0-9]{32}/backup$~D', $backup)) {
            throw new RuntimeException('Invalid recovery directory');
        }
        $journalPath = $backup . '/journal.json';
        if (!is_file($journalPath)) {
            $pending = $private . '/storage/update-pending.json';
            if (!is_link($pending) && is_file($pending) && filesize($pending) <= 4096) {
                $state = json_decode((string) file_get_contents($pending), true);
                if (($state['phase'] ?? null) === 'preparing'
                    && ($state['backup'] ?? null) === substr($backup, strlen($private) + 1)
                    && unlink($pending)) {
                    // No source file is changed until a complete journal is persisted.
                    return;
                }
            }
            throw new RuntimeException('Invalid recovery journal');
        }
        if (!is_file($journalPath) || is_link($journalPath) || filesize($journalPath) > 2097152) {
            throw new RuntimeException('Invalid recovery journal');
        }
        $journal = json_decode((string) file_get_contents($journalPath), true, 32, JSON_THROW_ON_ERROR);
        if (($journal['root'] ?? null) !== $root || !is_array($journal['files'] ?? null) || count($journal['files']) > 4000) {
            throw new RuntimeException('Invalid recovery journal');
        }
        $flat = $journal['flat'] ?? false;
        // The saved layout must survive missing/broken entry points after an interrupted update.
        $public = $flat === true ? $root : $root . '/public_html';
        if (!is_bool($flat) || !is_dir($public) || is_link($public) || realpath($public) !== $public) {
            throw new RuntimeException('Recovery layout does not match website');
        }
        foreach ($journal['files'] as $relative => $old) {
            self::target($root, $relative, $flat);
            if (!is_array($old) || !is_bool($old['exists'] ?? null)) {
                throw new RuntimeException('Invalid recovery entry');
            }
            if ($old['exists']) {
                $source = self::target($backup . '/files', $relative);
                if (!is_file($source) || !preg_match('/^[a-f0-9]{64}$/D', $old['sha256'] ?? '')
                    || !hash_equals($old['sha256'], (string) hash_file('sha256', $source))
                    || !is_int($old['mode'] ?? null) || $old['mode'] < 0 || $old['mode'] > 0777) {
                    throw new RuntimeException('Recovery file is missing or damaged');
                }
            }
        }
        foreach ($journal['files'] as $relative => $old) {
            $target = self::target($root, $relative, $flat);
            if ($old['exists']) {
                self::replace($backup . '/files/' . $relative, $target, $old['mode']);
            } elseif (is_file($target)) {
                if (!unlink($target)) {
                    throw new RuntimeException('Cannot remove new update file');
                }
                if (str_ends_with($target, '.php') && function_exists('opcache_invalidate')) {
                    @opcache_invalidate($target, true);
                }
            }
        }
        $pending = $private . '/storage/update-pending.json';
        if (is_file($pending) && !unlink($pending)) {
            throw new RuntimeException('Cannot leave maintenance mode');
        }
    }
}

// A standalone copy is kept beside every recovery journal, independent of app bootstrap.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $backup = realpath($argv[1] ?? __DIR__);
    $maintenance = $mutex = null;
    try {
        if ($backup === false) {
            throw new RuntimeException('Recovery directory not found');
        }
        $storage = dirname($backup, 3);
        $mutex = fopen($storage . '/update.lock', 'c+b');
        $maintenance = fopen($storage . '/maintenance.lock', 'c+b');
        if ($mutex === false || $maintenance === false || !flock($mutex, LOCK_EX | LOCK_NB)
            || !flock($maintenance, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Website is busy; retry recovery shortly');
        }
        UpdateRecovery::restore($backup);
        echo "Mã nguồn cũ đã được phục hồi. Khởi động lại PHP/LSAPI nếu OPcache đang bật.\n";
    } catch (\Throwable $error) {
        fwrite(STDERR, "Chưa phục hồi được mã nguồn: " . $error->getMessage() . "\n");
        exit(1);
    } finally {
        foreach ([$maintenance, $mutex] as $lock) {
            if (is_resource($lock)) {
                fclose($lock);
            }
        }
    }
}
