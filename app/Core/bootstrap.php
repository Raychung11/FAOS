<?php
/**
 * FAOS bootstrap: autoloader, env, error handling, timezone.
 * Included by public/index.php and bin/* scripts.
 */
declare(strict_types=1);

define('FAOS_START', microtime(true));
define('BASE_PATH', dirname(__DIR__, 2));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');

// ---- PSR-4-ish autoloader (App\ => app/) -----------------------------------
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, 4));
    $file = APP_PATH . '/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/helpers.php';

App\Core\Config::load(BASE_PATH . '/.env');

date_default_timezone_set(App\Core\Config::get('APP_TIMEZONE', 'Asia/Kuala_Lumpur'));

$debug = App\Core\Config::bool('APP_DEBUG', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

set_exception_handler(static function (\Throwable $e): void {
    App\Core\Logger::error($e->getMessage(), [
        'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString(),
    ]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "[ERROR] {$e->getMessage()}\n");
        exit(1);
    }
    http_response_code(500);
    $debug = App\Core\Config::bool('APP_DEBUG', false);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => $debug ? $e->getMessage() : 'Internal server error',
        ]);
        return;
    }
    echo '<h1>500 - Internal Server Error</h1>';
    if ($debug) {
        echo '<pre>' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
    }
});
