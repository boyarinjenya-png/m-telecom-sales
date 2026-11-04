<?php
declare(strict_types=1);

/**
 * Run one official Migrator::up() against a local MariaDB test database.
 * Does not load .env, bootstrap, or any remote host.
 */

$opts = getopt('', ['migrator:', 'name:']);
$migratorFile = $opts['migrator'] ?? '';
$name = $opts['name'] ?? '';
$host = getenv('MIG_HOST') ?: '';
$port = getenv('MIG_PORT') ?: '3306';
$user = getenv('MIG_USER') ?: '';
$pass = getenv('MIG_PASS');
$pass = $pass === false ? '' : $pass;

if (!is_string($migratorFile) || !is_file($migratorFile)) {
    fwrite(STDERR, "Migrator file is missing\n");
    exit(2);
}
if (!in_array($host, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Refusing host that is not local\n");
    exit(2);
}
if (!in_array($name, ['mig_main', 'mig_dup'], true)) {
    fwrite(STDERR, "Refusing database name\n");
    exit(2);
}
if ($user === '') {
    fwrite(STDERR, "MIG_USER is empty\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
require $root . '/backend/src/Core/Database.php';
require $migratorFile;

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$db = new MTelecom\Sales\Core\Database($pdo);
(new MTelecom\Sales\Core\Migrator($db))->up();
echo 'MIGRATOR_OK version=' . MTelecom\Sales\Core\Migrator::VERSION . PHP_EOL;
