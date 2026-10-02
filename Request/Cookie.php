<?php

declare(strict_types=1);

namespace CodeX\Http\Request;

use CodeX\Http\Response\Cookie as ResponseCookie;
use Countable;
use NoDiscard;
use SensitiveParameter;

/**
 * Обёртка над $_COOKIE.
 *
 * Предоставляет типобезопасный доступ к значениям cookie
 * и проверку подписанных токенов.
 */
readonly class Cookie implements Countable
{
    public function __construct(
        private array $params,
    ) {
    }

    /**
     * Возвращает значение по ключу.
     */
    #[NoDiscard]
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->params)
            ? $this->params[$key]
            : $default;
    }

    /**
     * Возвращает строковое значение.
     */
    #[NoDiscard]
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * Возвращает целочисленное значение.
     */
    #[NoDiscard]
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * Возвращает булево значение.
     */
    #[NoDiscard]
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Проверяет наличие ключа.
     */
    #[NoDiscard]
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->params);
    }

    /**
     * Возвращает все cookie.
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function all(): array
    {
        return $this->params;
    }

    /**
     * Возвращает количество cookie.
     */
    #[NoDiscard]
    public function count(): int
    {
        return count($this->params);
    }

    /**
     * Проверяет подписанное значение и возвращает исходную строку.
     */
    #[NoDiscard]
    public function getSigned(
        string $key,
        #[SensitiveParameter] string $secret,
        string $default = '',
    ): string {
        $raw = $this->get($key);

        if (!is_string($raw) || $raw === '') {
            return $default;
        }

        $value = ResponseCookie::verifySigned($raw, $secret);

        return $value === false ? $default : $value;
    }
}