<?php
declare(strict_types=1);

namespace CodeX\Http\Exception;

use RuntimeException;

/**
 * Исключение для управления HTTP-редиректами.
 * Перехватывается Front Controller'ом для отправки заголовка Location.
 */
class Redirect extends RuntimeException
{
    public function __construct(
        public readonly string $uri,
        public readonly int $statusCode = 302
    ) {
        parent::__construct('HTTP Redirect to '. $uri);
    }
}