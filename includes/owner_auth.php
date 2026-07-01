<?php
/**
 * AdvisorOS — Business-owner self-service auth.
 *
 * Lightweight self-registration path for business owners who found the
 * platform via /valuation-try.php or /equity-try.php and want to save
 * their reports against a real account. Kept deliberately separate
 * from the advisor login (includes/auth.php + users table) so a
 * "prospect" account can never be confused with a firm-invited portal
 * client.
 *
 * Storage is migration-free — records live in the settings table at
 * platform level (tenant_id=0) keyed by SHA-256 of the lower-cased
 * email. Password is stored as a PASSWORD_DEFAULT hash. The
 * `login_attempts` table (already used by advisor auth) provides
 * brute-force protection with the same MAX_LOGIN_ATTEMPTS /
 * LOGIN_LOCKOUT_SECONDS constants.
 *
 * Session key: $_SESSION['owner'] — carries {email, name, id_hash,
 * created_at, last_login_at}. Never carries the password hash.
 */

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

const OWNER_KEY_PREFIX = 'owner:';

/** Deterministic settings key for an email. */
function owner_key(string $email): string
{
    return OWNER_KEY_PREFIX . substr(hash('sha256',
        strtolower(trim($email)) . '|' . (string) env('APP_KEY', 'advisoros')), 0, 32);
}

/** Public-facing id (safe to expose in URLs / audit logs). */
function owner_public_id(string $email): string
{
    return substr(owner_key($email), strlen(OWNER_KEY_PREFIX), 16);
}

/** Load a prospect record by email, or null. */
function owner_find(string $email): ?array
{
    $rec = platform_setting_get_json(owner_key($email), []);
    if (empty($rec) || empty($rec['password_hash'])) { return null; }
    return $rec;
}

function owner_exists(string $email): bool
{
    return owner_find($email) !== null;
}

/** Persist a prospect (creates or updates). */
function owner_put(string $email, array $rec): void
{
    platform_setting_put_json(owner_key($email), $rec);
}

/** Create a new prospect account. Returns [ok, message]. */
function owner_register(string $email, string $password, string $fullName,
    string $phone, string $companyName): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Please enter a valid email address.'];
    }
    if (strlen($password) < 8) {
        return [false, 'Password must be at least 8 characters.'];
    }
    if (trim($fullName) === '') {
        return [false, 'Please enter your name.'];
    }
    if (owner_exists($email)) {
        return [false, 'An account with that email already exists — try signing in.'];
    }

    owner_put($email, [
        'email'         => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'full_name'     => mb_substr(trim($fullName), 0, 120),
        'phone'         => mb_substr(trim($phone), 0, 40),
        'company_name'  => mb_substr(trim($companyName), 0, 150),
        'created_at'    => date('Y-m-d H:i:s'),
        'last_login_at' => date('Y-m-d H:i:s'),
        'reports'       => [], // will hold [{kind:'equity', token, saved_at, snapshot}, ...]
    ]);
    return [true, 'Account created.'];
}

/** Log a prospect in. Returns [ok, message]. */
function owner_login(string $email, string $password): array
{
    $email = strtolower(trim($email));
    $ip    = function_exists('client_ip') ? client_ip()
              : (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    // Reuse advisor brute-force guard (shared login_attempts table).
    try {
        if (function_exists('is_locked_out') && is_locked_out($email, $ip)) {
            return [false, 'Too many failed attempts. Please try again later.'];
        }
    } catch (Throwable $e) { /* table optional at first boot */ }

    $rec = owner_find($email);
    $ok  = $rec && password_verify($password, (string) $rec['password_hash']);

    try {
        if (function_exists('record_login_attempt')) {
            record_login_attempt($email, $ip, (bool) $ok);
        }
    } catch (Throwable $e) { /* best effort */ }

    if (!$ok) { return [false, 'Invalid email or password.']; }

    // Transparent hash upgrade.
    if (password_needs_rehash((string) $rec['password_hash'], PASSWORD_DEFAULT)) {
        $rec['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }
    $rec['last_login_at'] = date('Y-m-d H:i:s');
    owner_put($email, $rec);

    owner_establish_session($rec);
    return [true, 'Welcome.'];
}

/** Populate the session for a prospect. */
function owner_establish_session(array $rec): void
{
    // Regenerate session id — prevents session fixation across auth boundary.
    if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
    $_SESSION['owner'] = [
        'email'         => $rec['email'],
        'name'          => $rec['full_name'],
        'id_hash'       => owner_public_id($rec['email']),
        'created_at'    => $rec['created_at'] ?? '',
        'last_login_at' => $rec['last_login_at'] ?? '',
    ];
}

function owner_current(): ?array
{
    return $_SESSION['owner'] ?? null;
}

function owner_is_logged_in(): bool
{
    return !empty($_SESSION['owner']);
}

function owner_logout(): void
{
    unset($_SESSION['owner']);
    if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
}

function owner_require_login(): array
{
    if (!owner_is_logged_in()) {
        redirect('owner-login.php');
    }
    return owner_current();
}

/** Reload the current prospect's full record (for pages that need it). */
function owner_current_record(): ?array
{
    $me = owner_current();
    return $me ? owner_find($me['email']) : null;
}

// ---------- Report attachment ------------------------------------------

/**
 * Attach a saved report (equity or valuation) to the current prospect.
 * $kind = 'equity' | 'valuation'
 * $ref  = token or record id; $snapshot = compact display info.
 */
function owner_attach_report(string $kind, string $ref, array $snapshot): void
{
    if (!owner_is_logged_in()) { return; }
    $rec = owner_current_record();
    if (!$rec) { return; }
    $rec['reports'] = (array) ($rec['reports'] ?? []);

    // Upsert by (kind, ref).
    $found = false;
    foreach ($rec['reports'] as &$r) {
        if (($r['kind'] ?? '') === $kind && ($r['ref'] ?? '') === $ref) {
            $r['snapshot']  = $snapshot;
            $r['saved_at']  = date('Y-m-d H:i:s');
            $found = true;
            break;
        }
    }
    unset($r);
    if (!$found) {
        $rec['reports'][] = [
            'kind'     => $kind,
            'ref'      => $ref,
            'snapshot' => $snapshot,
            'saved_at' => date('Y-m-d H:i:s'),
        ];
    }
    // Cap history at 50 to avoid unbounded growth.
    if (count($rec['reports']) > 50) {
        $rec['reports'] = array_slice($rec['reports'], -50);
    }
    owner_put($rec['email'], $rec);
}
