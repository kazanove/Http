<?php

declare(strict_types=1);

namespace CodeX\Http\Request;

use CodeX\Http\Response\Cookie as ResponseCookie;
use SensitiveParameter;

/**
 * Обёртка над $_COOKIE.
 */
readonly class Cookie
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

    public function getSigned(
        string $key,
        #[SensitiveParameter] string $secret,
        mixed $default = null
    ): mixed {
        $raw = $this->get($key);

        if (!is_string($raw)) {
            return $default;
        }

        $value = ResponseCookie::verifySigned($raw, $secret);

        return $value === false ? $default : $value;
    }
}