<?php
declare(strict_types=1);

namespace CodeX\Http\Response;

class Header
{
    private array $parameters = [];

    public function set(string $name, string $value): void
    {
        $name = str_replace(["\r", "\n", "\t"], '', $name);
        $value = str_replace(["\r", "\n", "\t"], '', $value);

        $normalizedName = $this->normalizeName($name);

        $this->parameters[$normalizedName] = $value;
    }

    public function get(string $key): ?string
    {
        return $this->parameters[$this->normalizeName($key)] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->parameters[$this->normalizeName($key)]);
    }

    public function all(): array
    {
        return $this->parameters;
    }

    public function remove(string $key): void
    {
        unset($this->parameters[$this->normalizeName($key)]);
    }

    public function clear(): void
    {
        $this->parameters = [];
    }

    private function normalizeName(string $name): string
    {
        // Использование Pipe Operator (PHP 8.5)
        return $name
                |> strtolower(...)
                |> (static fn($x) => str_replace(['-', '_'], ' ', $x))
                |> ucwords(...)
                |> (static fn($x) => str_replace(' ', '-', $x));
    }
}