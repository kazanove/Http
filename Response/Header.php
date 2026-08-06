<?php

declare(strict_types=1);

namespace CodeX\Http\Response;

class Header
{
    private array $parameters = [];

    public function set(string $name, string $value): void
    {
        // Используем chr() для соблюдения правила одинарных кавычек
        $name = str_replace([chr(13), chr(10), chr(9)], '', $name);
        $value = str_replace([chr(13), chr(10), chr(9)], '', $value);

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
        return $name
                |> strtolower(...)
                |> (static fn($x) => str_replace(['-', '_'], ' ', $x))
                |> ucwords(...)
                |> (static fn($x) => str_replace(' ', '-', $x));
    }
}