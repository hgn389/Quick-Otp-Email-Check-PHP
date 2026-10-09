<?php
declare(strict_types=1);

require dirname(__DIR__) . '/quickotp-private/bootstrap.php';

use QuickOtp\Backup;
use QuickOtp\Generator;
use QuickOtp\HttpError;
use QuickOtp\Imap;
use QuickOtp\Network;
use QuickOtp\Secrets;
use QuickOtp\Settings;
use QuickOtp\System;
use QuickOtp\Validation;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function rejected(callable $work, string $message): void
{
    try {
        $work();
    } catch (Throwable) {
        check(true, $message);
        return;
    }
    check(false, $message);
}

$password = 'Synthetic-private-app-password-2026!';
$secrets = new Secrets(random_bytes(32));
$encrypted = $secrets->encrypt($password);
check($secrets->decrypt($encrypted) === $password, 'Secret round trip');
check(!str_contains($encrypted, $password) && $encrypted !== $secrets->encrypt($password), 'Encryption hides secret and uses unique nonces');
rejected(fn () => (new Secrets(random_bytes(32)))->decrypt($encrypted), 'Wrong key must fail');
$data = base64_decode($encrypted);
$data[15] = chr(ord($data[15]) ^ 1);
rejected(fn () => $secrets->decrypt(base64_encode($data)), 'Tampered mail secret must fail');

$plain = json_encode(['example' => $password], JSON_THROW_ON_ERROR);
$backupPassword = 'Synthetic-backup-password-2026!';
$archive = Backup::seal($plain, $backupPassword);
check(Backup::open($archive, $backupPassword)['example'] === $password, 'Backup encryption round trip');
check(!str_contains($archive, $password), 'Backup does not contain plaintext');
rejected(fn () => Backup::open($archive, 'Wrong-backup-password-2026!'), 'Wrong backup password');
$archive[40] = chr(ord($archive[40]) ^ 1);
rejected(fn () => Backup::open($archive, $backupPassword), 'Tampered backup rejected');
rejected(fn () => Backup::seal($plain, 'short'), 'Weak backup password');
rejected(fn () => Backup::open('QOTPBA01' . str_repeat('x', 64), $backupPassword), 'Go backup is not accepted as PHP backup');

foreach (['127.0.0.1', '10.0.0.1', '172.16.0.2', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1', '192.0.2.10', '2001:db8::1'] as $ip) {
    check(!Network::publicIp($ip), 'Restricted IP rejected: ' . $ip);
}
check(Network::publicIp('8.8.8.8') && Network::publicIp('2606:4700:4700::1111'), 'Public IPv4 and IPv6 accepted');
check(Validation::ip('::ffff:192.168.1.1') === '192.168.1.1', 'Mapped IPv6 canonicalized');
rejected(fn () => Network::resolve('localhost'), 'Local host rejected');
rejected(fn () => Imap::quote("invalid\r\nQ2 LOGOUT"), 'IMAP command injection rejected');
check(Imap::quote('a"b\\c') === '"a\\"b\\\\c"', 'IMAP quoted values escaped');

foreach (Validation::GENERATORS as $type) {
    for ($i = 0; $i < 10; $i++) {
        $local = Generator::local($type, 'Đặng Văn Ánh');
        check(preg_match('/^[a-z0-9]{1,64}$/D', $local) === 1, 'Valid generated local part');
        if ($type === 'random_crypto') {
            check(strlen($local) === 16, 'Crypto username length');
        }
    }
}
check(Generator::clean('Đặng Văn Ánh') === 'dangvananh', 'Vietnamese prefix transliteration');
rejected(fn () => Generator::local('unsupported'), 'Unknown generator rejected');
rejected(fn () => Generator::local('custom_prefix', '!!!'), 'Invalid prefix rejected');
rejected(fn () => Validation::email('a@example.com.evil@target.com'), 'Invalid email rejected');
$settings = Settings::defaults();
$settings['default_domains'] = ['example.com', 'example.org'];
rejected(fn () => Settings::validate($settings), 'Default domain must be saved');

$raw = "From: Example Sender <sender@example.org>\r\nTo: target@example.com\r\nSubject: Verification code\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nYour verification code is 123456.";
$incoming = Imap::parse($raw, 'target@example.com');
check($incoming !== null && $incoming['otp'] === '123456' && str_contains($incoming['body_text'], '123456'), 'Text mail and OTP');
check($incoming['sender'] === 'Example Sender <sender@example.org>', 'Sender name and email');
$encodedSender = str_replace('Example Sender', '=?UTF-8?B?TcOjIHjDoWMgbmjhuq1u?=', $raw);
check(Imap::parse($encodedSender, 'target@example.com')['sender'] === 'Mã xác nhận <sender@example.org>', 'Encoded sender decoded');
check(Imap::parse($raw, 'other@example.com') === null, 'Wrong recipient mail rejected');
check(Imap::parse(str_replace('target@example.com', 'pretarget@example.com', $raw), 'target@example.com') === null, 'Substring recipient rejected');
check(Imap::parse(str_replace('To: target@example.com', 'To: "target@example.com" <different@example.com>', $raw), 'target@example.com') === null, 'Display name must not count as recipient');
$forwarded = str_replace('To: target@example.com', "To: mailbox@example.com\r\nX-Original-To: target@example.com", $raw);
check(Imap::parse($forwarded, 'target@example.com') !== null, 'Original recipient header supported');
$mime = "From: sender@example.org\r\nTo: target@example.com\r\nSubject: =?UTF-8?B?TcOjIHjDoWMgbmjhuq1u?=\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=bound\r\n\r\n--bound\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nVerification code: 654321 caf=E9\r\n--bound\r\nContent-Type: text/plain\r\nContent-Disposition: attachment; filename=private.txt\r\n\r\nAttachment secret 999999\r\n--bound--\r\n";
$incoming = Imap::parse($mime, 'target@example.com');
check($incoming !== null && $incoming['otp'] === '654321' && str_contains($incoming['body_text'], 'café') && !str_contains($incoming['body_text'], 'Attachment secret'), 'MIME charset, transfer encoding, attachment exclusion');
$html = str_replace('text/plain; charset=UTF-8', 'text/html; charset=UTF-8', $raw);
$html = str_replace('Your verification code is 123456.', '<style>hidden</style><script>bad()</script><p>Your code: <b>334455</b></p><img src="https://example.org/tracker">', $html);
$incoming = Imap::parse($html, 'target@example.com');
check($incoming !== null && $incoming['otp'] === '334455' && !str_contains($incoming['body_text'], '<') && !str_contains($incoming['body_text'], 'bad()'), 'HTML sanitized to plain text');
rejected(fn () => Imap::parse(str_repeat('a', Imap::MAX_MESSAGE + 1), 'target@example.com'), 'Oversized message rejected');

if (function_exists('pcntl_fork') && function_exists('stream_socket_pair')) {
    function fakeImap(array $responses, callable $action, bool $oversize = false): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($pair[0]);
            fwrite($pair[1], "* OK Fixture server\r\n");
            foreach ($responses as $expected => $response) {
                $command = fgets($pair[1]);
                if ($command === false || !str_contains($command, $expected) || (str_contains($command, 'FETCH') && str_contains($command, 'BODY['))) {
                    exit(1);
                }
                fwrite($pair[1], $response);
            }
            fclose($pair[1]);
            exit(0);
        }
        fclose($pair[1]);
        try {
            $imap = new Imap(['host' => 'imap.example.com', 'port' => 993, 'username' => 'fixture', 'password' => 'synthetic', 'folder' => 'INBOX'], fn () => $pair[0]);
            $action($imap);
        } finally {
            if (is_resource($pair[0])) {
                fclose($pair[0]);
            }
            pcntl_waitpid($pid, $status);
            check(pcntl_wexitstatus($status) === 0, 'IMAP command sequence and read-only BODY.PEEK verified');
        }
    }
    fakeImap([
        'LOGIN' => "Q1 OK Login\r\n",
        'EXAMINE "INBOX"' => "* 1 EXISTS\r\n* OK [UIDVALIDITY 100] UIDs\r\nQ2 OK Read only\r\n",
        'FETCH 1:1' => "* 1 FETCH (UID 18 FLAGS () RFC822.SIZE 100 BODY[HEADER.FIELDS (TO)] {" . strlen("To: different@example.com\r\n\r\n") . "}\r\nTo: different@example.com\r\n\r\n)\r\nQ3 OK Headers\r\n",
        'UID SEARCH UNDELETED' => "* SEARCH 17\r\nQ4 OK Search\r\n",
        'UID FETCH 17 (UID RFC822.SIZE)' => '* 1 FETCH (UID 17 RFC822.SIZE ' . strlen($raw) . ")\r\nQ5 OK Fetch\r\n",
        'BODY.PEEK[]' => '* 1 FETCH (UID 17 INTERNALDATE "08-Oct-2026 08:00:00 +0700" BODY[] {' . strlen($raw) . "}\r\n" . $raw . ")\r\nQ6 OK Fetch\r\n",
    ], function (Imap $imap): void {
        $mail = $imap->latest('target@example.com');
        check($mail !== null && $mail['otp'] === '123456' && $mail['received_at'] === '2026-10-08T01:00:00.000000Z' && strlen($mail['source_key']) === 64, 'IMAP full message fetch, UTC date and dedupe key');
    });
    $targetHeader = "To: target@example.com\r\n\r\n";
    $wrongHeader = "To: \"target@example.com\" <different@example.com>\r\n\r\n";
    fakeImap([
        'LOGIN' => "Q1 OK Login\r\n",
        'EXAMINE' => "* 3 EXISTS\r\n* OK [UIDVALIDITY 100] Valid\r\nQ2 OK Open\r\n",
        'FETCH 1:3' => '* 1 FETCH (BODY[HEADER.FIELDS (TO)] {' . strlen($targetHeader) . "}\r\n" . $targetHeader . ' UID 17 FLAGS () RFC822.SIZE ' . strlen($raw) . ")\r\n"
            . '* 2 FETCH (UID 18 FLAGS (\\Deleted) RFC822.SIZE 99999999 BODY[HEADER.FIELDS (TO)] {' . strlen($targetHeader) . "}\r\n" . $targetHeader . ")\r\n"
            . '* 3 FETCH (UID 19 FLAGS () RFC822.SIZE 99999999 BODY[HEADER.FIELDS (TO)] {' . strlen($wrongHeader) . "}\r\n" . $wrongHeader . ")\r\nQ3 OK Headers\r\n",
        'BODY.PEEK[]' => '* 1 FETCH (UID 17 INTERNALDATE "08-Oct-2026 08:00:00 +0700" BODY[] {' . strlen($raw) . "}\r\n" . $raw . ")\r\nQ4 OK Fetch\r\n",
    ], function (Imap $imap): void {
        check($imap->latest('target@example.com')['otp'] === '123456', 'New mail fetched without SEARCH; deleted and display-name-only recipients skipped; UID after literal supported');
    });
    $latestRaw = str_replace('123456', '654321', $raw);
    fakeImap([
        'LOGIN' => "Q1 OK Login\r\n",
        'EXAMINE' => "* 2 EXISTS\r\nQ2 OK Open\r\n",
        'FETCH 1:2' => '* 2 FETCH (UID 18 FLAGS () RFC822.SIZE ' . strlen($latestRaw) . ' BODY[HEADER.FIELDS (TO)] {' . strlen($targetHeader) . "}\r\n" . $targetHeader . ")\r\n"
            . '* 1 FETCH (UID 17 FLAGS () RFC822.SIZE ' . strlen($raw) . ' BODY[HEADER.FIELDS (TO)] {' . strlen($targetHeader) . "}\r\n" . $targetHeader . ")\r\nQ3 OK Headers\r\n",
        'UID FETCH 18 (UID INTERNALDATE BODY.PEEK[]' => '* 2 FETCH (UID 18 INTERNALDATE "08-Oct-2026 08:00:00 +0700" BODY[] {' . strlen($latestRaw) . "}\r\n" . $latestRaw . ")\r\nQ4 OK Fetch\r\n",
    ], function (Imap $imap): void {
        check($imap->latest('target@example.com')['otp'] === '654321', 'Latest OTP selected even when recent headers arrive out of order');
    });
    fakeImap(['LOGIN' => "Q1 OK Login\r\n", 'EXAMINE' => "* 1 EXISTS\r\nQ2 OK Open\r\n", 'FETCH 1:1' => "* 1 FETCH (UID 17 BODY[HEADER.FIELDS (TO)] {999999999}\r\n"], function (Imap $imap): void {
        rejected(fn () => $imap->latest('target@example.com'), 'Unbounded server literal rejected before read');
    });
}

check(System::newer('1.0.10', '1.0.9') && !System::newer('1.0.0', '1.0.0') && !System::newer('1.0.0-rc1', '1.0.0'), 'Stable PHP version comparison');
check(System::newer('1.0.0-beta_2', '1.0.0-beta_1') && System::newer('1.0.0-beta_10', '1.0.0-beta_2'), 'Beta versions compare numerically');
check(System::newer('1.0.0', '1.0.0-beta_1') && !System::newer('1.0.0-beta_2', '1.0.0'), 'Stable release supersedes beta');
foreach (['1.0.0-beta_0', '1.0.0-beta_01', '1.0.0-beta_1/../', '1.0.0-beta_1+build', 'v1.0.0-beta_1'] as $invalidVersion) {
    check(!System::validVersion($invalidVersion), 'Invalid beta identifiers rejected');
}
check(System::release([['tag_name' => 'v9.9.9', 'draft' => false, 'prerelease' => false, 'assets' => []]]) === null, 'Release without PHP assets excluded');
$assetName = 'Quick-Otp-Email-Check-PHP_v1.0.0.zip';
$assetBase = 'https://github.com/' . System::REPOSITORY . '/releases/download/v1.0.0/';
$release = ['tag_name' => 'v1.0.0', 'draft' => false, 'prerelease' => false, 'assets' => [
    ['name' => $assetName, 'browser_download_url' => $assetBase . $assetName, 'size' => 1024],
    ['name' => 'checksums-php.txt', 'browser_download_url' => $assetBase . 'checksums-php.txt'],
]];
check(System::release([$release])['download_url'] === $assetBase . $assetName, 'Standalone repository download selected');
check(System::release([$release])['release_url'] === 'https://github.com/' . System::REPOSITORY . '/releases/tag/v1.0.0', 'Standalone release page selected');
$betaRelease = $release;
$betaRelease['tag_name'] = 'v1.0.0-beta_2';
$betaRelease['prerelease'] = true;
foreach ($betaRelease['assets'] as &$item) {
    $item['name'] = str_replace('v1.0.0.zip', 'v1.0.0-beta_2.zip', $item['name']);
    $item['browser_download_url'] = str_replace('v1.0.0/', 'v1.0.0-beta_2/', $item['browser_download_url']);
    $item['browser_download_url'] = str_replace('v1.0.0.zip', 'v1.0.0-beta_2.zip', $item['browser_download_url']);
}
unset($item);
check(System::release([$betaRelease], '1.0.0-beta_1')['number'] === '1.0.0-beta_2', 'Beta clients discover beta releases');
check(System::release([$betaRelease], '1.0.0') === null, 'Stable clients ignore beta releases');
check(System::release([$betaRelease, $release], '1.0.0-beta_1')['number'] === '1.0.0', 'Stable release wins over a beta of the same version');
$betaRelease['prerelease'] = false;
check(System::release([$betaRelease], '1.0.0-beta_1') === null, 'Beta tag must be marked as a prerelease');
$release['assets'][0]['browser_download_url'] = str_replace(System::REPOSITORY, 'hgn389/Quick-Otp-Email-Check', $release['assets'][0]['browser_download_url']);
check(System::release([$release]) === null, 'Assets from old repository rejected');

check(System::newer('1.0.0-beta-2', '1.0.0-beta_1'), 'Hyphen beta accepts legacy underscore version');
check(System::newer('1.0.0-beta-10', '1.0.0-beta-2'), 'Hyphen beta versions compare numerically');
check(!System::newer('1.0.0-beta_2', '1.0.0-beta-2'), 'Equivalent beta spellings do not trigger updates');
foreach (['1.0.0-beta-0', '1.0.0-beta-01', '1.0.0-beta-2/../'] as $invalidVersion) {
    check(!System::validVersion($invalidVersion), 'Invalid hyphen beta is rejected');
}
check(!\QuickOtp\UpdateRecovery::allowed('quickotp-private/install-password.php'), 'Updates cannot replace the installation secret');
check(\QuickOtp\UpdateRecovery::allowed('quickotp-private/install-password.example.php'), 'Updates can include the empty installation template');
$hyphenRelease = json_decode(str_replace('beta_2', 'beta-2', json_encode($betaRelease)), true);
$hyphenRelease['prerelease'] = true;
check(System::release([$hyphenRelease], '1.0.0-beta_1')['number'] === '1.0.0-beta-2', 'Current parser discovers hyphen releases from legacy version identifiers');
check(System::release([$hyphenRelease], '1.0.0') === null, 'Stable clients still skip hyphen beta releases');

echo "PASS: $checks PHP crypto, validation, generator, MIME, IMAP and update checks.\n";
