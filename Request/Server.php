<?php

declare(strict_types=1);

namespace CodeX\Http\Request;

/**
 * Обёртка над $_SERVER.
 */
readonly class Server
{
    public function __construct(private array $params)
    {
    }

    public function get(string $key): ?string
    {
        $value = $this->params[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->params);
    }

    public function all(): array
    {
        return $this->params;
    }
}