<?php

declare(strict_types=1);

namespace CodeX\Http\Exception;

use Throwable;

/**
 * Ошибка 400: некорректный запрос.
 */
class Request extends Http
{
    public function __construct(
        string $message = 'Некорректный запрос.',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 400, $previous);
    }
}