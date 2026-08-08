<?php

declare(strict_types=1);

namespace CodeX\Http\Request;

/**
 * Обёртка над GET/POST-данными.
 *
 * Глобальный trim намеренно не используется,
 * чтобы не повредить пароли, форматированный текст и другие значимые пробелы.
 */
readonly class Input
{
    public function __construct(private array $params)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->params);
    }

    public function all(): array
    {
        return $this->params;
    }

    /**
     * Возвращает строковое значение, если оно скалярное.
     */
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        return $default;
    }

    /**
     * Возвращает целое число.
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        if (is_numeric($value)) {
            return (int)$value;
        }

        return $default;
    }

    /**
     * Возвращает массив.
     */
    public function getArray(string $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? $value : [];
    }
}