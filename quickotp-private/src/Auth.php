<?php
declare(strict_types=1);

namespace QuickOtp;

use DateTimeImmutable;
use DateTimeZone;

final class Auth
{
    public const COOKIE = 'quickotp_php_session';

    public function __construct(private readonly Database $db, private readonly string $dummyHash)
    {
    }

    public function session(): ?array
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        return $this->db->one('SELECT s.*,u.username,u.password_hash,u.must_change_password FROM qotp_sessions s JOIN qotp_users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>?', [hash('sha256', $token), now()]);
    }

    public function requireSession(bool $api): array
    {
        $session = $this->session();
        if ($session === null) {
            if (!$api) {
                Http::redirect('/login.html');
            }
            throw new HttpError(401, 'authentication required');
        }
        return $session;
    }

    public function requireCsrf(array $session): void
    {
        if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        Http::sameOrigin();
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($provided === '' || !hash_equals($session['csrf_token'], $provided)) {
            throw new HttpError(403, 'invalid CSRF token');
        }
    }

    private function createSession(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('+12 hours', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $this->db->query('DELETE FROM qotp_sessions WHERE expires_at<=?', [now()]);
        $this->db->query('INSERT INTO qotp_sessions(token_hash,user_id,csrf_token,created_at,expires_at) VALUES (?,?,?,?,?)', [hash('sha256', $token), $userId, bin2hex(random_bytes(32)), now(), $expires]);
        return $token;
    }

    private function setCookie(string $token): void
    {
        setcookie(self::COOKIE, $token, ['expires' => time() + 43200, 'path' => '/', 'secure' => Http::secure(), 'httponly' => true, 'samesite' => 'Strict']);
    }

    public function clearCookie(): void
    {
        setcookie(self::COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => Http::secure(), 'httponly' => true, 'samesite' => 'Strict']);
    }

    public function login(array $input): array
    {
        Http::sameOrigin();
        $username = trim(Validation::text($input, 'username', 64));
        $password = Validation::text($input, 'password', 72);
        $ip = Validation::ip($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $result = $this->db->transaction(function () use ($username, $password, $ip): array {
            $this->db->query('INSERT INTO qotp_login_failures(ip_address,attempts,updated_at) VALUES (?,0,?) ON DUPLICATE KEY UPDATE ip_address=VALUES(ip_address)', [$ip, now()]);
            $failure = $this->db->one('SELECT * FROM qotp_login_failures WHERE ip_address=? FOR UPDATE', [$ip]);
            if ($this->db->one('SELECT ip_address FROM qotp_blocked_ips WHERE ip_address=?', [$ip])) {
                return ['error' => 'this IP address is permanently blocked', 'status' => 403];
            }
            $user = $this->db->one('SELECT * FROM qotp_users WHERE username=?', [$username]);
            $valid = !str_contains($password, "\0") && password_verify($password, $user['password_hash'] ?? $this->dummyHash);
            if ($user === null || !$valid) {
                $attempts = (int) $failure['attempts'] + 1;
                $this->db->query('UPDATE qotp_login_failures SET attempts=?,updated_at=? WHERE ip_address=?', [$attempts, now(), $ip]);
                if ($attempts >= 5) {
                    $this->db->query('INSERT INTO qotp_blocked_ips(ip_address,created_at) VALUES (?,?) ON DUPLICATE KEY UPDATE ip_address=VALUES(ip_address)', [$ip, now()]);
                }
                return ['error' => $attempts >= 5 ? 'this IP address is permanently blocked' : 'invalid username or password', 'status' => $attempts >= 5 ? 403 : 401];
            }
            $this->db->query('UPDATE qotp_login_failures SET attempts=0,updated_at=? WHERE ip_address=?', [now(), $ip]);
            $this->db->query('UPDATE qotp_users SET last_login_at=? WHERE id=?', [now(), $user['id']]);
            return ['token' => $this->createSession((int) $user['id']), 'username' => $user['username'], 'must_change_password' => (bool) $user['must_change_password']];
        });
        if (isset($result['error'])) {
            throw new HttpError($result['status'], $result['error']);
        }
        $this->setCookie($result['token']);
        unset($result['token']);
        return $result;
    }

    public function logout(array $session): void
    {
        $this->db->query('DELETE FROM qotp_sessions WHERE token_hash=?', [$session['token_hash']]);
        $this->clearCookie();
    }

    public function verifyCurrent(array $session, string $password): void
    {
        $status = $this->db->transaction(function () use ($session, $password): int {
            $this->db->query("INSERT INTO qotp_reauth_failures(user_id,attempts,blocked_until) VALUES (?,0,'') ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)", [$session['user_id']]);
            $failure = $this->db->one('SELECT * FROM qotp_reauth_failures WHERE user_id=? FOR UPDATE', [$session['user_id']]);
            if ($failure['blocked_until'] !== '' && $failure['blocked_until'] > now()) {
                return 429;
            }
            $user = $this->db->one('SELECT password_hash FROM qotp_users WHERE id=?', [$session['user_id']]);
            if ($user && strlen($password) <= 72 && !str_contains($password, "\0") && password_verify($password, $user['password_hash'])) {
                $this->db->query('UPDATE qotp_reauth_failures SET attempts=0,blocked_until=\'\' WHERE user_id=?', [$session['user_id']]);
                return 200;
            }
            $attempts = ($failure['blocked_until'] !== '' ? 0 : (int) $failure['attempts']) + 1;
            $until = $attempts >= 5 ? (new DateTimeImmutable('+15 minutes', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z') : '';
            $this->db->query('UPDATE qotp_reauth_failures SET attempts=?,blocked_until=? WHERE user_id=?', [$attempts, $until, $session['user_id']]);
            return $attempts >= 5 ? 429 : 401;
        });
        if ($status !== 200) {
            if ($status === 429) {
                header('Retry-After: 900');
            }
            throw new HttpError($status, $status === 429 ? 'Quá nhiều lần nhập sai. Vui lòng thử lại sau 15 phút.' : 'current password is incorrect');
        }
    }

    private function ensureProfiles(): void
    {
        // Additive storage keeps existing installations and update manifests compatible.
        if (!$this->db->one("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qotp_user_profiles'")) {
            $this->db->query("CREATE TABLE IF NOT EXISTS qotp_user_profiles (
                user_id BIGINT UNSIGNED PRIMARY KEY,
                full_name VARCHAR(100) NOT NULL DEFAULT '', email VARCHAR(254) NOT NULL DEFAULT '',
                telegram_contact VARCHAR(256) NOT NULL DEFAULT '', updated_at VARCHAR(27) CHARACTER SET ascii NOT NULL,
                FOREIGN KEY (user_id) REFERENCES qotp_users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }

    public static function validateProfile(array $input): array
    {
        $profile = [];
        foreach (['full_name' => 100, 'email' => 254, 'telegram_contact' => 256] as $key => $limit) {
            $value = trim(Validation::text($input, $key, $limit * 4));
            if (mb_strlen($value) > $limit || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
                throw new HttpError(400, 'Thông tin tài khoản không hợp lệ.');
            }
            $profile[$key] = $value;
        }
        if ($profile['email'] !== '' && (strlen($profile['email']) > 254 || !filter_var($profile['email'], FILTER_VALIDATE_EMAIL))) {
            throw new HttpError(400, 'Nhập địa chỉ email hợp lệ.');
        }
        if ($profile['telegram_contact'] !== '' && !preg_match('~^(?:@|https://t\.me/)[A-Za-z0-9_]{5,32}$~D', $profile['telegram_contact'])) {
            throw new HttpError(400, 'Telegram cần có dạng @username hoặc https://t.me/username.');
        }
        return $profile;
    }

    public function profile(array $session): array
    {
        $this->ensureProfiles();
        $row = $this->db->one('SELECT full_name,email,telegram_contact FROM qotp_user_profiles WHERE user_id=?', [$session['user_id']]);
        return ['username' => $session['username']] + ($row ?? ['full_name' => '', 'email' => '', 'telegram_contact' => '']);
    }

    public function saveProfile(array $session, array $input): array
    {
        $profile = self::validateProfile($input);
        $this->ensureProfiles();
        $this->db->query('INSERT INTO qotp_user_profiles(user_id,full_name,email,telegram_contact,updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),email=VALUES(email),telegram_contact=VALUES(telegram_contact),updated_at=VALUES(updated_at)', [$session['user_id'], $profile['full_name'], $profile['email'], $profile['telegram_contact'], now()]);
        return ['username' => $session['username']] + $profile;
    }

    public function changePassword(array $session, array $input): array
    {
        $current = Validation::text($input, 'current_password', 72);
        $next = Validation::text($input, 'new_password', 72);
        Validation::password($next);
        if ($current === $next) {
            throw new HttpError(400, 'new password must be different from the current password');
        }
        $this->verifyCurrent($session, $current);
        $hash = password_hash($next, PASSWORD_BCRYPT, ['cost' => 12]);
        $token = $this->db->transaction(function () use ($session, $hash): string {
            $user = $this->db->one('SELECT password_hash FROM qotp_users WHERE id=? FOR UPDATE', [$session['user_id']]);
            if (!$user || !hash_equals($session['password_hash'], $user['password_hash'])) {
                throw new HttpError(409, 'Mật khẩu vừa được thay đổi. Hãy đăng nhập lại.');
            }
            $this->db->query('UPDATE qotp_users SET password_hash=?,must_change_password=0,updated_at=? WHERE id=?', [$hash, now(), $session['user_id']]);
            $this->db->query('DELETE FROM qotp_sessions WHERE user_id=?', [$session['user_id']]);
            return $this->createSession((int) $session['user_id']);
        });
        $this->setCookie($token);
        return ['status' => 'password changed'];
    }
}
