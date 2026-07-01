<?php
/**
 * AdvisorOS — Shared helper functions.
 */

declare(strict_types=1);

/** Escape a value for safe HTML output (XSS prevention). */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an absolute URL from an app-relative path. */
function url(string $path = ''): string
{
    return APP_URL . '/' . ltrim($path, '/');
}

/** Send a redirect and stop execution. */
function redirect(string $path): never
{
    $location = preg_match('#^https?://#', $path) ? $path : url($path);
    header('Location: ' . $location);
    exit;
}

/** Read-once flash message. */
function set_flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $flashes;
}

/** Persist submitted input so forms can be re-rendered after a failure. */
function flash_old(array $data): void
{
    $_SESSION['_old'] = $data;
}

function old(string $key, $default = '')
{
    return $_SESSION['_old'][$key] ?? $default;
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

/** Trimmed request input accessor. */
function input(string $key, $default = null)
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Basic email validation. */
function is_valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/** JSON response helper for AJAX endpoints. */
function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/** Client IP, respecting a single trusted proxy header when present. */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Human-friendly currency formatting. */
function money($amount): string
{
    return number_format((float) $amount, 2);
}

/** Format a date string for display, returning a dash when empty. */
function fmt_date(?string $date, string $format = 'd M Y'): string
{
    if (empty($date) || $date === '0000-00-00') {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '—';
}

/** Title-case a snake_case enum value for display. */
function label(?string $value): string
{
    return $value ? ucwords(str_replace('_', ' ', $value)) : '—';
}

/** Generate a cryptographically secure random token. */
function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * Financial Health Score (0–100) from a financial snapshot row.
 * Six weighted dimensions: cash flow, debt ratio, protection coverage,
 * emergency reserve, investment readiness, retirement preparedness.
 */
function financial_health_score(array $f): int
{
    $income   = max(0.0, (float) ($f['monthly_income'] ?? 0));
    $expenses = max(0.0, (float) ($f['monthly_expenses'] ?? 0));
    $assets   = max(0.0, (float) ($f['total_assets'] ?? 0));
    $liab     = max(0.0, (float) ($f['total_liabilities'] ?? 0));
    $cover    = max(0.0, (float) ($f['insurance_coverage'] ?? 0));
    $invest   = max(0.0, (float) ($f['investments_value'] ?? 0));
    $emergency= max(0.0, (float) ($f['emergency_fund'] ?? 0));
    $retTarget= max(0.0, (float) ($f['retirement_target'] ?? 0));

    // Cash flow surplus ratio (20)
    $cf = $income > 0 ? max(0.0, ($income - $expenses) / $income) : 0;
    $s1 = min(20, $cf * 100);

    // Debt-to-asset ratio (20) — lower is better
    $dr = $assets > 0 ? min(1.0, $liab / $assets) : ($liab > 0 ? 1.0 : 0);
    $s2 = (1 - $dr) * 20;

    // Protection: coverage vs ~10x annual income (20)
    $target = $income * 12 * 10;
    $s3 = $target > 0 ? min(20, ($cover / $target) * 20) : ($cover > 0 ? 20 : 0);

    // Emergency reserve: months of expenses, target 6 (15)
    $months = $expenses > 0 ? $emergency / $expenses : ($emergency > 0 ? 6 : 0);
    $s4 = min(15, ($months / 6) * 15);

    // Investment readiness vs annual income (15)
    $annual = $income * 12;
    $s5 = $annual > 0 ? min(15, ($invest / $annual) * 15) : ($invest > 0 ? 15 : 0);

    // Retirement preparedness (10)
    $s6 = $retTarget > 0 ? min(10, (($assets + $invest) / $retTarget) * 10) : 0;

    return (int) round($s1 + $s2 + $s3 + $s4 + $s5 + $s6);
}

/** Standard AI disclaimer required on every AI-generated output. */
function ai_disclaimer(): string
{
    return 'This is not financial advice. Final recommendations must be '
        . 'reviewed and approved by a licensed financial advisor.';
}
