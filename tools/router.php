<?php
declare(strict_types=1);

// Development router for php -S; production uses OpenLiteSpeed/Apache rewrite rules.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!is_string($path) || preg_match('~^/(?:\.|quickotp-private(?:/|$)|vendor(?:/|$)|storage(?:/|$))~', rawurldecode($path))) {
    http_response_code(403);
    exit;
}
$public = realpath($_SERVER['DOCUMENT_ROOT']);
$file = is_string($path) ? realpath($public . '/' . $path) : false;
if ($file !== false && str_starts_with($file, $public . DIRECTORY_SEPARATOR) && is_file($file)
    && (preg_match('/\.(?:css|js|svg|png|ico)$/D', $file) || basename($file) === 'install.php'
        || preg_match('/^quickotp-probe-[a-f0-9]{32}\.txt$/D', basename($file)))) {
    return false;
}
require $public . '/index.php';
