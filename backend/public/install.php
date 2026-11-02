<?php
declare(strict_types=1);

/**
 * First-run installer for shared hosting.
 * Disabled automatically after backend/public/install.lock is created.
 */

$root = dirname(__DIR__, 2);
$lock = __DIR__ . '/install.lock';
$autoload = dirname(__DIR__) . '/src/bootstrap.php';

if (is_file($lock)) {
    http_response_code(403);
    echo 'Установка уже выполнена. Удалите install.lock только если осознанно запускаете повторную установку.';
    exit;
}

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function env_quote(string $v): string
{
    return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $v) . '"';
}

$errors = [];
$ok = [];
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';

$appUrl = $_POST['APP_URL'] ?? 'https://mtelecom.kz/sales';
$dbHost = $_POST['DB_HOST'] ?? 'localhost';
$dbPort = $_POST['DB_PORT'] ?? '3306';
$dbName = $_POST['DB_NAME'] ?? 'mtelecom_sales';
$dbUser = $_POST['DB_USER'] ?? '';
$dbPass = $_POST['DB_PASS'] ?? '';
$adminLogin = $_POST['ADMIN_LOGIN'] ?? 'admin';
$adminName = $_POST['ADMIN_NAME'] ?? 'Руководитель';
$adminPass = $_POST['ADMIN_PASS'] ?? '';
$basePath = $_POST['APP_BASE_PATH'] ?? '/sales';

if ($posted) {
    if (PHP_VERSION_ID < 80200) {
        $errors[] = 'Нужен PHP 8.2 или новее. Сейчас: ' . PHP_VERSION;
    }
    foreach (['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'json'] as $ext) {
        if (!extension_loaded($ext)) {
            $errors[] = 'Не подключено расширение PHP: ' . $ext;
        }
    }
    if (strlen($adminPass) < 8) {
        $errors[] = 'Пароль руководителя должен быть не короче 8 символов.';
    }
    if ($dbUser === '' || $dbName === '') {
        $errors[] = 'Укажите имя базы и пользователя MySQL.';
    }
    $basePath = trim($basePath);
    if ($basePath === '') {
        $urlPath = parse_url($appUrl, PHP_URL_PATH);
        if (is_string($urlPath) && $urlPath !== '/' && $urlPath !== '') {
            $basePath = rtrim($urlPath, '/');
        }
    } else {
        $basePath = '/' . trim($basePath, '/');
        if ($basePath === '/') {
            $basePath = '';
        }
    }

    $storage = $root . '/backend/storage';
    $publicDir = __DIR__;
    foreach ([$storage, $storage . '/logs', $storage . '/uploads', $publicDir] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            $errors[] = 'Нет каталога и не удалось создать: ' . $dir;
        } elseif (!is_writable($dir)) {
            $errors[] = 'Нет прав на запись: ' . $dir;
        }
    }

    if (!$errors) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
            $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $ok[] = 'Подключение к MySQL успешно.';
        } catch (Throwable $e) {
            $errors[] = 'Не удалось подключиться к базе. Проверьте имя БД, пользователя и пароль.';
        }
    }

    if (!$errors) {
        $key = bin2hex(random_bytes(32));
        $env = implode("\n", [
            'APP_NAME=' . env_quote('M-TELECOM SALES'),
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL=' . env_quote($appUrl),
            'APP_BASE_PATH=' . env_quote($basePath),
            'APP_TIMEZONE=Asia/Almaty',
            'APP_KEY=' . env_quote($key),
            '',
            'DB_DRIVER=mysql',
            'DB_HOST=' . env_quote($dbHost),
            'DB_PORT=' . env_quote($dbPort),
            'DB_NAME=' . env_quote($dbName),
            'DB_USER=' . env_quote($dbUser),
            'DB_PASS=' . env_quote($dbPass),
            'DB_CHARSET=utf8mb4',
            '',
            'TOKEN_TTL_HOURS=720',
            'LOGIN_MAX_ATTEMPTS=5',
            'LOGIN_WINDOW_MINUTES=15',
            'UPLOAD_MAX_MB=5',
            'ANDROID_VERSION_CODE=1',
            'ANDROID_VERSION_NAME=1.0.0',
            'ANDROID_APK_URL=',
            '',
        ]);
        $envFile = $root . '/.env';
        if (!is_dir($root . '/backend/config')) {
            mkdir($root . '/backend/config', 0775, true);
        }
        if (file_put_contents($envFile, $env) === false) {
            $alt = $root . '/backend/config/.env';
            if (file_put_contents($alt, $env) === false) {
                $errors[] = 'Не удалось записать .env. Проверьте права на запись.';
            } else {
                $ok[] = 'Конфигурация сохранена в backend/config/.env';
            }
        } else {
            $ok[] = 'Конфигурация сохранена в .env';
        }
    }
}

if ($posted && !$errors) {
    try {
        require_once $root . '/backend/src/Core/Env.php';
        require_once $root . '/backend/src/Core/Config.php';
        require_once $root . '/backend/src/Core/Database.php';
        require_once $root . '/backend/src/Core/Migrator.php';
        \MTelecom\Sales\Core\Env::load($root . '/.env');
        \MTelecom\Sales\Core\Env::load($root . '/backend/config/.env');
        $config = \MTelecom\Sales\Core\Config::fromEnv($root);
        $db = \MTelecom\Sales\Core\Database::connect($config);
        (new \MTelecom\Sales\Core\Migrator($db))->up();
        $ok[] = 'Таблицы базы данных созданы.';

        $exists = $db->fetch('SELECT id FROM users WHERE role = :r', ['r' => 'admin']);
        if (!$exists) {
            $db->insert('users', [
                'uuid' => sprintf(
                    '%s%s-%s-%s-%s-%s%s%s',
                    ...str_split(bin2hex(random_bytes(16)), 4)
                ),
                'login' => $adminLogin,
                'password_hash' => password_hash($adminPass, PASSWORD_DEFAULT),
                'full_name' => $adminName,
                'phone' => null,
                'role' => 'admin',
                'status' => 'active',
                'created_by' => null,
                'last_login_at' => null,
                'created_at' => $db->now(),
                'updated_at' => $db->now(),
            ]);
            $ok[] = 'Учётная запись руководителя создана.';
        } else {
            $ok[] = 'Руководитель уже существует, новый не создан.';
        }

        $db->insert('app_versions', [
            'version_code' => 1,
            'version_name' => '1.0.0',
            'apk_url' => '',
            'changelog' => 'Первая рабочая версия M-TELECOM SALES',
            'is_stable' => 1,
            'created_at' => $db->now(),
        ]);

        if (file_put_contents($lock, date('c'), LOCK_EX) === false) {
            throw new RuntimeException('Таблицы созданы, но не удалось записать install.lock. Закройте install.php вручную.');
        }
        @chmod($lock, 0644);
        $ok[] = 'Установщик заблокирован (install.lock). Повторный запуск запрещён.';
        $ok[] = 'Откройте кабинет руководителя и смените пароль после первого входа.';
    } catch (Throwable $e) {
        $errors[] = 'Ошибка миграции: ' . $e->getMessage();
    }
}

?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Установка — M-TELECOM SALES</title>
  <style>
    :root { --navy:#0B3A5B; --cyan:#00A3E0; --bg:#F4F7FA; }
    body { font-family: Segoe UI, system-ui, sans-serif; background: var(--bg); margin:0; color:#1A202C; }
    .wrap { max-width: 640px; margin: 40px auto; background:#fff; padding: 32px; border-radius: 16px; box-shadow: 0 10px 40px rgba(11,58,91,.08); }
    h1 { margin:0 0 8px; color: var(--navy); font-size: 24px; }
    p.lead { color:#4A5568; margin:0 0 24px; }
    label { display:block; font-size:13px; font-weight:600; margin: 12px 0 6px; }
    input { width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #D0D7DE; border-radius:8px; font-size:15px; }
    button { margin-top:20px; background:var(--navy); color:#fff; border:0; padding:12px 18px; border-radius:10px; font-weight:600; cursor:pointer; width:100%; }
    .err { background:#FED7D7; color:#9B2C2C; padding:10px 12px; border-radius:8px; margin:8px 0; }
    .ok { background:#C6F6D5; color:#276749; padding:10px 12px; border-radius:8px; margin:8px 0; }
    .row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    @media (max-width: 640px) { .row { grid-template-columns:1fr; } .wrap { margin:12px; padding:20px; } }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>M-TELECOM SALES</h1>
    <p class="lead">Установка кабинета продаж. Основной сайт mtelecom.kz не изменяется.</p>
    <?php foreach ($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>
    <?php foreach ($ok as $e): ?><div class="ok"><?= h($e) ?></div><?php endforeach; ?>
    <?php if (!is_file($lock)): ?>
    <form method="post" autocomplete="off">
      <label>Адрес кабинета</label>
      <input name="APP_URL" value="<?= h($appUrl) ?>">
      <label>Базовый путь (если не поддомен, например /sales)</label>
      <input name="APP_BASE_PATH" value="<?= h($basePath) ?>" placeholder="/sales">
      <div class="row">
        <div><label>MySQL хост</label><input name="DB_HOST" value="<?= h($dbHost) ?>"></div>
        <div><label>Порт</label><input name="DB_PORT" value="<?= h($dbPort) ?>"></div>
      </div>
      <label>Имя отдельной базы</label>
      <input name="DB_NAME" value="<?= h($dbName) ?>">
      <label>Пользователь БД</label>
      <input name="DB_USER" value="<?= h($dbUser) ?>">
      <label>Пароль БД</label>
      <input name="DB_PASS" type="password" value="">
      <div class="row">
        <div><label>Логин руководителя</label><input name="ADMIN_LOGIN" value="<?= h($adminLogin) ?>"></div>
        <div><label>ФИО руководителя</label><input name="ADMIN_NAME" value="<?= h($adminName) ?>"></div>
      </div>
      <label>Пароль руководителя</label>
      <input name="ADMIN_PASS" type="password" minlength="8" required>
      <button type="submit">Создать базу и кабинет</button>
    </form>
    <?php else: ?>
      <p><a href="./">Перейти в кабинет</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
