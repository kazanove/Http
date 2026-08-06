<?php
declare(strict_types=1);

namespace CodeX\Http\Exception;

use RuntimeException;

/**
 * Исключение для управления HTTP-редиректами.
 * Перехватывается Front Controller'ом для отправки заголовка Location.
 */

class AccessDenied extends RuntimeException
{
    public function __construct(string $message = 'Доступ запрещен', int $code = 403)
    {
        parent::__construct($message, $code);
    }
}