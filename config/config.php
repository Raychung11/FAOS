<?php
/**
 * AdvisorOS — Application configuration.
 * Loads environment values from the project .env file (falling back to
 * sensible defaults) and exposes them via the env() helper + constants.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/**
 * Minimal .env parser. Values already set in the real environment win.
 */
function load_env(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Strip optional surrounding quotes.
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

function env(string $key, $default = null)
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return match (strtolower((string) $value)) {
        'true'  => true,
        'false' => false,
        'null'  => null,
        default => $value,
    };
}

load_env(APP_ROOT . '/.env');

date_default_timezone_set((string) env('APP_TIMEZONE', 'Asia/Kuala_Lumpur'));

define('APP_NAME',  (string) env('APP_NAME', 'MaxWealth'));
define('APP_ENV',   (string) env('APP_ENV', 'production'));
define('APP_DEBUG', (bool)   env('APP_DEBUG', false));
/**
 * Base URL. An explicit APP_URL in .env is authoritative and
 * recommended for production. When it is blank we auto-detect
 * scheme + host + the sub-directory the app lives in (derived from
 * the filesystem vs DOCUMENT_ROOT, so it is correct for every entry
 * point — root or sub-folder installs alike). This is what makes the
 * stylesheet, links and redirects resolve when APP_URL is unset.
 */
$appUrl = trim((string) env('APP_URL', ''));
if ($appUrl === '') {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $base = '';
    $docroot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $appdir  = realpath(APP_ROOT);
    if ($docroot && $appdir && str_starts_with($appdir, $docroot)) {
        $base = '/' . trim(str_replace('\\', '/', substr($appdir, strlen($docroot))), '/');
        if ($base === '/') { $base = ''; }
    }
    $appUrl = ($https ? 'https' : 'http') . '://' . $host . $base;
}
define('APP_URL', rtrim($appUrl, '/'));

define('UPLOAD_DIR',   APP_ROOT . '/uploads');
define('MAX_UPLOAD_MB', (int) env('MAX_UPLOAD_MB', 10));

define('MAX_LOGIN_ATTEMPTS',   (int) env('MAX_LOGIN_ATTEMPTS', 5));
define('LOGIN_LOCKOUT_SECONDS',(int) env('LOGIN_LOCKOUT_SECONDS', 900));
define('SESSION_LIFETIME',     (int) env('SESSION_LIFETIME', 3600));

// Error visibility depends on environment.
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}
