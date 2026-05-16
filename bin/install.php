<?php
/**
 * FAOS installer (CLI).
 *   php bin/install.php            -> create DB, schema, seed
 *   php bin/install.php --fresh    -> DROP and recreate the database
 *   php bin/install.php --no-seed  -> schema only
 */
declare(strict_types=1);

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Config;

$fresh  = in_array('--fresh', $argv, true);
$noSeed = in_array('--no-seed', $argv, true);

$host = Config::get('DB_HOST');
$port = Config::get('DB_PORT');
$name = Config::get('DB_NAME');
$user = Config::get('DB_USER');
$pass = Config::get('DB_PASS');
$char = Config::get('DB_CHARSET', 'utf8mb4');

echo "FAOS Installer\n==============\n";
echo "Target: {$user}@{$host}:{$port}  DB={$name}\n\n";

try {
    $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: cannot connect to MySQL server: {$e->getMessage()}\n");
    fwrite(STDERR, "Hint: copy .env.example to .env and set DB_* credentials.\n");
    exit(1);
}

if ($fresh) {
    echo "Dropping database {$name} (--fresh)…\n";
    $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET {$char} COLLATE {$char}_unicode_ci");
$pdo->exec("USE `{$name}`");
echo "Database ready.\n";

function runSqlFile(PDO $pdo, string $file): void
{
    $raw = file_get_contents($file);
    if ($raw === false) {
        throw new RuntimeException("Cannot read {$file}");
    }
    // Strip full-line "--" comments and blank lines, then split on ";" that
    // ends a line. Our schema/seed use no stored routines, so this is safe.
    $clean = [];
    foreach (preg_split('/\R/', $raw) as $line) {
        $t = trim($line);
        if ($t === '' || str_starts_with($t, '--')) {
            continue;
        }
        $clean[] = $line;
    }
    $sql = implode("\n", $clean);

    $buffer = '';
    foreach (explode("\n", $sql) as $line) {
        $buffer .= $line . "\n";
        if (preg_match('/;\s*$/', $line)) {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $pdo->exec(trim($buffer));
    }
}

echo "Applying schema…\n";
runSqlFile($pdo, __DIR__ . '/../database/schema.sql');
echo "Schema applied.\n";

if (!$noSeed) {
    $hasData = (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
    if ($hasData > 0 && !$fresh) {
        echo "Seed skipped (data already present). Use --fresh to reset.\n";
    } else {
        echo "Seeding demo data…\n";
        runSqlFile($pdo, __DIR__ . '/../database/seed.sql');
        echo "Seed complete.\n";
    }
}

// Ensure an APP_KEY exists in .env.
$envPath = __DIR__ . '/../.env';
if (is_file($envPath)) {
    $env = file_get_contents($envPath);
    if (preg_match('/^APP_KEY=\s*$/m', $env)) {
        $key = bin2hex(random_bytes(24));
        $env = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $env);
        file_put_contents($envPath, $env);
        echo "Generated APP_KEY.\n";
    }
}

echo "\nDone. Start the server:\n  php -S 0.0.0.0:8080 server.php\n";
echo "Login: admin / admin123\n";
