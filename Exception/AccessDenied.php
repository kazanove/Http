<?php

declare(strict_types=1);

namespace CodeX\Http\Exception;

use Throwable;

/**
 * Ошибка 403: доступ запрещён.
 */
class AccessDenied extends Http
{
    public function __construct(
        string $message = 'Доступ запрещён.',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 403, $previous);
    }
}