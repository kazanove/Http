<?php
declare(strict_types=1);

namespace CodeX\Http\Request;

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

    public function all(): array
    {
        return $this->params;
    }
}