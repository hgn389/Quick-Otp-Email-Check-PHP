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
    case 'rotate-key':
        $old = new \QuickOtp\Secrets(base64_decode($config['app_key'], true));
        $newKey = random_bytes(32);
        $new = new \QuickOtp\Secrets($newKey);
        $mail = $db->one('SELECT password_encrypted FROM qotp_mail_config WHERE id=1');
        $db->query('UPDATE qotp_mail_config SET password_encrypted=? WHERE id=1', [$new->encrypt($old->decrypt($mail['password_encrypted']))]);
        $config['app_key'] = base64_encode($newKey);
        file_put_contents($site . '/quickotp-private/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
        break;
    case 'check-mail':
        $mail = $db->one('SELECT password_encrypted FROM qotp_mail_config WHERE id=1');
        $secrets = new \QuickOtp\Secrets(base64_decode($config['app_key'], true));
        echo json_encode(['valid' => $secrets->decrypt($mail['password_encrypted']) === $input['password'], 'encrypted' => !str_contains($mail['password_encrypted'], $input['password']), 'blocked' => $db->one('SELECT * FROM qotp_blocked_ips WHERE ip_address=?', ['203.0.113.9']) !== null]);
        break;
    case 'block-extra':
        $db->query('INSERT INTO qotp_blocked_ips(ip_address,created_at) VALUES (?,?)', ['203.0.113.9', \QuickOtp\now()]);
        break;
    case 'mutate-backup':
        $data = \QuickOtp\Backup::open(base64_decode($input['backup']), $input['password']);
        if ($input['mutation'] === 'id') {
            $data['tables']['users'][0]['id'] = 0;
        } elseif ($input['mutation'] === 'table') {
            $data['tables']['messages;DROP TABLE qotp_users'] = [];
        } elseif ($input['mutation'] === 'column') {
            $data['tables']['users'][0]['arbitrary_sql'] = 'DROP TABLE qotp_users';
        } elseif ($input['mutation'] === 'email') {
            $data['tables']['generated_emails'][0]['email'] = 'wrong@example.com';
        } elseif ($input['mutation'] === 'time') {
            $data['tables']['users'][0]['created_at'] = '2026-99-99T25:70:90Z';
        }
        echo base64_encode(\QuickOtp\Backup::seal(json_encode($data, JSON_THROW_ON_ERROR), $input['password']));
        break;
    case 'expire-session':
        $db->query('UPDATE qotp_sessions SET expires_at=? WHERE token_hash=?', [\QuickOtp\now(), hash('sha256', $input['token'])]);
        break;
    default:
        throw new RuntimeException('Unknown fixture action');
}
