<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function check(Request $req): bool
    {
        $sent = $req->input('_csrf') ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $stored = $_SESSION['_csrf'] ?? '';
        return $stored !== '' && is_string($sent) && hash_equals($stored, $sent);
    }
}
