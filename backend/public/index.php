<?php
declare(strict_types=1);

try {
    $app = require dirname(__DIR__) . '/src/bootstrap.php';
    $app->run();
} catch (Throwable $e) {
    $lock = __DIR__ . '/install.lock';
    if (!is_file($lock)) {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (str_contains($uri, '/api/')) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'Система ещё не установлена',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $prefix = str_starts_with($uri, '/sales') ? '/sales' : '';
        header('Location: ' . $prefix . '/install.php', true, 302);
        exit;
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Внутренняя ошибка сервера.';
    exit;
}
