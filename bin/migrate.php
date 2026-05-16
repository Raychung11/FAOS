<?php
/**
 * FAOS migration runner — applies pending migrations only (no data loss).
 *
 *   php bin/migrate.php            apply baseline (if fresh) + pending migrations
 *   php bin/migrate.php --seed     also load demo seed if the DB is empty
 *   php bin/migrate.php --status   show applied / pending, make no changes
 *
 * Baseline = database/schema.sql (recorded as version "00000000000000_baseline").
 * Incremental changes live in database/migrations/<version>_<name>.sql and are
 * applied in filename order, each recorded in the schema_migrations table.
 */
declare(strict_types=1);

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Config;
use App\Core\SqlRunner;

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

$applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$tableExists = static function (PDO $p, string $t): bool {
    $s = $p->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $s->execute([$t]);
    return (int) $s->fetchColumn() > 0;
};

$baseVersion = '00000000000000_baseline';
$migrationsDir = BASE_PATH . '/database/migrations';
$pending = [];
foreach (glob($migrationsDir . '/*.sql') ?: [] as $f) {
    $v = basename($f, '.sql');
    if (!isset($applied[$v])) {
        $pending[$v] = $f;
    }
}
ksort($pending);

if ($status) {
    echo "Applied migrations:\n";
    foreach (array_keys($applied) as $v) {
        echo "  ✓ {$v}\n";
    }
    echo $applied ? '' : "  (none)\n";
    echo "Pending migrations:\n";
    if (!isset($applied[$baseVersion]) && !$tableExists($pdo, 'companies')) {
        echo "  • {$baseVersion} (schema.sql)\n";
    }
    foreach (array_keys($pending) as $v) {
        echo "  • {$v}\n";
    }
    echo $pending ? '' : "  (none)\n";
    exit(0);
}

$record = static function (PDO $p, string $version, string $file, string $sql): void {
    $stmt = $p->prepare(
        'INSERT INTO schema_migrations (version, filename, checksum) VALUES (?,?,?)'
    );
    $stmt->execute([$version, basename($file), sha1($sql)]);
};

// --- Baseline -------------------------------------------------------------
if (!isset($applied[$baseVersion])) {
    if ($tableExists($pdo, 'companies')) {
        // Existing pre-migration install: adopt current schema as baseline.
        $record($pdo, $baseVersion, 'schema.sql', 'adopted');
        echo "Baseline adopted (existing schema, not re-run).\n";
    } else {
        $schemaFile = BASE_PATH . '/database/schema.sql';
        $sql = (string) file_get_contents($schemaFile);
        $n = SqlRunner::runString($pdo, $sql);
        $record($pdo, $baseVersion, 'schema.sql', $sql);
        echo "Baseline applied: schema.sql ({$n} statements).\n";
    }
}

// --- Pending incremental migrations --------------------------------------
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
