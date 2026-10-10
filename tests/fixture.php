<?php
declare(strict_types=1);

$site = $argv[1];
require $site . '/quickotp-private/bootstrap.php';
$action = $argv[2];
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$config = $action === 'reset-database' ? $input : require $site . '/quickotp-private/config.php';
if (!str_starts_with($config['db_name'], 'quickotp_test_')) {
    throw new RuntimeException('Fixtures may only use a test database');
}
$db = new \QuickOtp\Database($config);
switch ($action) {
    case 'reset-database':
        if (getenv('QUICKOTP_TEST_RESET_DATABASE') !== '1') {
            throw new RuntimeException('Explicit test reset flag required');
        }
        foreach (['sessions', 'reauth_failures', 'messages', 'mail_config', 'generated_emails', 'app_settings', 'blocked_ips', 'login_failures', 'users', 'meta'] as $table) {
            $db->query('DROP TABLE IF EXISTS qotp_' . $table);
        }
        break;
    case 'seed':
        foreach (['target@example.com' => '123456', 'other@example.com' => '998877'] as $recipient => $otp) {
            $db->query('INSERT INTO qotp_messages(source_key,recipient,sender,subject,body_text,otp,received_at) VALUES (?,?,?,?,?,?,?)', [hash('sha256', $recipient), $recipient, 'sender@example.org', 'Mã xác nhận', '<script>fixture</script> Mã xác nhận: ' . $otp, $otp, \QuickOtp\now()]);
        }
        $db->query('INSERT INTO qotp_blocked_ips(ip_address,created_at) VALUES (?,?)', ['203.0.113.8', \QuickOtp\now()]);
        break;
    case 'legacy-domain-settings':
        $db->query('UPDATE qotp_app_settings SET default_domain=?,default_domains=? WHERE id=1', ['example.com', json_encode(['example.com'], JSON_THROW_ON_ERROR)]);
        break;
    case 'expire-session':
        $db->query('UPDATE qotp_sessions SET expires_at=? WHERE token_hash=?', [\QuickOtp\now(), hash('sha256', $input['token'])]);
        break;
    default:
        throw new RuntimeException('Unknown fixture action');
}
