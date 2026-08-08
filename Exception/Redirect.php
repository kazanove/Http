<?php

declare(strict_types=1);

namespace CodeX\Http\Exception;

/**
 * Исключение редиректа.
 *
 * Вместо exit и ручной отправки заголовков приложение
 * должно перехватить это исключение в Front Controller.
 */
class Redirect extends Http
{
    public function __construct(
        public readonly string $uri,
        int $statusCode = 302
    ) {
        parent::__construct('Выполняется HTTP-редирект.', $statusCode);
    }
}