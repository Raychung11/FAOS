<?php
/**
 * AdvisorOS — One-time installer.
 *
 * Runs database/schema.sql + database/seed.sql, then creates the
 * platform Super Admin and a demo tenant with role users. Passwords are
 * hashed with password_hash(). Safe to re-run (idempotent).
 *
 * CLI:  php setup/install.php
 * Web:  visit /setup/install.php  (block/remove after first run)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../includes/functions.php';

$isCli = PHP_SAPI === 'cli';
$out = static function (string $line) use ($isCli): void {
    echo $isCli ? $line . "\n" : nl2br(e($line)) . "<br>\n";
};

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre style="font-family:monospace;background:#0B1F3A;color:#cdd6e4;padding:20px">';
}

// One-time web lock: once installed, refuse to run again from the
// browser so the public installer cannot be replayed. The CLI is
// always allowed (re-running is intentional during development).
$lockFile = __DIR__ . '/.installed.lock';
if (!$isCli && is_file($lockFile)) {
    http_response_code(403);
    $out('AdvisorOS is already installed.');
    $out('');
    $out('For security, delete the entire /setup directory now.');
    $out('To re-run intentionally: remove setup/.installed.lock, or run');
    $out('  php setup/install.php  from the command line.');
    if (!$isCli) { echo '</pre>'; }
    exit;
}

try {
    $pdo = db();

    // ---- Schema ---------------------------------------------------
    $out('→ Applying schema.sql ...');
    $pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
    $out('  schema applied.');

    // ---- Seed reference data -------------------------------------
    $out('→ Applying seed.sql ...');
    $pdo->exec(file_get_contents(__DIR__ . '/../database/seed.sql'));
    $out('  reference data seeded.');

    // ---- Role id lookup ------------------------------------------
    $roleId = [];
    foreach ($pdo->query('SELECT id, code FROM roles') as $r) {
        $roleId[$r['code']] = (int) $r['id'];
    }

    $defaultPass = 'Admin@12345';
    $hash = static fn () => password_hash('Admin@12345', PASSWORD_DEFAULT);

    $upsertUser = static function (
        ?int $tenantId, int $roleId, string $name, string $email
    ) use ($pdo, $hash): int {
        $sel = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $sel->execute([$email]);
        if ($id = $sel->fetchColumn()) {
            return (int) $id;
        }
        $ins = $pdo->prepare(
            'INSERT INTO users (tenant_id, role_id, name, email, password_hash, status)
             VALUES (?, ?, ?, ?, ?, "active")'
        );
        $ins->execute([$tenantId, $roleId, $name, $email, $hash()]);
        return (int) $pdo->lastInsertId();
    };

    // ---- Super Admin ---------------------------------------------
    $out('→ Creating Super Admin ...');
    $upsertUser(null, $roleId['super_admin'], 'Platform Super Admin', 'superadmin@advisoros.app');

    // ---- Demo tenant ---------------------------------------------
    $out('→ Creating demo tenant ...');
    $planId = (int) $pdo->query("SELECT id FROM subscription_plans WHERE code='professional'")->fetchColumn();
    $sel = $pdo->prepare('SELECT id FROM tenants WHERE slug = ?');
    $sel->execute(['demo-advisory']);
    $tenantId = (int) $sel->fetchColumn();
    if (!$tenantId) {
        $ins = $pdo->prepare(
            'INSERT INTO tenants (company_name, slug, registration_no, email, plan_id,
                subscription_status, trial_ends_at, status)
             VALUES (?, ?, ?, ?, ?, "active", (CURDATE() + INTERVAL 30 DAY), "active")'
        );
        $ins->execute(['Demo Advisory Group', 'demo-advisory', 'REG-DEMO-001',
            'admin@demo-advisory.test', $planId]);
        $tenantId = (int) $pdo->lastInsertId();
    }

    // ---- Demo tenant users ---------------------------------------
    $out('→ Creating demo tenant users ...');
    $upsertUser($tenantId, $roleId['tenant_admin'],       'Demo Tenant Admin',    'admin@demo-advisory.test');
    $upsertUser($tenantId, $roleId['agency_leader'],      'Demo Agency Leader',   'leader@demo-advisory.test');
    $advisorId = $upsertUser($tenantId, $roleId['financial_advisor'], 'Demo Advisor', 'advisor@demo-advisory.test');
    $upsertUser($tenantId, $roleId['compliance_officer'], 'Demo Compliance',      'compliance@demo-advisory.test');

    $out('');
    $out('=============================================================');
    $out(' AdvisorOS installation complete.');
    $out('-------------------------------------------------------------');
    $out(' Default password for ALL seeded accounts: ' . $defaultPass);
    $out('');
    $out(' Super Admin   superadmin@advisoros.app');
    $out(' Tenant Admin  admin@demo-advisory.test');
    $out(' Agency Leader leader@demo-advisory.test');
    $out(' Advisor       advisor@demo-advisory.test');
    $out(' Compliance    compliance@demo-advisory.test');
    $out('=============================================================');
    $out(' SECURITY: change these passwords and delete setup/ in production.');

    // Lock the web installer against replay.
    @file_put_contents($lockFile, 'installed ' . date('c') . "\n");
    if (!$isCli) {
        $out('');
        $out(' The web installer is now locked. Delete the /setup directory.');
    }
} catch (Throwable $e) {
    http_response_code(500);
    $out('INSTALL FAILED: ' . $e->getMessage());
}

if (!$isCli) {
    echo '</pre>';
}
