<?php
/**
 * FAOS migration runner.
 *
 *   php bin/migrate.php            baseline (if needed) + apply pending
 *   php bin/migrate.php --seed     also load demo seed if the DB is empty
 *   php bin/migrate.php --status   show applied / pending, change nothing
 *
 * database/schema.sql is the COMPLETE current schema (single source of truth
 * for a fresh DB; importing it + seed.sql also works standalone). Every
 * migration in database/migrations/ up to and including SCHEMA_BASELINE is
 * already folded into schema.sql, so the runner records those as applied
 * WITHOUT executing them (no duplicate-column/table errors on a fresh DB).
 * Only migrations newer than SCHEMA_BASELINE are executed — that's how future
 * incremental changes ship to existing deployments.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit("FAOS migrator is a command-line tool and cannot be run from a browser.\n"
        . "Import database/reset.sql in phpMyAdmin instead, then configure .env.\n");
}

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Config;
use App\Core\SqlRunner;

// Highest migration version baked into schema.sql. Bump this whenever
// schema.sql is regenerated to include newer migrations.
const SCHEMA_BASELINE = '20260516170000_accounting_periods';

$seed   = in_array('--seed', $argv, true);
$status = in_array('--status', $argv, true);

$host = Config::get('DB_HOST');
$port = Config::get('DB_PORT');
$name = Config::get('DB_NAME');
$char = Config::get('DB_CHARSET', 'utf8mb4');

try {
    $pdo = new PDO("mysql:host={$host};port={$port}", Config::get('DB_USER'), Config::get('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: cannot connect to MySQL: {$e->getMessage()}\n");
    exit(1);
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET {$char} COLLATE {$char}_unicode_ci");
$pdo->exec("USE `{$name}`");

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        version    VARCHAR(64)  NOT NULL,
        filename   VARCHAR(255) NOT NULL,
        checksum   CHAR(40)     NOT NULL,
        applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (version)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = array_flip(
    $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)
);

$tableExists = static function (PDO $p, string $t): bool {
    $s = $p->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $s->execute([$t]);
    return (int) $s->fetchColumn() > 0;
};

$baseVersion = '00000000000000_baseline';

$files = [];
foreach (glob(BASE_PATH . '/database/migrations/*.sql') ?: [] as $f) {
    $files[basename($f, '.sql')] = $f;
}
ksort($files);

$squashed = [];   // <= SCHEMA_BASELINE  -> recorded, never executed
$pending  = [];   // >  SCHEMA_BASELINE  -> executed
foreach ($files as $v => $f) {
    if (isset($applied[$v])) {
        continue;
    }
    if (strcmp($v, SCHEMA_BASELINE) <= 0) {
        $squashed[$v] = $f;
    } else {
        $pending[$v] = $f;
    }
}

if ($status) {
    echo "Applied:\n";
    foreach (array_keys($applied) as $v) {
        echo "  ✓ {$v}\n";
    }
    echo $applied ? '' : "  (none)\n";
    if (!isset($applied[$baseVersion])) {
        echo "Baseline pending: schema.sql"
            . ($tableExists($pdo, 'companies') ? " (adopt existing)\n" : " (fresh)\n");
    }
    foreach (array_keys($squashed) as $v) {
        echo "  ~ {$v} (folded into schema.sql)\n";
    }
    echo "Pending:\n";
    foreach (array_keys($pending) as $v) {
        echo "  • {$v}\n";
    }
    echo $pending ? '' : "  (none)\n";
    exit(0);
}

$record = static function (PDO $p, string $version, string $file, string $sql): void {
    $p->prepare('INSERT INTO schema_migrations (version, filename, checksum) VALUES (?,?,?)')
      ->execute([$version, basename($file), sha1($sql)]);
};

// --- Baseline -------------------------------------------------------------
if (!isset($applied[$baseVersion])) {
    if ($tableExists($pdo, 'companies')) {
        $record($pdo, $baseVersion, 'schema.sql', 'adopted');
        echo "Baseline adopted (existing schema, not re-run).\n";
    } else {
        $sql = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
        $n = SqlRunner::runString($pdo, $sql);
        $record($pdo, $baseVersion, 'schema.sql', $sql);
        echo "Baseline applied: schema.sql ({$n} statements).\n";
    }
    $applied[$baseVersion] = true;
}

// --- Squash: migrations already inside schema.sql -> mark, never run -------
foreach ($squashed as $v => $f) {
    $record($pdo, $v, $f, 'squashed-into-baseline');
    echo "Folded (already in schema.sql): {$v}\n";
}

// --- Pending incremental migrations (newer than the baked baseline) -------
if (!$pending) {
    echo "No pending migrations.\n";
} else {
    foreach ($pending as $version => $file) {
        $sql = (string) file_get_contents($file);
        try {
            $n = SqlRunner::runString($pdo, $sql);
            $record($pdo, $version, $file, $sql);
            echo "Applied: {$version} ({$n} statements).\n";
        } catch (\Throwable $e) {
            fwrite(STDERR, "FAILED migration {$version}: {$e->getMessage()}\n");
            exit(1);
        }
    }
}

// --- Optional demo seed ---------------------------------------------------
if ($seed) {
    $hasData = $tableExists($pdo, 'companies')
        ? (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn()
        : 0;
    if ($hasData > 0) {
        echo "Seed skipped (data already present).\n";
    } else {
        SqlRunner::runFile($pdo, BASE_PATH . '/database/seed.sql');
        echo "Demo seed loaded.\n";
    }
}

echo "Migrations up to date.\n";
