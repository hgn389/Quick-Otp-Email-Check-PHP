<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('QUICKOTP_RUNTIME')) { http_response_code(403); exit; }
// Copy to install-password.php and set a unique password of at least 16 characters and at most 256 bytes.
// Never publish the configured file. The installer deletes it after success.
return '';
