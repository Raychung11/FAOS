<?php
/**
 * AdvisorOS — Additive migration runner (business data model).
 *
 * Applies database/migrate_business.sql, which contains ONLY
 * CREATE TABLE IF NOT EXISTS statements. It never drops or alters
 * existing tables and never touches data — safe to run on a live
 * production database, and idempotent (re-running is a no-op).
 *
 * CLI:  php setup/migrate.php
 * Web:  /setup/migrate.php   (must be signed in as Super Admin)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    require_login();
    require_permission('platform.manage');
    echo '<pre style="font-family:monospace;background:#0B1F3A;color:#cdd6e4;padding:20px">';
}

$out = static function (string $line) use ($isCli): void {
    echo $isCli ? $line . "\n" : e($line) . "\n";
};

try {
    $sql = file_get_contents(__DIR__ . '/../database/migrate_business.sql');
    if ($sql === false) {
        throw new RuntimeException('Cannot read database/migrate_business.sql');
    }

    $out('→ Applying additive migration: business data model ...');
    db()->exec($sql);

    $tables = ['companies', 'company_stakeholders', 'business_financials'];
    foreach ($tables as $t) {
        $ok = db()->query("SHOW TABLES LIKE " . db()->quote($t))->fetchColumn();
        $out(($ok ? '  ✓ ' : '  ✗ ') . $t . ($ok ? ' present' : ' MISSING'));
    }

    $out('');
    $out('Migration complete. No existing tables or data were modified.');
    if (!$isCli) {
        $out('You may now use the Business profile on any client.');
    }
} catch (Throwable $e) {
    if (!$isCli) {
        http_response_code(500);
    }
    error_log('[AdvisorOS] migrate failed: ' . $e->getMessage());
    $out('Migration failed: ' . $e->getMessage());
}

if (!$isCli) {
    echo '</pre>';
}
