<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * User & role administration (permission: admin.users).
 *
 * Hardened: company-scoped (never trust a client company_id), password/PIN
 * hashed and never returned, and lockout safeguards so an admin cannot lock
 * the company out of its last super_admin or disable their own account.
 */
final class AdminUserController extends Controller
{
    private const SAFE_COLS =
        'u.id, u.username, u.full_name, u.email, u.phone, u.role_id,
         r.code AS role_code, r.name AS role_name,
         u.outlet_id, o.name AS outlet_name, u.kiosk_id,
         u.is_active, u.last_login_at, u.locked_until,
         (u.pin_hash IS NOT NULL) AS has_pin, u.created_at';

    public function list(Request $req): void
    {
        $sql = 'SELECT ' . self::SAFE_COLS . '
                FROM users u
                JOIN roles r ON r.id = u.role_id
                LEFT JOIN outlets o ON o.id = u.outlet_id
                WHERE u.company_id = ?';
        $args = [$this->companyId()];
        if ($req->query('q')) {
            $sql .= ' AND (u.username LIKE ? OR u.full_name LIKE ?)';
            $args[] = '%' . $req->query('q') . '%';
            $args[] = '%' . $req->query('q') . '%';
        }
        $sql .= ' ORDER BY u.is_active DESC, u.full_name';
        Response::ok(Database::all($sql, $args));
    }

    public function show(Request $req, array $p): void
    {
        $u = $this->findUser((int) $p['id']);
        $u ? Response::ok($u) : Response::fail('Not found', 404);
    }

    public function roles(Request $req): void
    {
        $roles = Database::all('SELECT * FROM roles ORDER BY id');
        foreach ($roles as &$r) {
            $r['permissions'] = Database::all(
                'SELECT p.code, p.name, p.module FROM role_permissions rp
                 JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = ? ORDER BY p.module, p.code',
                [$r['id']]
            );
        }
        Response::ok([
            'roles'       => $roles,
            'permissions' => Database::all('SELECT * FROM permissions ORDER BY module, code'),
        ]);
    }

    public function create(Request $req): void
    {
        $d = $this->validate($req, [
            'username'  => 'required|string|max:64',
            'full_name' => 'required|string|max:160',
            'role_id'   => 'required|int',
            'password'  => 'required|string',
        ]);
        // Length is checked explicitly: the generic "min" rule compares
        // numeric strings numerically, so a digits-only password (e.g. "123")
        // would otherwise slip past a min:6.
        if (strlen((string) $d['username']) < 3) {
            Response::fail('Username must be at least 3 characters', 422);
        }
        if (strlen((string) $d['password']) < 6) {
            Response::fail('Password must be at least 6 characters', 422);
        }
        $role = $this->validRole((int) $d['role_id']);
        [$outletId, $kioskId] = $this->resolveScope($req);

        if (Database::scalar('SELECT id FROM users WHERE username = ?', [$d['username']])) {
            Response::fail('Username already taken', 422);
        }
        $pinHash = $this->pinHash($req->input('pin'));

        $id = Database::insert(
            'INSERT INTO users
               (company_id, role_id, outlet_id, kiosk_id, username, full_name,
                email, phone, password_hash, pin_hash, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,1)',
            [
                $this->companyId(), $role['id'], $outletId, $kioskId,
                $d['username'], $d['full_name'],
                $req->input('email'), $req->input('phone'),
                password_hash($d['password'], PASSWORD_BCRYPT), $pinHash,
            ]
        );
        $this->syncWorker($id, $role['code'], $outletId, $kioskId, $req->input('staff_no'));
        Audit::log('user_create', 'users', (string) $id, null,
            ['username' => $d['username'], 'role' => $role['code']]);
        Response::ok(['id' => $id], 'User created');
    }

    public function update(Request $req, array $p): void
    {
        $target = $this->findUser((int) $p['id']);
        if (!$target) {
            Response::fail('Not found', 404);
        }
        $self = (int) $p['id'] === (int) Auth::id();

        $fields = [];
        $args = [];
        foreach (['full_name', 'email', 'phone'] as $f) {
            if ($req->input($f) !== null) {
                $fields[] = "$f = ?";
                $args[] = $req->input($f);
            }
        }

        if ($req->input('role_id') !== null && (int) $req->input('role_id') !== (int) $target['role_id']) {
            if ($self) {
                Response::fail('You cannot change your own role', 422);
            }
            $newRole = $this->validRole((int) $req->input('role_id'));
            if ($target['role_code'] === 'super_admin') {
                $this->guardLastSuperAdmin((int) $p['id']);
            }
            $fields[] = 'role_id = ?';
            $args[] = $newRole['id'];
        }

        if ($req->input('outlet_id') !== null || $req->input('kiosk_id') !== null) {
            [$outletId, $kioskId] = $this->resolveScope($req);
            $fields[] = 'outlet_id = ?';
            $args[] = $outletId;
            $fields[] = 'kiosk_id = ?';
            $args[] = $kioskId;
        }

        if ($req->input('is_active') !== null) {
            $active = (int) ((bool) $req->input('is_active'));
            if ($active === 0) {
                if ($self) {
                    Response::fail('You cannot deactivate your own account', 422);
                }
                if ($target['role_code'] === 'super_admin') {
                    $this->guardLastSuperAdmin((int) $p['id']);
                }
            }
            $fields[] = 'is_active = ?';
            $args[] = $active;
        }

        if (!$fields) {
            Response::fail('Nothing to update', 422);
        }
        $args[] = (int) $p['id'];
        $args[] = $this->companyId();
        Database::run(
            'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ? AND company_id = ?',
            $args
        );
        Audit::log('user_update', 'users', $p['id'], $target, $req->all());
        Response::ok(null, 'User updated');
    }

    public function resetPassword(Request $req, array $p): void
    {
        $target = $this->findUser((int) $p['id']);
        if (!$target) {
            Response::fail('Not found', 404);
        }
        $d = $this->validate($req, ['password' => 'required|string']);
        if (strlen((string) $d['password']) < 6) {
            Response::fail('Password must be at least 6 characters', 422);
        }
        Database::run(
            'UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL
             WHERE id = ? AND company_id = ?',
            [password_hash($d['password'], PASSWORD_BCRYPT), (int) $p['id'], $this->companyId()]
        );
        Audit::log('user_password_reset', 'users', $p['id']);
        Response::ok(null, 'Password reset');
    }

    public function setPin(Request $req, array $p): void
    {
        $target = $this->findUser((int) $p['id']);
        if (!$target) {
            Response::fail('Not found', 404);
        }
        $pin = $req->input('pin');
        $hash = ($pin === null || $pin === '') ? null : $this->pinHash($pin);
        Database::run(
            'UPDATE users SET pin_hash = ? WHERE id = ? AND company_id = ?',
            [$hash, (int) $p['id'], $this->companyId()]
        );
        Audit::log('user_pin_set', 'users', $p['id'], null, ['cleared' => $hash === null]);
        Response::ok(null, $hash === null ? 'PIN cleared' : 'PIN set');
    }

    public function unlock(Request $req, array $p): void
    {
        if (!$this->findUser((int) $p['id'])) {
            Response::fail('Not found', 404);
        }
        Database::run(
            'UPDATE users SET failed_attempts = 0, locked_until = NULL
             WHERE id = ? AND company_id = ?',
            [(int) $p['id'], $this->companyId()]
        );
        Audit::log('user_unlock', 'users', $p['id']);
        Response::ok(null, 'Account unlocked');
    }

    // ---- helpers -----------------------------------------------------------

    private function findUser(int $id): ?array
    {
        return Database::first(
            'SELECT ' . self::SAFE_COLS . '
             FROM users u JOIN roles r ON r.id = u.role_id
             LEFT JOIN outlets o ON o.id = u.outlet_id
             WHERE u.id = ? AND u.company_id = ?',
            [$id, $this->companyId()]
        );
    }

    private function validRole(int $roleId): array
    {
        $role = Database::first('SELECT id, code FROM roles WHERE id = ?', [$roleId]);
        if (!$role) {
            Response::fail('Invalid role', 422);
        }
        return $role;
    }

    /** outlet/kiosk must belong to this company; kiosk implies its outlet. */
    private function resolveScope(Request $req): array
    {
        $outletId = $req->input('outlet_id') ?: null;
        $kioskId  = $req->input('kiosk_id') ?: null;
        if ($outletId !== null) {
            $ok = Database::scalar(
                'SELECT id FROM outlets WHERE id = ? AND company_id = ?',
                [$outletId, $this->companyId()]
            );
            if (!$ok) {
                Response::fail('Outlet not in your company', 422);
            }
        }
        if ($kioskId !== null) {
            $k = Database::first(
                'SELECT k.id, k.outlet_id FROM kiosks k
                 JOIN outlets o ON o.id = k.outlet_id
                 WHERE k.id = ? AND o.company_id = ?',
                [$kioskId, $this->companyId()]
            );
            if (!$k) {
                Response::fail('Kiosk not in your company', 422);
            }
            $outletId = (int) $k['outlet_id']; // keep them consistent
        }
        return [$outletId ? (int) $outletId : null, $kioskId ? (int) $kioskId : null];
    }

    private function guardLastSuperAdmin(int $excludeUserId): void
    {
        $count = (int) Database::scalar(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.code = 'super_admin' AND u.is_active = 1
               AND u.company_id = ? AND u.id <> ?",
            [$this->companyId(), $excludeUserId]
        );
        if ($count < 1) {
            Response::fail('Cannot remove/disable the last active super admin', 422);
        }
    }

    private function pinHash(?string $pin): ?string
    {
        if ($pin === null || $pin === '') {
            return null;
        }
        if (!preg_match('/^\d{4,8}$/', $pin)) {
            Response::fail('PIN must be 4-8 digits', 422);
        }
        return password_hash($pin, PASSWORD_BCRYPT);
    }

    private function syncWorker(int $userId, string $roleCode, ?int $outletId, ?int $kioskId, ?string $staffNo): void
    {
        if ($roleCode !== 'worker' || !$outletId) {
            return;
        }
        if (Database::scalar('SELECT id FROM workers WHERE user_id = ?', [$userId])) {
            Database::run(
                'UPDATE workers SET outlet_id = ?, kiosk_id = ? WHERE user_id = ?',
                [$outletId, $kioskId, $userId]
            );
            return;
        }
        Database::insert(
            'INSERT INTO workers (user_id, outlet_id, kiosk_id, staff_no, is_active)
             VALUES (?,?,?,?,1)',
            [$userId, $outletId, $kioskId, $staffNo]
        );
    }
}
