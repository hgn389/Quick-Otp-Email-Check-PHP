<?php
declare(strict_types=1);

namespace QuickOtp;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use ZBateson\MailMimeParser\Message;
use ZBateson\MailMimeParser\Header\AddressHeader;

final class Network
{
    public static function publicIp(string $value): bool
    {
        try {
            $value = Validation::ip($value);
        } catch (HttpError) {
            return false;
        }
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    public static function resolve(string $host): array
    {
        Validation::domain($host);
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? '';
            if ($ip !== '' && !self::publicIp($ip)) {
                throw new RuntimeException('Host IMAP trỏ đến địa chỉ nội bộ hoặc địa chỉ bị hạn chế.');
            }
            if ($ip !== '') {
                $ips[] = Validation::ip($ip);
            }
        }
        if (!$ips) {
            throw new RuntimeException('Không phân giải được host IMAP.');
        }
        return array_values(array_unique($ips));
    }

    public static function tls(string $host, int $port)
    {
        $ips = self::resolve($host);
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'peer_name' => $host, 'SNI_enabled' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]);
        // Dial the resolved address and verify the original hostname to prevent DNS rebinding.
        foreach (array_slice($ips, 0, 3) as $ip) {
            $address = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $connection = @stream_socket_client("tls://$address:$port", $errorNumber, $errorMessage, 5, STREAM_CLIENT_CONNECT, $context);
            if ($connection !== false) {
                return $connection;
            }
        }
        throw new RuntimeException('Không kết nối được IMAP qua TLS. Kiểm tra host, port và chứng chỉ.');
    }
}

final class Imap
{
    public const MAX_MESSAGE = 2097152;
    public const RECIPIENT_HEADERS = ['To', 'Cc', 'Bcc', 'Delivered-To', 'X-Original-To', 'Envelope-To', 'X-Envelope-To', 'X-Forwarded-To'];
    private $stream;
    private float $deadline;
    private int $sequence = 0;
    private int $remaining = 16777216;
    private int $uidValidity = 0;
    private int $messages = 0;

    public function __construct(private readonly array $config, ?Closure $connector = null)
    {
        $this->deadline = microtime(true) + 20;
        $this->stream = ($connector ?? Network::tls(...))($config['host'], $config['port']);
        if (!is_resource($this->stream)) {
            throw new RuntimeException('Kết nối IMAP không hợp lệ.');
        }
        $greeting = $this->line();
        if (!preg_match('/^\* OK\b/i', $greeting)) {
            throw new RuntimeException('Máy chủ IMAP chưa sẵn sàng.');
        }
        $this->command('LOGIN ' . self::quote($config['username']) . ' ' . self::quote($config['password']), 'IMAP từ chối đăng nhập; kiểm tra App Password và quyền truy cập IMAP.');
        $folder = mb_convert_encoding($config['folder'], 'UTF7-IMAP', 'UTF-8');
        $result = $this->command('EXAMINE ' . self::quote($folder), 'Không mở được folder IMAP.');
        if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $result['text'], $match)) {
            $this->uidValidity = (int) $match[1];
        }
        if (preg_match('/\* (\d+) EXISTS\b/i', $result['text'], $match)) {
            $this->messages = (int) $match[1];
        }
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public static function quote(string $value): string
    {
        if (preg_match('/[\x00\r\n]/', $value)) {
            throw new RuntimeException('Tham số IMAP không hợp lệ.');
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function timeout(): void
    {
        $left = $this->deadline - microtime(true);
        if ($left <= 0 || $this->remaining < 1) {
            throw new RuntimeException('IMAP vượt giới hạn thời gian hoặc dung lượng.');
        }
        stream_set_timeout($this->stream, (int) $left, (int) (($left - floor($left)) * 1000000));
    }

    private function line(): string
    {
        $this->timeout();
        $line = fgets($this->stream, 65538);
        if ($line === false || !str_ends_with($line, "\r\n") || strlen($line) > 65536) {
            throw new RuntimeException('Phản hồi IMAP không hợp lệ hoặc đã hết thời gian.');
        }
        $this->remaining -= strlen($line);
        return $line;
    }

    private function literal(int $size): string
    {
        if ($size < 0 || $size > self::MAX_MESSAGE || $size > $this->remaining) {
            throw new RuntimeException('Thư hoặc phản hồi IMAP vượt giới hạn 2 MiB.');
        }
        $result = '';
        while (strlen($result) < $size) {
            $this->timeout();
            $part = fread($this->stream, min(65536, $size - strlen($result)));
            if ($part === false || $part === '') {
                throw new RuntimeException('Không tải được nội dung thư IMAP.');
            }
            $result .= $part;
            $this->remaining -= strlen($part);
        }
        return $result;
    }

    private function command(string $command, string $error): array
    {
        $this->timeout();
        $tag = 'Q' . (++$this->sequence);
        $request = $tag . ' ' . $command . "\r\n";
        $offset = 0;
        while ($offset < strlen($request)) {
            $this->timeout();
            $written = fwrite($this->stream, substr($request, $offset));
            if (!$written) {
                throw new RuntimeException('Không gửi được yêu cầu IMAP.');
            }
            $offset += $written;
        }
        $text = '';
        $literals = [];
        $fetches = [];
        $fetchIndex = null;
        for ($i = 0; $i < 10000; $i++) {
            $line = $this->line();
            $text .= $line;
            if (preg_match('/^\* [0-9]+ FETCH \(/i', $line)) {
                $fetches[] = ['text' => '', 'literals' => []];
                $fetchIndex = array_key_last($fetches);
            } elseif (str_starts_with($line, '* ') || str_starts_with($line, $tag . ' ')) {
                $fetchIndex = null;
            }
            if ($fetchIndex !== null) {
                $fetches[$fetchIndex]['text'] .= $line;
            }
            if (preg_match('/\{(\d+)\+?\}\r\n$/D', $line, $match)) {
                if (count($literals) >= 10 || strlen($match[1]) > 8) {
                    throw new RuntimeException('Phản hồi IMAP vượt giới hạn.');
                }
                $literal = $this->literal((int) $match[1]);
                $literals[] = $literal;
                if ($fetchIndex !== null) {
                    $fetches[$fetchIndex]['literals'][] = $literal;
                }
            }
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . $tag . ' OK\b/i', $line)) {
                    throw new RuntimeException($error);
                }
                return compact('text', 'literals', 'fetches');
            }
            if (preg_match('/^\* BYE\b/i', $line)) {
                throw new RuntimeException('Máy chủ IMAP đã ngắt kết nối.');
            }
        }
        throw new RuntimeException('Phản hồi IMAP vượt giới hạn.');
    }

    public function check(): array
    {
        return ['status' => 'connected', 'folder' => $this->config['folder'], 'messages' => $this->messages, 'tested_at' => now()];
    }

    public function latest(string $email): ?array
    {
        $email = Validation::email($email);
        $checked = [];
        // New mail can be fetched before it appears in a provider's search results.
        $oldest = max(1, $this->messages - 29);
        for ($end = $this->messages; $end >= $oldest; $end -= 10) {
            $start = max($oldest, $end - 9);
            $headers = $this->command('FETCH ' . $start . ':' . $end . ' (UID FLAGS RFC822.SIZE BODY.PEEK[HEADER.FIELDS (' . implode(' ', self::RECIPIENT_HEADERS) . ')]<0.65536>)', 'Không đọc được header thư mới.');
            $candidates = [];
            foreach ($headers['fetches'] as $fetch) {
                if (count($fetch['literals']) !== 1 || strlen($fetch['literals'][0]) > 65536
                    || !preg_match('/\bUID ([0-9]{1,10})\b/i', $fetch['text'], $uidMatch)
                    || !preg_match('/\bRFC822\.SIZE ([0-9]+)\b/i', $fetch['text'], $sizeMatch)
                    || !preg_match('/\bFLAGS \(([^)]*)\)/i', $fetch['text'], $flagsMatch)) {
                    continue;
                }
                $uid = (int) $uidMatch[1];
                $flags = preg_split('/\s+/', strtolower(trim($flagsMatch[1]))) ?: [];
                if ($uid < 1 || $uid > 4294967295 || in_array('\\deleted', $flags, true)) {
                    continue;
                }
                $header = $fetch['literals'][0];
                if (!str_ends_with($header, "\r\n\r\n") || self::parse($header, $email) === null) {
                    continue;
                }
                $candidates[$uid] = (int) $sizeMatch[1];
            }
            krsort($candidates, SORT_NUMERIC);
            foreach ($candidates as $uid => $size) {
                $checked[$uid] = true;
                $incoming = $this->readMessage($uid, $size, $email);
                if ($incoming !== null) {
                    return $incoming;
                }
            }
        }
        $criteria = '';
        foreach (array_reverse(self::RECIPIENT_HEADERS) as $name) {
            $item = 'HEADER ' . $name . ' ' . self::quote($email);
            $criteria = $criteria === '' ? $item : 'OR ' . $item . ' ' . $criteria;
        }
        $search = $this->command('UID SEARCH UNDELETED ' . $criteria, 'Không tìm kiếm được thư IMAP.');
        $uids = [];
        if (preg_match('/^\* SEARCH((?: \d+)*)\r?$/m', $search['text'], $match)) {
            $uids = array_filter(array_map('intval', explode(' ', trim($match[1]))));
        }
        rsort($uids, SORT_NUMERIC);
        foreach (array_slice($uids, 0, 10) as $uid) {
            if (isset($checked[$uid])) {
                continue;
            }
            $metadata = $this->command('UID FETCH ' . $uid . ' (UID RFC822.SIZE)', 'Không đọc được thông tin thư IMAP.');
            if (!preg_match('/\bRFC822\.SIZE (\d+)\b/i', $metadata['text'], $size)) {
                continue;
            }
            $incoming = $this->readMessage($uid, (int) $size[1], $email);
            if ($incoming !== null) {
                return $incoming;
            }
        }
        return null;
    }

    private function readMessage(int $uid, int $size, string $email): ?array
    {
        if ($size > self::MAX_MESSAGE) {
            throw new RuntimeException('Thư vượt giới hạn 2 MiB; hãy xem thư này trên nhà cung cấp email.');
        }
        $message = $this->command('UID FETCH ' . $uid . ' (UID INTERNALDATE BODY.PEEK[]<0.' . self::MAX_MESSAGE . '>)', 'Không tải được nội dung thư IMAP.');
        foreach ($message['fetches'] as $fetch) {
            if (!preg_match('/\bUID ' . $uid . '\b/i', $fetch['text']) || count($fetch['literals']) !== 1) {
                continue;
            }
            $incoming = self::parse($fetch['literals'][0], $email);
            if ($incoming === null) {
                continue;
            }
            if (!preg_match('/\bINTERNALDATE "([^"]+)"/i', $fetch['text'], $match)) {
                throw new RuntimeException('Thư thiếu thời gian nhận.');
            }
            $date = DateTimeImmutable::createFromFormat('!d-M-Y H:i:s O', trim($match[1]));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
                throw new RuntimeException('Thời gian nhận thư không hợp lệ.');
            }
            $incoming['received_at'] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
            $incoming['source_key'] = hash('sha256', implode("\0", [$this->config['host'], $this->config['port'], strtolower($this->config['username']), $this->config['folder'], $this->uidValidity, $uid, $email]));
            return $incoming;
        }
        return null;
    }

    public static function parse(string $raw, string $email): ?array
    {
        if (strlen($raw) > self::MAX_MESSAGE) {
            throw new RuntimeException('Thư vượt giới hạn 2 MiB.');
        }
        $headerEnd = strpos($raw, "\r\n\r\n");
        if ($headerEnd === false || $headerEnd > 65536) {
            throw new RuntimeException('Header thư không hợp lệ.');
        }
        $message = Message::from($raw, false);
        $matched = false;
        foreach (self::RECIPIENT_HEADERS as $name) {
            for ($offset = 0; $offset < 20; $offset++) {
                $header = $message->getHeader($name, $offset);
                if ($header === null) {
                    break;
                }
                $addresses = $header instanceof AddressHeader ? $header : new AddressHeader($name, $header->getRawValue());
                foreach ($addresses->getAddresses() as $address) {
                    if (strtolower($address->getEmail()) === $email) {
                        $matched = true;
                    }
                }
            }
        }
        if (!$matched) {
            return null;
        }
        $text = $message->getTextContent() ?? '';
        if (trim($text) === '') {
            $html = $message->getHtmlContent() ?? '';
            $html = (string) preg_replace('~<(script|style|head)\b[^>]*>.*?</\1\s*>~is', '', $html);
            $html = (string) preg_replace('~<(?:br\b[^>]*|/p|/div|/li|/tr|/h[1-6])\s*>~i', "\n", $html);
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $body = mb_strcut(trim($text), 0, 262144, 'UTF-8');
        $subject = mb_strcut($message->getSubject() ?? '', 0, 8192, 'UTF-8');
        $from = $message->getHeader('From');
        $sender = '';
        if ($from instanceof AddressHeader) {
            $sender = implode(', ', array_map(static function ($address): string {
                $name = $address->getName();
                return $name !== '' ? $name . ' <' . $address->getEmail() . '>' : $address->getEmail();
            }, array_slice($from->getAddresses(), 0, 20)));
        }
        $sender = mb_strcut($sender, 0, 8192, 'UTF-8');
        $content = $subject . "\n" . $body;
        $otp = '';
        if (preg_match('/(?:otp|verification code|security code|confirmation code|passcode|your code|mã xác (?:minh|nhận)|mã otp|code)[^0-9]{0,60}([0-9]{4,8})(?:[^0-9]|$)/iu', $content, $match)
            || preg_match('/(?:^|[^0-9])([0-9]{6,8})(?:[^0-9]|$)/u', $content, $match)) {
            $otp = $match[1];
        }
        return ['recipient' => $email, 'sender' => $sender, 'subject' => $subject, 'body_text' => $body, 'otp' => $otp];
    }
}
