<?php
declare(strict_types=1);

/**
 * Installer entry for https://mtelecom.kz/sales/install.php
 * This file only loads backend/public/install.php. It is not the installer itself.
 */
$target = __DIR__ . '/backend/public/install.php';
if (!is_file($target)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Не найден backend/public/install.php. Загрузите полный комплект в каталог /sales/.';
    exit;
}
require $target;
