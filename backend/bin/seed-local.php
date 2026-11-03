<?php
declare(strict_types=1);

/**
 * Local SQLite fixture for development only.
 * Encoding: UTF-8.
 *
 * Never connects to MySQL/MariaDB. Never seeds production.
 * Credentials come from gitignored backend/config/seed.local.php
 * (or SEED_* in backend/config/.env). Copy seed.local.example.php first.
 */

$backend = dirname(__DIR__);
$root = dirname($backend);

require $backend . '/src/Core/Env.php';

use MTelecom\Sales\Core\Env;

/**
 * @return never
 */
function seed_local_refuse(string $reason): void
{
    fwrite(STDERR, "seed-local.php отказался работать: {$reason}" . PHP_EOL);
    fwrite(STDERR, "Скрипт заполняет только локальную SQLite и физически не подключается к MySQL." . PHP_EOL);
    exit(1);
}

function seed_local_file_value(string $path, string $key): ?string
{
    if (!is_file($path)) {
        return null;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        if (trim(substr($line, 0, $eq)) !== $key) {
            continue;
        }
        $value = trim(substr($line, $eq + 1));
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        return $value;
    }
    return null;
}

/** @param list<string|null> $values */
function seed_local_normalized(array $values): array
{
    $out = [];
    foreach ($values as $value) {
        if ($value === null) {
            continue;
        }
        $trim = strtolower(trim($value));
        if ($trim !== '') {
            $out[] = $trim;
        }
    }
    return $out;
}

Env::load($root . '/.env');
Env::load($root . '/backend/config/.env');

$envFileRoot = $root . '/.env';
$envFileConfig = $root . '/backend/config/.env';

$envFromProcess = getenv('APP_ENV');
$driverFromProcess = getenv('DB_DRIVER');

$envValues = seed_local_normalized([
    ($envFromProcess !== false && $envFromProcess !== '') ? (string) $envFromProcess : null,
    seed_local_file_value($envFileRoot, 'APP_ENV'),
    seed_local_file_value($envFileConfig, 'APP_ENV'),
]);
foreach ($envValues as $envValue) {
    if (in_array($envValue, ['production', 'prod'], true)) {
        seed_local_refuse('APP_ENV указывает на production.');
    }
}

$driverValues = seed_local_normalized([
    ($driverFromProcess !== false && $driverFromProcess !== '') ? (string) $driverFromProcess : null,
    seed_local_file_value($envFileRoot, 'DB_DRIVER'),
    seed_local_file_value($envFileConfig, 'DB_DRIVER'),
]);
foreach ($driverValues as $driverValue) {
    if (in_array($driverValue, ['mysql', 'mariadb', 'pdo_mysql'], true)) {
        seed_local_refuse('DB_DRIVER указывает на MySQL/MariaDB.');
    }
    if ($driverValue !== 'sqlite') {
        seed_local_refuse("неподдерживаемый DB_DRIVER={$driverValue}.");
    }
}

$effectiveEnv = strtolower(trim((string) (Env::get('APP_ENV', 'production') ?? 'production')));
if (!in_array($effectiveEnv, ['local', 'test', 'development', 'dev'], true)) {
    seed_local_refuse("APP_ENV={$effectiveEnv} не является локальным.");
}

$effectiveDriver = strtolower(trim((string) (Env::get('DB_DRIVER', 'mysql') ?? 'mysql')));
if ($effectiveDriver !== 'sqlite') {
    seed_local_refuse("DB_DRIVER={$effectiveDriver}. Разрешена только sqlite.");
}

$seedFile = $root . '/backend/config/seed.local.php';
$fromFile = [];
if (is_file($seedFile)) {
    $loaded = require $seedFile;
    if (is_array($loaded)) {
        $fromFile = $loaded;
    }
}

$adminLogin = trim((string) ($fromFile['admin_login'] ?? Env::get('SEED_ADMIN_LOGIN', '') ?? ''));
$adminPassword = (string) ($fromFile['admin_password'] ?? Env::get('SEED_ADMIN_PASSWORD', '') ?? '');
$adminName = trim((string) ($fromFile['admin_name'] ?? Env::get('SEED_ADMIN_NAME', 'Тестовый руководитель') ?? 'Тестовый руководитель'));
$managerLogin = trim((string) ($fromFile['manager_login'] ?? Env::get('SEED_MANAGER_LOGIN', '') ?? ''));
$managerPassword = (string) ($fromFile['manager_password'] ?? Env::get('SEED_MANAGER_PASSWORD', '') ?? '');
$managerName = trim((string) ($fromFile['manager_name'] ?? Env::get('SEED_MANAGER_NAME', 'Тестовый менеджер') ?? 'Тестовый менеджер'));
$managerPhone = trim((string) ($fromFile['manager_phone'] ?? Env::get('SEED_MANAGER_PHONE', '77010000001') ?? '77010000001'));

if ($adminLogin === '' || $managerLogin === '' || strlen($adminPassword) < 8 || strlen($managerPassword) < 8) {
    seed_local_refuse(
        'нет локальных учётных данных. Скопируйте backend/config/seed.local.example.php в backend/config/seed.local.php и задайте пароли (файл в .gitignore).'
    );
}

$app = require $backend . '/src/bootstrap.php';

if ($app->config->isProduction() || !$app->config->isSqlite() || !$app->db->isSqlite()) {
    seed_local_refuse('после запуска приложения база не является локальной SQLite.');
}

(new MTelecom\Sales\Core\Migrator($app->db))->up();

$now = $app->db->now();

$ensureAdmin = static function (string $login, string $password, string $name) use ($app, $now): array {
    $row = $app->db->fetch('SELECT * FROM users WHERE login = :l', ['l' => $login]);
    if ($row) {
        $app->db->update('users', [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name' => $name,
            'status' => 'active',
            'updated_at' => $now,
        ], ['id' => $row['id']]);
        return $app->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $row['id']]) ?? $row;
    }
    $id = $app->users->createAdmin($login, $password, $name);
    return $app->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]) ?? [];
};

$ensureManager = static function (string $login, string $password, string $name, array $admin) use ($app, $now, $managerPhone): array {
    $row = $app->db->fetch('SELECT * FROM users WHERE login = :l', ['l' => $login]);
    if ($row) {
        $app->db->update('users', [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name' => $name,
            'phone' => $managerPhone,
            'status' => 'active',
            'updated_at' => $now,
        ], ['id' => $row['id']]);
        return $app->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $row['id']]) ?? $row;
    }
    $id = $app->db->insert('users', [
        'uuid' => bin2hex(random_bytes(16)),
        'login' => $login,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'full_name' => $name,
        'phone' => $managerPhone,
        'role' => 'manager',
        'status' => 'active',
        'created_by' => $admin['id'] ?? null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    return $app->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]) ?? [];
};

$admin = $ensureAdmin($adminLogin, $adminPassword, $adminName);
$manager = $ensureManager($managerLogin, $managerPassword, $managerName, $admin);

$existing = $app->db->fetch(
    'SELECT id FROM clients WHERE phone_primary = :p',
    ['p' => '77015550001']
);
if (!$existing) {
    $app->db->insert('clients', [
        'uuid' => bin2hex(random_bytes(16)),
        'manager_id' => $manager['id'],
        'created_by' => $manager['id'],
        'type' => 'person',
        'full_name' => 'Айдар Тестовый',
        'phone_primary' => '77015550001',
        'address' => 'Алматы, тестовый адрес',
        'need' => 'Тестовая потребность: интернет 100 Мбит',
        'current_provider' => 'Другой оператор',
        'status' => 'new',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

fwrite(STDOUT, 'Local SQLite seeded. Driver=' . $app->db->driver() . PHP_EOL);
fwrite(STDOUT, "Admin login: {$adminLogin}" . PHP_EOL);
fwrite(STDOUT, "Manager login: {$managerLogin}" . PHP_EOL);
fwrite(STDOUT, 'Пароли не выводятся. Они хранятся только в backend/config/seed.local.php (не в git).' . PHP_EOL);
