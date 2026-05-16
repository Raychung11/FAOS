<?php
/**
 * Router for the PHP built-in dev server:
 *   php -S 0.0.0.0:8080 server.php
 * Serves real files from /public, routes everything else to the front controller.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/public' . $uri;

if ($uri !== '/' && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = [
        'css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'ico' => 'image/x-icon',
        'woff2' => 'font/woff2', 'json' => 'application/json',
    ][$ext] ?? null;
    if ($mime) {
        header('Content-Type: ' . $mime);
    }
    readfile($file);
    return true;
}

require __DIR__ . '/public/index.php';
