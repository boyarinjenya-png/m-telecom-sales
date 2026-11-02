<?php
declare(strict_types=1);

$app = require dirname(__DIR__) . '/src/bootstrap.php';
$dir = $app->config->get('storage') . '/backups';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$stamp = date('Ymd-His');
$file = $dir . '/backup-' . $stamp . '.sql';

if ($app->db->isSqlite()) {
    $src = (string) $app->config->get('db.sqlite_path');
    copy($src, $dir . '/backup-' . $stamp . '.sqlite');
    fwrite(STDOUT, "SQLite copy saved\n");
    exit(0);
}

$host = $app->config->get('db.host');
$name = $app->config->get('db.name');
$user = $app->config->get('db.user');
$pass = $app->config->get('db.pass');
$port = $app->config->get('db.port');

$cmd = sprintf(
    'mysqldump --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --triggers %s',
    escapeshellarg((string) $host),
    escapeshellarg((string) $port),
    escapeshellarg((string) $user),
    escapeshellarg((string) $pass),
    escapeshellarg((string) $name)
);
exec($cmd . ' > ' . escapeshellarg($file), $out, $code);
if ($code !== 0 || !is_file($file) || filesize($file) < 10) {
    fwrite(STDERR, "mysqldump failed. Use hosting panel backup or phpMyAdmin export.\n");
    exit(1);
}
fwrite(STDOUT, "Saved {$file}\n");
