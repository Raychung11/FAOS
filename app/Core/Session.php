<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = Config::bool('SESSION_SECURE', false);
        session_name(Config::get('SESSION_NAME', 'faos_session'));
        session_set_cookie_params([
            'lifetime' => Config::int('SESSION_LIFETIME', 43200),
            'path' => '/',
            'httponly' => true,
            'secure' => $secure,
            'samesite' => Config::get('SESSION_SAMESITE', 'Lax'),
        ]);
        session_start();

        // Mitigate fixation: rotate id periodically.
        if (!isset($_SESSION['_init'])) {
            session_regenerate_id(true);
            $_SESSION['_init'] = time();
        } elseif (time() - (int) $_SESSION['_init'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['_init'] = time();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function flash(string $key, ?string $value = null): ?string
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $v = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $v;
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
