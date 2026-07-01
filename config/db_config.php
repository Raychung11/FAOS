<?php
/**
 * AdvisorOS — Database connection (PDO singleton).
 * All queries in the application MUST use prepared statements via this
 * connection. Raw SQL errors are never echoed to the browser.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host    = (string) env('DB_HOST', '127.0.0.1');
    $port    = (string) env('DB_PORT', '3306');
    $name    = (string) env('DB_NAME', 'advisoros');
    $user    = (string) env('DB_USER', 'root');
    $pass    = (string) env('DB_PASS', '');
    $charset = (string) env('DB_CHARSET', 'utf8mb4');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('[AdvisorOS] DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        if (APP_DEBUG) {
            exit('Database connection error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES));
        }
        exit('A database error occurred. Please contact your administrator.');
    }

    return $pdo;
}
