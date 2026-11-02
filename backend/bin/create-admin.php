<?php
declare(strict_types=1);

$app = require dirname(__DIR__) . '/src/bootstrap.php';

$login = $argv[1] ?? '';
$password = $argv[2] ?? '';
$name = $argv[3] ?? 'Руководитель';

if ($login === '' || strlen($password) < 8) {
    fwrite(STDERR, "Usage: php create-admin.php <login> <password> [full_name]\n");
    exit(1);
}

$exists = $app->db->fetch('SELECT id FROM users WHERE login = :l', ['l' => $login]);
if ($exists) {
    fwrite(STDERR, "User already exists\n");
    exit(1);
}

$id = $app->users->createAdmin($login, $password, $name);
fwrite(STDOUT, "Admin created id={$id}\n");
