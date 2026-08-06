<?php
declare(strict_types=1);

namespace CodeX\Http\Request;

readonly class Cookie
{
    public function __construct(private array $params)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->params;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->params);
    }
}