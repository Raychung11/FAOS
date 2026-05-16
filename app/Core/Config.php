<?php
declare(strict_types=1);

namespace App\Core;

/** Tiny .env loader + typed config accessor. */
final class Config
{
    private static array $items = [];
    private static bool $loaded = false;

    public static function load(string $envFile): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        // Defaults so the app boots even without a .env (e.g. fresh clone).
        self::$items = [
            'APP_NAME' => 'FAOS BOS',
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'http://localhost:8080',
            'APP_TIMEZONE' => 'Asia/Kuala_Lumpur',
            'APP_KEY' => 'change-me-please-32-characters-min',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_NAME' => 'faos_bos',
            'DB_USER' => 'root',
            'DB_PASS' => '',
            'DB_CHARSET' => 'utf8mb4',
            'SESSION_NAME' => 'faos_session',
            'SESSION_LIFETIME' => '43200',
            'SESSION_SECURE' => 'false',
            'SESSION_SAMESITE' => 'Lax',
            'AI_PROVIDER' => 'none',
            'AI_API_KEY' => '',
            'AI_MODEL' => 'claude-opus-4-7',
            'AI_BASE_URL' => '',
            'CURRENCY' => 'MYR',
            'CURRENCY_SYMBOL' => 'RM',
        ];

        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'")) {
                    $v = substr($v, 1, -1);
                }
                self::$items[$k] = $v;
            }
        }

        // Real environment variables win over the file.
        foreach (self::$items as $k => $_) {
            $env = getenv($k);
            if ($env !== false && $env !== '') {
                self::$items[$k] = $env;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$items[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::$items[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        return isset(self::$items[$key]) ? (int) self::$items[$key] : $default;
    }
}
