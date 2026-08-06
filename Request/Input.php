<?php
declare(strict_types=1);

namespace CodeX\Http\Request;

readonly class Input
{
    public function __construct(private array $params)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $this->params)) {
            return $default;
        }

        return $this->params[$key];
    }

    public function all(): array
    {
        return $this->params;
    }
}