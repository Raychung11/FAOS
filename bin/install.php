<?php
/**
 * FAOS installer.
 *   php bin/install.php            apply migrations (baseline + pending) + seed
 *   php bin/install.php --fresh    DROP and recreate the database (dev only!)
 *   php bin/install.php --no-seed  schema/migrations only, no demo data
 *
 * This is a thin wrapper: the real work (idempotent, no-data-loss) is done by
 * bin/migrate.php. Use migrate.php directly in production.
 */
declare(strict_types=1);

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Config;

$fresh  = in_array('--fresh', $argv, true);
$noSeed = in_array('--no-seed', $argv, true);

$host = Config::get('DB_HOST');
$port = Config::get('DB_PORT');
$name = Config::get('DB_NAME');
$char = Config::get('DB_CHARSET', 'utf8mb4');

echo "FAOS Installer\n==============\n";
echo 'Target: ' . Config::get('DB_USER') . "@{$host}:{$port}  DB={$name}\n\n";

try {
    $pdo = new PDO("mysql:host={$host};port={$port}", Config::get('DB_USER'), Config::get('DB_PASS'), [
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
echo "Database ready. Running migrations…\n\n";

// Single source of truth: delegate to the migration runner.
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/migrate.php');
if (!$noSeed) {
    $cmd .= ' --seed';
}
passthru($cmd, $code);
if ($code !== 0) {
    exit($code);
}

// Ensure an APP_KEY exists in .env.
$envPath = BASE_PATH . '/.env';
if (is_file($envPath)) {
    $env = file_get_contents($envPath);
    if (preg_match('/^APP_KEY=\s*$/m', $env)) {
        $env = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . bin2hex(random_bytes(24)), $env);
        file_put_contents($envPath, $env);
        echo "Generated APP_KEY.\n";
    }
}

echo "\nDone. Start the server:\n  php -S 0.0.0.0:8080 server.php\n";
echo "Login: admin / admin123\n";
