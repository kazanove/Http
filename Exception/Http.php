<?php

declare(strict_types=1);

namespace CodeX\Http\Exception;

use RuntimeException;
use Throwable;

/**
 * Базовое HTTP-исключение.
 */
class Http extends RuntimeException
{
    public function __construct(
        string $message = '',
        private readonly int $statusCode = 500,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}