<?php
declare(strict_types=1);

/**
 * MariaDB 10.4 migration sequence on synthetic data.
 *
 * Schema 4 comes from Migrator at ba44ed12e456dab3bc49eccfdfcd69d1fb0dea77.
 * Schema 5 comes from Migrator at 1d9d6873c480ec464cd83a3b7017cd8525803d88.
 * Schema 6 comes from Migrator at 35ae9106c98b2d2c25f5b401f244aaa19ae21878.
 *
 * Does not read .env and does not open a connection except 127.0.0.1.
 */

$root = dirname(__DIR__, 2);
$once = __DIR__ . '/mariadb-migrate-once.php';
$host = getenv('MIG_HOST') ?: '';
$port = getenv('MIG_PORT') ?: '3306';
$user = getenv('MIG_USER') ?: '';
$pass = getenv('MIG_PASS');
$pass = $pass === false ? '' : $pass;

$commits = [
    '4' => 'ba44ed12e456dab3bc49eccfdfcd69d1fb0dea77',
    '5' => '1d9d6873c480ec464cd83a3b7017cd8525803d88',
    '6' => '35ae9106c98b2d2c25f5b401f244aaa19ae21878',
];

$failed = 0;
$passed = 0;

function say(string $line): void
{
    echo $line . PHP_EOL;
}

function check(bool $ok, string $message): void
{
    global $failed, $passed;
    if ($ok) {
        $passed++;
        say('  OK  ' . $message);
        return;
    }
    $failed++;
    say('  FAIL  ' . $message);
}

function finish(): void
{
    global $failed, $passed;
    say('RESULT passed=' . $passed . ' failed=' . $failed);
    exit($failed === 0 ? 0 : 1);
}

if (!in_array($host, ['127.0.0.1', 'localhost'], true) || $user === '') {
    fwrite(STDERR, "Refusing to run without a local MIG_HOST and MIG_USER\n");
    exit(2);
}

function server_pdo(string $host, string $port, string $user, string $pass, ?string $db = null): PDO
{
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
    if ($db !== null) {
        $dsn .= ';dbname=' . $db;
    }
    $last = null;
    for ($i = 0; $i < 30; $i++) {
        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            $last = $e;
            sleep(2);
        }
    }
    throw new RuntimeException('MariaDB connection failed: ' . ($last ? $last->getMessage() : 'unknown'));
}

function recreate(PDO $server, string $name): void
{
    if (!in_array($name, ['mig_main', 'mig_dup'], true)) {
        throw new RuntimeException('Refusing to recreate database');
    }
    $server->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    $server->exec(
        'CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
}

function extract_migrator(string $root, string $rev, string $dest): void
{
    $proc = proc_open(
        ['git', 'show', $rev . ':backend/src/Core/Migrator.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('git show failed to start');
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0 || !is_string($out) || !str_contains($out, 'class Migrator')) {
        throw new RuntimeException('git show ' . $rev . ' failed: ' . $err);
    }
    file_put_contents($dest, $out);
}

function assert_source(string $file, string $version, bool $unique): void
{
    $src = (string) file_get_contents($file);
    if (!str_contains($src, "const VERSION = '" . $version . "'")) {
        throw new RuntimeException('Migrator file is not version ' . $version);
    }
    $hasUnique = str_contains($src, 'function ensureUniqueClientIin');
    if ($hasUnique !== $unique) {
        throw new RuntimeException('Unexpected unique-index code for version ' . $version);
    }
    $hasIin = str_contains($src, "ensureColumn('clients', 'iin'");
    if ($version === '4' && $hasIin) {
        throw new RuntimeException('Version 4 source unexpectedly adds clients.iin');
    }
    if ($version !== '4' && !$hasIin) {
        throw new RuntimeException('Version ' . $version . ' source does not add clients.iin');
    }
}

/** @return array{code:int,out:string} */
function run_migrator(string $root, string $once, string $file, string $name): array
{
    $proc = proc_open(
        [PHP_BINARY, $once, '--migrator=' . $file, '--name=' . $name],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    if (!is_resource($proc)) {
        return ['code' => 1, 'out' => 'failed to start migrator process'];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($proc), 'out' => (string) $out . (string) $err];
}

function versions(PDO $pdo): array
{
    return $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
}

function has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    return $stmt->fetch() !== false;
}

function client_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
}

/** @return list<string> */
function client_uuids(PDO $pdo): array
{
    return $pdo->query('SELECT uuid FROM clients ORDER BY uuid')->fetchAll(PDO::FETCH_COLUMN);
}

function iin_of(PDO $pdo, string $uuid): ?string
{
    $stmt = $pdo->prepare('SELECT iin FROM clients WHERE uuid = :u');
    $stmt->execute(['u' => $uuid]);
    $row = $stmt->fetch();
    if ($row === false || $row['iin'] === null) {
        return null;
    }
    return (string) $row['iin'];
}

/** @return array<string,int> */
function table_counts(PDO $pdo): array
{
    $tables = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $counts = [];
    foreach ($tables as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table)) {
            continue;
        }
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    ksort($counts);
    return $counts;
}

function index_rows(PDO $pdo): array
{
    return $pdo->query(
        "SHOW INDEX FROM `clients` WHERE Key_name = 'idx_clients_iin_unique'"
    )->fetchAll();
}

function insert_client(PDO $pdo, string $uuid, string $name, string $phone, ?string $iin = null): void
{
    $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    if ($iin === null) {
        $stmt = $pdo->prepare(
            'INSERT INTO clients (uuid, type, status, full_name, phone_primary, phone_primary_norm, created_at, updated_at)
             VALUES (:uuid, :type, :status, :name, :phone, :norm, :created, :updated)'
        );
        $stmt->execute([
            'uuid' => $uuid,
            'type' => 'person',
            'status' => 'new',
            'name' => $name,
            'phone' => $phone,
            'norm' => $phone,
            'created' => $now,
            'updated' => $now,
        ]);
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO clients (uuid, type, status, full_name, phone_primary, phone_primary_norm, iin, created_at, updated_at)
         VALUES (:uuid, :type, :status, :name, :phone, :norm, :iin, :created, :updated)'
    );
    $stmt->execute([
        'uuid' => $uuid,
        'type' => 'person',
        'status' => 'new',
        'name' => $name,
        'phone' => $phone,
        'norm' => $phone,
        'iin' => $iin,
        'created' => $now,
        'updated' => $now,
    ]);
}

$tmp = sys_get_temp_dir() . '/mtelecom-mig-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "Cannot create temp directory\n");
    exit(2);
}

try {
    $server = server_pdo($host, $port, $user, $pass);
    $versionRow = $server->query('SELECT VERSION() AS v')->fetch();
    $mariaVersion = (string) ($versionRow['v'] ?? '');
    say('MARIADB_VERSION=' . $mariaVersion);
    check(str_contains($mariaVersion, '10.4.'), 'server version is MariaDB 10.4');
    if (!str_contains($mariaVersion, '10.4.')) {
        finish();
    }

    $files = [];
    foreach ($commits as $version => $rev) {
        $version = (string) $version;
        $dest = $tmp . '/Migrator-v' . $version . '.php';
        extract_migrator($root, $rev, $dest);
        assert_source($dest, $version, $version === '6');
        $files[$version] = $dest;
        say('SOURCE v' . $version . ' ' . $rev);
    }

    $uuidA = 'a0000000-0000-4000-8000-000000000001';
    $uuidB = 'a0000000-0000-4000-8000-000000000002';
    $uuidC = 'a0000000-0000-4000-8000-000000000003';
    $keptIin = '900101300123';

    say('TEST A schema 4 to 5');
    recreate($server, 'mig_main');
    $boot = run_migrator($root, $once, $files['4'], 'mig_main');
    say($boot['out']);
    check($boot['code'] === 0 && str_contains($boot['out'], 'MIGRATOR_OK version=4'), 'version 4 migrator created the base schema');
    $main = server_pdo($host, $port, $user, $pass, 'mig_main');
    check(versions($main) === ['4'], 'schema_migrations contains only version 4');
    check(!has_column($main, 'clients', 'iin'), 'clients.iin is absent before version 5');
    insert_client($main, $uuidA, 'Синтетический клиент А', '77000000001');
    insert_client($main, $uuidB, 'Синтетический клиент Б', '77000000002');
    $beforeA = client_count($main);
    $uuidsBeforeA = client_uuids($main);
    $to5 = run_migrator($root, $once, $files['5'], 'mig_main');
    say($to5['out']);
    check($to5['code'] === 0 && str_contains($to5['out'], 'MIGRATOR_OK version=5'), 'version 5 migrator finished');
    check(versions($main) === ['4', '5'], 'schema version 5 is recorded and version 4 remains');
    check(has_column($main, 'clients', 'iin'), 'clients.iin exists after version 5');
    check(client_count($main) === $beforeA && client_uuids($main) === $uuidsBeforeA, 'client rows are unchanged by version 5');
    check(index_rows($main) === [], 'unique IIN index does not exist at version 5');

    say('TEST B schema 5 to 6');
    $main->prepare('UPDATE clients SET iin = :iin WHERE uuid = :u')->execute(['iin' => '', 'u' => $uuidA]);
    $main->prepare('UPDATE clients SET iin = :iin WHERE uuid = :u')->execute(['iin' => '   ', 'u' => $uuidB]);
    insert_client($main, $uuidC, 'Синтетический клиент В', '77000000003', $keptIin);
    $beforeB = client_count($main);
    $uuidsBeforeB = client_uuids($main);
    $to6 = run_migrator($root, $once, $files['6'], 'mig_main');
    say($to6['out']);
    check($to6['code'] === 0 && str_contains($to6['out'], 'MIGRATOR_OK version=6'), 'version 6 migrator finished');
    check(versions($main) === ['4', '5', '6'], 'schema version 6 is recorded');
    $index = index_rows($main);
    check(count($index) === 1 && (string) ($index[0]['Non_unique'] ?? $index[0]['non_unique'] ?? '') === '0', 'unique index idx_clients_iin_unique exists');
    check(iin_of($main, $uuidA) === null && iin_of($main, $uuidB) === null, 'blank IINs are stored as NULL');
    check(iin_of($main, $uuidC) === $keptIin, 'non-empty IIN is preserved');
    check(client_count($main) === $beforeB && client_uuids($main) === $uuidsBeforeB, 'client rows are unchanged by version 6');

    say('TEST C repeat version 6');
    $countsBefore = table_counts($main);
    $again = run_migrator($root, $once, $files['6'], 'mig_main');
    say($again['out']);
    check($again['code'] === 0 && str_contains($again['out'], 'MIGRATOR_OK version=6'), 'second version 6 run finished');
    check(table_counts($main) === $countsBefore, 'second run does not change row counts');
    check(count(index_rows($main)) === 1, 'second run does not duplicate the unique index');
    check(versions($main) === ['4', '5', '6'], 'second run does not add another schema version');
    check(iin_of($main, $uuidC) === $keptIin && iin_of($main, $uuidA) === null, 'second run keeps IIN values');

    say('TEST D duplicate IIN stops version 6');
    recreate($server, 'mig_dup');
    $dupBoot = run_migrator($root, $once, $files['4'], 'mig_dup');
    $dup5 = run_migrator($root, $once, $files['5'], 'mig_dup');
    say($dupBoot['out']);
    say($dup5['out']);
    check($dup5['code'] === 0, 'duplicate database reached version 5');
    $dup = server_pdo($host, $port, $user, $pass, 'mig_dup');
    $dup1 = 'b0000000-0000-4000-8000-000000000001';
    $dup2 = 'b0000000-0000-4000-8000-000000000002';
    $dupIin = '900101300999';
    insert_client($dup, $dup1, 'Синтетический дубль 1', '77000000011', $dupIin);
    insert_client($dup, $dup2, 'Синтетический дубль 2', '77000000012', $dupIin);
    $dupBefore = client_uuids($dup);
    $dupRun = run_migrator($root, $once, $files['6'], 'mig_dup');
    say($dupRun['out']);
    check($dupRun['code'] !== 0 && str_contains($dupRun['out'], 'повторяющийся ИИН'), 'version 6 stops on a duplicate IIN');
    check(index_rows($dup) === [], 'unique index is not created when duplicates exist');
    check(versions($dup) === ['4', '5'], 'version 6 is not recorded and version 5 remains');
    check(client_uuids($dup) === $dupBefore && client_count($dup) === 2, 'both duplicate clients remain');
    check(iin_of($dup, $dup1) === $dupIin && iin_of($dup, $dup2) === $dupIin, 'duplicate IIN values remain stored');

    say('TEST E database rejects a second copy of an IIN');
    $beforeE = client_count($main);
    $rejected = false;
    $rejectDetail = '';
    try {
        insert_client($main, 'a0000000-0000-4000-8000-000000000004', 'Синтетический клиент Г', '77000000004', $keptIin);
    } catch (PDOException $e) {
        $rejected = true;
        $rejectDetail = $e->getMessage();
    }
    say($rejectDetail);
    check($rejected && str_contains($rejectDetail, 'idx_clients_iin_unique'), 'MariaDB rejects the duplicate IIN insert');
    check(client_count($main) === $beforeE && iin_of($main, $uuidC) === $keptIin, 'rejected insert does not add a client');
} catch (Throwable $e) {
    say('HARNESS ERROR ' . $e->getMessage());
    $failed++;
}

finish();
