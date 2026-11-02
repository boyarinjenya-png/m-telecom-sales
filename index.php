<?php
declare(strict_types=1);

/**
 * Front controller for https://mtelecom.kz/sales/
 * Keep this file in httpdocs/sales together with backend/public/index.php.
 */
$target = __DIR__ . '/backend/public/index.php';
if (!is_file($target)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Не найден backend/public/index.php. Загрузите полный комплект в каталог /sales/.';
    exit;
}
require $target;
