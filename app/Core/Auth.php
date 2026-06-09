<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;

    /** Attempt password OR pin login. Returns user row or null. */
    public static function attempt(string $username, string $secret, bool $isPin = false): ?array
    {
        $u = Database::first(
            'SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1',
            [$username]
        );
        if (!$u) {
            return null;
        }

        if ($u['locked_until'] !== null && strtotime($u['locked_until']) > time()) {
            return null;
        }

        $hash = $isPin ? ($u['pin_hash'] ?? '') : $u['password_hash'];
        if (!$hash || !password_verify($secret, $hash)) {
            $fails = (int) $u['failed_attempts'] + 1;
            $lock = $fails >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
            Database::run(
                'UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?',
                [$fails, $lock, $u['id']]
            );
            return null;
        }

        Database::run(
            'UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?',
            [$u['id']]
        );

        unset($u['password_hash'], $u['pin_hash']);
        return $u;
    }

    public static function login(array $user): void
    {
        Session::start();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['_init'] = time();
        self::$user = null;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $uid = Session::get('uid');
        if (!$uid) {
            return null;
        }
        $u = Database::first(
            'SELECT u.*, r.code AS role_code, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? AND u.is_active = 1 LIMIT 1',
            [$uid]
        );
        if ($u) {
            unset($u['password_hash'], $u['pin_hash']);
            self::$user = $u;
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function companyId(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['company_id'] : null;
    }

    /** @return string[] permission codes for the current user's role */
    public static function permissions(): array
    {
        $u = self::user();
        if (!$u) {
            return [];
        }
        static $cache = [];
        $rid = (int) $u['role_id'];
        if (isset($cache[$rid])) {
            return $cache[$rid];
        }
        $rows = Database::all(
            'SELECT p.code FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ?',
            [$rid]
        );
        return $cache[$rid] = array_column($rows, 'code');
    }

    public static function can(string $permission): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        if ($u['role_code'] === 'super_admin') {
            return true;
        }
        return in_array($permission, self::permissions(), true);
    }

    public static function requireLogin(Request $req): void
    {
        if (self::check()) {
            return;
        }
        if ($req->wantsJson()) {
            Response::fail('Unauthenticated', 401);
        }
        Response::redirect(base_url('/login'));
    }

    public static function requirePermission(Request $req, string $perm): void
    {
        self::requireLogin($req);
        if (!self::can($perm)) {
            if ($req->wantsJson()) {
                Response::fail('Forbidden: missing permission ' . $perm, 403);
            }
            Response::html('<h1>403 Forbidden</h1><p>You lack permission: ' . htmlspecialchars($perm) . '</p>', 403);
        }
    }
}
