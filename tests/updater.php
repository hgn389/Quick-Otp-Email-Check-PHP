<?php
declare(strict_types=1);

require dirname(__DIR__) . '/quickotp-private/bootstrap.php';

use QuickOtp\Backup;
use QuickOtp\HttpError;
use QuickOtp\System;
use QuickOtp\Updater;
use QuickOtp\UpdateRecovery;

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/quickotp-updater-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$count = 0;
function assertUpdate(bool $condition, string $label): void {
    global $count;
    if (!$condition) throw new RuntimeException($label);
    $count++;
}
function copyTree(string $source, string $destination): void {
    mkdir($destination, 0700, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $target = $destination . substr($item->getPathname(), strlen($source));
        $item->isDir() ? mkdir($target, 0700) : copy($item->getPathname(), $target);
    }
}
function newSite(): string {
    global $root, $temporary;
    $site = $temporary . '/site-' . bin2hex(random_bytes(4));
    mkdir($site, 0700);
    copyTree($root . '/public_html', $site . '/public_html');
    mkdir($site . '/quickotp-private', 0700);
    foreach (['src', 'views', 'vendor'] as $directory) copyTree($root . '/quickotp-private/' . $directory, $site . '/quickotp-private/' . $directory);
    foreach (['bootstrap.php', 'update-recovery.php', 'schema.sql', 'names.json', 'composer.json', 'composer.lock', 'config.example.php'] as $file) copy($root . '/quickotp-private/' . $file, $site . '/quickotp-private/' . $file);
    mkdir($site . '/quickotp-private/storage', 0700);
    file_put_contents($site . '/quickotp-private/config.php', "<?php return ['test' => 'preserve-configuration'];\n");
    file_put_contents($site . '/quickotp-private/storage/persistent-data', 'preserve-runtime-data');
    file_put_contents($site . '/quickotp-private/install-password.php', "<?php return 'Synthetic-retained-setup-password';\n");
    return $site;
}
function futureZip(string $site, array $changes = [], bool $symlink = false, bool $wrongSchema = false): string {
    global $temporary;
    $version = '1.0.0-beta-5';
    $path = $temporary . '/package-' . bin2hex(random_bytes(4)) . '.zip';
    $files = [];
    foreach (['public_html', 'quickotp-private'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($site . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $item) {
            $relative = substr($item->getPathname(), strlen($site) + 1);
            if ($item->isFile() && UpdateRecovery::allowed($relative) && $relative !== 'quickotp-private/update-manifest.json') $files[$relative] = file_get_contents($item->getPathname());
        }
    }
    $files['quickotp-private/src/System.php'] = str_replace("const VERSION = '" . System::VERSION . "';", "const VERSION = '$version';", $files['quickotp-private/src/System.php']);
    $files['public_html/app.js'] .= "\n// Synthetic updater integration fixture.\n";
    foreach ($changes as $name => $data) $files[$name] = $data;
    $hashes = [];
    foreach ($files as $name => $data) $hashes[$name] = hash('sha256', $data);
    $manifest = ['format' => 1, 'version' => $version, 'php_min' => 80300, 'schema_sha256' => $wrongSchema ? str_repeat('0', 64) : hash_file('sha256', $site . '/quickotp-private/schema.sql'), 'files' => $hashes];
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $prefix = 'Quick-Otp-Email-Check-PHP_v' . $version . '/';
    foreach ($files as $name => $data) $zip->addFromString($prefix . $name, $data);
    $zip->addFromString($prefix . 'update.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    if ($symlink) $zip->setExternalAttributesName($prefix . 'public_html/app.js', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();
    return $path;
}
function releaseFixture(): array {
    $base = 'https://github.com/' . System::REPOSITORY . '/releases/download/v1.0.0-beta-5/';
    return ['number' => '1.0.0-beta-5', 'download_url' => $base . 'Quick-Otp-Email-Check-PHP_v1.0.0-beta-5.zip', 'checksums_url' => $base . 'checksums-php.txt'];
}
function runUpdate(string $site, string $zip, ?Closure $fault = null, bool $badHash = false): array {
    $fetch = static function (string $url, string $destination, int $limit) use ($zip, $badHash): void {
        if (str_ends_with($url, 'checksums-php.txt')) file_put_contents($destination, ($badHash ? str_repeat('0', 64) : hash_file('sha256', $zip)) . "  Quick-Otp-Email-Check-PHP_v1.0.0-beta-5.zip\n");
        else copy($zip, $destination);
    };
    $lock = fopen($site . '/quickotp-private/storage/maintenance.lock', 'c+b');
    flock($lock, LOCK_SH);
    try {
        return (new Updater($site . '/quickotp-private', $fetch, $fault))->install(releaseFixture(), $lock, static fn (): string => Backup::MAGIC . 'synthetic-backup-fixture');
    } finally {
        fclose($lock);
    }
}
function updateFails(callable $action, string $label): void {
    try { $action(); } catch (Throwable $error) { assertUpdate(true, $label); return; }
    throw new RuntimeException($label);
}
try {
    $site = newSite();
    file_put_contents($site . '/index.php', '<?php // Unrelated hosting placeholder');
    file_put_contents($site . '/install.php', '<?php // Unrelated hosting placeholder');
    assertUpdate(\QuickOtp\Layout::publicDirectory($site . '/quickotp-private') === $site . '/public_html', 'Split layout takes precedence over unrelated website home files');
    $original = file_get_contents($site . '/public_html/app.js');
    $config = file_get_contents($site . '/quickotp-private/config.php');
    $result = runUpdate($site, futureZip($site));
    assertUpdate(file_get_contents($site . '/quickotp-private/install-password.php') === "<?php return 'Synthetic-retained-setup-password';\n", 'Configured installation password is preserved during updates');
    assertUpdate($result['status'] === 'updated' && $result['version'] === 'v1.0.0-beta-5-php', 'New version installed');
    assertUpdate(file_get_contents($site . '/public_html/app.js') !== $original, 'New assets installed');
    assertUpdate(file_get_contents($site . '/quickotp-private/config.php') === $config && file_get_contents($site . '/quickotp-private/storage/persistent-data') === 'preserve-runtime-data', 'Configuration and runtime preserved');
    $backup = $site . '/quickotp-private/' . $result['recovery_directory'];
    assertUpdate(file_get_contents($backup . '/files/public_html/app.js') === $original && str_starts_with(file_get_contents($backup . '/database.qotp'), Backup::MAGIC), 'Source and database recovery copies saved');
    assertUpdate(!file_exists($site . '/quickotp-private/storage/update-pending.json'), 'Maintenance cleared on success');
    $entryPoint = file_get_contents($site . '/public_html/index.php');
    unlink($site . '/public_html/index.php');
    $process = proc_open([PHP_BINARY, $backup . '/restore.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    foreach ($pipes as $pipe) { stream_get_contents($pipe); fclose($pipe); }
    assertUpdate(proc_close($process) === 0 && file_get_contents($site . '/public_html/app.js') === $original, 'Standalone recovery restores source without app bootstrap');
    assertUpdate(file_get_contents($site . '/public_html/index.php') === $entryPoint, 'Standalone recovery restores a missing entry point using the journal layout');
    assertUpdate(file_get_contents($site . '/index.php') === '<?php // Unrelated hosting placeholder', 'Recovery leaves unrelated website home files unchanged');
    assertUpdate(!file_exists($site . '/quickotp-private/update-manifest.json'), 'Recovery removes files introduced by update');

    $site = newSite();
    $original = file_get_contents($site . '/public_html/app.js');
    updateFails(fn () => runUpdate($site, futureZip($site), static function (string $relative): void { if ($relative === 'public_html/app.js') throw new RuntimeException('Synthetic write failure'); }), 'Write failure reported');
    assertUpdate(file_get_contents($site . '/public_html/app.js') === $original && !file_exists($site . '/quickotp-private/storage/update-pending.json'), 'Write failure restores old source and clears maintenance');
    assertUpdate(file_get_contents($site . '/quickotp-private/storage/persistent-data') === 'preserve-runtime-data', 'Rollback preserves runtime data');

    foreach (['checksum', 'schema', 'schema-content', 'traversal', 'secret', 'syntax', 'symlink', 'oversize'] as $case) {
        $site = newSite();
        $original = file_get_contents($site . '/public_html/app.js');
        $changes = match ($case) {
            'traversal' => ['public_html/../../escape.php' => '<?php echo "invalid";'],
            'secret' => ['quickotp-private/config.php' => '<?php echo "overwrite";'],
            'syntax' => ['quickotp-private/src/Updater.php' => '<?php function invalid( {'],
            'schema-content' => ['quickotp-private/schema.sql' => 'changed-schema-fixture'],
            'oversize' => ['public_html/app.js' => str_repeat('x', Updater::MAX_FILE + 1)],
            default => [],
        };
        $zip = futureZip($site, $changes, $case === 'symlink', $case === 'schema');
        updateFails(fn () => runUpdate($site, $zip, null, $case === 'checksum'), "Invalid $case rejected");
        assertUpdate(file_get_contents($site . '/public_html/app.js') === $original && !file_exists($site . '/quickotp-private/storage/update-pending.json'), "Invalid $case leaves website unchanged");
    }
    $site = newSite();
    file_put_contents($site . '/quickotp-private/storage/update-pending.json', 'existing-recovery-marker');
    updateFails(fn () => runUpdate($site, futureZip($site)), 'Unfinished update blocks another update');
    assertUpdate(file_get_contents($site . '/quickotp-private/storage/update-pending.json') === 'existing-recovery-marker', 'Existing recovery marker preserved');
    $site = newSite();
    $backup = $site . '/quickotp-private/storage/updates/' . bin2hex(random_bytes(16)) . '/backup';
    mkdir($backup, 0700, true);
    UpdateRecovery::writeJson($site . '/quickotp-private/storage/update-pending.json', ['backup' => substr($backup, strlen($site . '/quickotp-private') + 1), 'phase' => 'preparing']);
    UpdateRecovery::restore($backup);
    assertUpdate(!file_exists($site . '/quickotp-private/storage/update-pending.json'), 'Interrupted preparation can leave maintenance without replacing source');
    foreach (['http://github.com/a', 'https://github.com.evil.example/a', 'https://user@github.com/a', 'https://127.0.0.1/a', 'https://github.com:444/a'] as $url) {
        updateFails(fn () => Updater::download($url, $temporary . '/invalid-download', 1024), 'Non-approved download URL rejected');
    }
    echo "$count updater checks passed: installation, standalone recovery, write rollback, runtime preservation, malformed ZIP/schema/PHP and download URL restrictions.\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($temporary);
}
