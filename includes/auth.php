<?php
/**
 * AdvisorOS — Authentication & session management.
 *  - Secure session cookie configuration
 *  - Idle session timeout
 *  - Brute-force protection (login_attempts table)
 *  - password_hash() / password_verify()
 *  - "Remember me" persistent token
 */

declare(strict_types=1);

/** Configure and start a hardened session. */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443);

    session_name((string) env('SESSION_NAME', 'advisoros_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Idle timeout.
    $now = time();
    if (isset($_SESSION['_last_activity'])
        && ($now - (int) $_SESSION['_last_activity']) > SESSION_LIFETIME) {
        logout_user();
        set_flash('warning', 'Your session expired. Please sign in again.');
        redirect('login.php');
    }
    $_SESSION['_last_activity'] = $now;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']);
}

/** Require an authenticated session or redirect to login. */
function require_login(): void
{
    if (!is_logged_in() && !attempt_remember_login()) {
        set_flash('warning', 'Please sign in to continue.');
        redirect('login.php');
    }
}

/** Count failed attempts for an email/IP within the lockout window. */
function recent_failed_attempts(string $email, string $ip): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE successful = 0 AND (email = ? OR ip_address = ?)
           AND attempted_at > (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->execute([$email, $ip, LOGIN_LOCKOUT_SECONDS]);
    return (int) $stmt->fetchColumn();
}

function record_login_attempt(string $email, string $ip, bool $ok): void
{
    $stmt = db()->prepare(
        'INSERT INTO login_attempts (email, ip_address, successful) VALUES (?, ?, ?)'
    );
    $stmt->execute([$email, $ip, $ok ? 1 : 0]);
}

function is_locked_out(string $email, string $ip): bool
{
    return recent_failed_attempts($email, $ip) >= MAX_LOGIN_ATTEMPTS;
}

/** Load a user row + role code by email. */
function find_user_by_email(string $email): ?array
{
    $stmt = db()->prepare(
        'SELECT u.*, r.code AS role_code, r.name AS role_name
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.email = ? AND u.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Establish the authenticated session for a user row. */
function establish_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'         => (int) $user['id'],
        'tenant_id'  => $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
        'name'       => $user['name'],
        'email'      => $user['email'],
        'role_id'    => (int) $user['role_id'],
        'role_code'  => $user['role_code'],
        'role_name'  => $user['role_name'],
    ];

    $stmt = db()->prepare(
        'UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?'
    );
    $stmt->execute([client_ip(), (int) $user['id']]);
}

/**
 * Attempt to authenticate. Returns [success, message].
 */
function attempt_login(string $email, string $password, bool $remember = false): array
{
    $ip = client_ip();

    if (is_locked_out($email, $ip)) {
        return [false, 'Too many failed attempts. Please try again later.'];
    }

    $user = find_user_by_email($email);
    $ok = $user
        && $user['status'] === 'active'
        && password_verify($password, $user['password_hash']);

    record_login_attempt($email, $ip, (bool) $ok);

    if (!$ok) {
        return [false, 'Invalid email or password.'];
    }

    // Transparent hash upgrade if algorithm/cost changed.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $new = password_hash($password, PASSWORD_DEFAULT);
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$new, (int) $user['id']]);
    }

    establish_session($user);

    if ($remember) {
        issue_remember_token((int) $user['id']);
    }

    return [true, 'Welcome back.'];
}

/** Issue a persistent "remember me" cookie + store its hash. */
function issue_remember_token(int $userId): void
{
    $token = random_token(32);
    $stmt = db()->prepare('UPDATE users SET remember_token = ? WHERE id = ?');
    $stmt->execute([hash('sha256', $token), $userId]);

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('advisoros_remember', $userId . ':' . $token, [
        'expires'  => time() + 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Restore a session from a valid remember cookie. */
function attempt_remember_login(): bool
{
    $cookie = $_COOKIE['advisoros_remember'] ?? '';
    if (!str_contains($cookie, ':')) {
        return false;
    }
    [$userId, $token] = explode(':', $cookie, 2);
    if (!ctype_digit($userId) || $token === '') {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT u.*, r.code AS role_code, r.name AS role_name
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? AND u.deleted_at IS NULL AND u.status = "active" LIMIT 1'
    );
    $stmt->execute([(int) $userId]);
    $user = $stmt->fetch();

    if (!$user || empty($user['remember_token'])
        || !hash_equals($user['remember_token'], hash('sha256', $token))) {
        return false;
    }

    establish_session($user);
    return true;
}

function logout_user(): void
{
    if (!empty($_SESSION['user']['id'])) {
        $stmt = db()->prepare('UPDATE users SET remember_token = NULL WHERE id = ?');
        $stmt->execute([(int) $_SESSION['user']['id']]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    setcookie('advisoros_remember', '', time() - 42000, '/');
    session_destroy();
}

/** Landing route per role after login. */
function role_home(string $roleCode): string
{
    return match ($roleCode) {
        'super_admin'        => 'superadmin/dashboard.php',
        'tenant_admin'       => 'tenant/dashboard.php',
        'agency_leader'      => 'advisor/dashboard.php',
        'financial_advisor'  => 'advisor/dashboard.php',
        'compliance_officer' => 'compliance/dashboard.php',
        'client'             => 'client/portal.php',
        default              => 'login.php',
    };
}
