<?php

declare(strict_types=1);

namespace CodeX\Http\Response;

/**
 * Хранилище HTTP-заголовков ответа.
 */
class Header
{
    private array $parameters = [];

    /**
     * Устанавливает заголовок.
     * Удаляет управляющие символы для защиты от HTTP Response Splitting.
     */
    public function set(string $name, string $value): void
    {
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

    /**
     * Приводит имя заголовка к формату X-Frame-Options.
     */
    private function normalizeName(string $name): string
    {
        $name = strtolower($name);
        $name = str_replace(['-', '_'], ' ', $name);
        $name = ucwords($name);

        return str_replace(' ', '-', $name);
    }
}