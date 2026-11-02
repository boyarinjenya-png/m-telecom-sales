<?php
declare(strict_types=1);

use MTelecom\Sales\App;
use MTelecom\Sales\Core\Config;
use MTelecom\Sales\Core\Database;
use MTelecom\Sales\Core\Env;

$root = dirname(__DIR__, 2);

spl_autoload_register(function (string $class) use ($root): void {
    $prefix = 'MTelecom\\Sales\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $root . '/backend/src/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Env::load($root . '/.env');
Env::load($root . '/backend/config/.env');

$tz = Env::get('APP_TIMEZONE', 'Asia/Almaty') ?? 'Asia/Almaty';
date_default_timezone_set($tz);

$config = Config::fromEnv($root);
$db = Database::connect($config);

return new App($config, $db, $root);
