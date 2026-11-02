<?php
declare(strict_types=1);

namespace MTelecom\Sales\Core;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public int $status;
    /** @var array<string,mixed> */
    public array $extra;

    /** @param array<string,mixed> $extra */
    public function __construct(string $message, int $status = 400, array $extra = [])
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->extra = $extra;
    }

    public static function unauthorized(string $msg = 'Требуется авторизация'): self
    {
        return new self($msg, 401);
    }

    public static function forbidden(string $msg = 'Недостаточно прав'): self
    {
        return new self($msg, 403);
    }

    public static function notFound(string $msg = 'Не найдено'): self
    {
        return new self($msg, 404);
    }

    public static function conflict(string $msg, array $extra = []): self
    {
        return new self($msg, 409, $extra);
    }
}
