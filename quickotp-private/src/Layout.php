<?php
declare(strict_types=1);

namespace QuickOtp;

use RuntimeException;

final class Layout
{
    public static function publicDirectory(string $private): string
    {
        $parent = dirname($private);
        if (realpath($private) !== $private || basename($private) !== 'quickotp-private'
            || is_link($private) || is_link($parent)) {
            throw new RuntimeException('Invalid website directory');
        }
        $public = is_file($parent . '/index.php') ? $parent : $parent . '/public_html';
        if (realpath($public) !== $public || !is_file($public . '/index.php')
            || !is_file($public . '/install.php') || is_link($public)) {
            throw new RuntimeException('Invalid public directory');
        }
        if (PHP_SAPI !== 'cli' && realpath($_SERVER['DOCUMENT_ROOT'] ?? '') !== $public) {
            throw new RuntimeException('Website must use the public directory as its document root');
        }
        return $public;
    }

    public static function verifyProtection(string $private): void
    {
        $public = self::publicDirectory($private);
        if ($public !== dirname($private)) {
            return;
        }
        // Bind to this server, bypassing DNS, external proxies and redirects.
        // The two temporary files contain random test bytes, never configuration.
        $address = $_SERVER['SERVER_ADDR'] ?? (PHP_SAPI === 'cli-server' ? '127.0.0.1' : '');
        $port = filter_var($_SERVER['SERVER_PORT'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''));
        if (!filter_var($address, FILTER_VALIDATE_IP) || $port === false || !is_array($host)
            || !preg_match('/^(?:[A-Za-z0-9.-]+|\[[A-Fa-f0-9:]+\])$/D', $host['host'] ?? '')
            || isset($host['user']) || isset($host['pass']) || isset($host['path']) || isset($host['query']) || isset($host['fragment'])) {
            throw new HttpError(503, 'Không xác định được địa chỉ webserver để kiểm tra bảo vệ dữ liệu.');
        }
        $name = 'quickotp-probe-' . bin2hex(random_bytes(16)) . '.txt';
        $bytes = bin2hex(random_bytes(32));
        $paths = [$public . '/' . $name, $private . '/' . $name];
        try {
            foreach ($paths as $path) {
                $file = @fopen($path, 'x+b');
                if ($file === false) {
                    throw new HttpError(503, 'PHP user cần quyền ghi thư mục website và quickotp-private.');
                }
                try {
                    if (fwrite($file, $bytes) !== strlen($bytes) || !chmod($path, 0644)) {
                        throw new RuntimeException('Cannot create protection probe');
                    }
                } finally {
                    fclose($file);
                }
            }
            $hostname = $host['host'];
            $destination = str_contains($address, ':') ? '[' . $address . ']' : $address;
            $scheme = Http::secure() ? 'https' : 'http';
            $results = [];
            foreach (['/' . $name, '/quickotp-private/' . $name] as $path) {
                $curl = curl_init($scheme . '://' . $hostname . ':' . $port . $path);
                $response = '';
                curl_setopt_array($curl, [CURLOPT_RESOLVE => [$hostname . ':' . $port . ':' . $destination],
                    CURLOPT_HTTPHEADER => ['Host: ' . ($_SERVER['HTTP_HOST']), 'Cache-Control: no-cache'],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_PROXY => '',
                    CURLOPT_CONNECTTIMEOUT_MS => 1500, CURLOPT_TIMEOUT_MS => 4000,
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    // A pinned local probe also works with a Cloudflare Origin CA certificate.
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $part) use (&$response): int {
                        if (strlen($response) + strlen($part) > 8192) {
                            return 0;
                        }
                        $response .= $part;
                        return strlen($part);
                    }]);
                try {
                    $ok = curl_exec($curl);
                    $results[] = [$ok, curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $response];
                } finally {
                    curl_close($curl);
                }
            }
            if ($results[0][0] === false || $results[0][1] !== 200 || !hash_equals($bytes, $results[0][2])) {
                throw new HttpError(503, 'Không kiểm tra được website qua kết nối nội bộ. Kiểm tra listener, rewrite và quyền truy cập của webserver.');
            }
            if ($results[1][0] === false || !in_array($results[1][1], [403, 404], true)
                || str_contains($results[1][2], $bytes)) {
                throw new HttpError(503, 'Chưa bảo vệ được thư mục quickotp-private. Giữ các file .htaccess trong bộ ZIP, bật rewrite/Auto Load .htaccess và khởi động lại OpenLiteSpeed trước khi cài.');
            }
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }
}
