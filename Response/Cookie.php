<?php

declare(strict_types=1);

namespace CodeX\Http\Response;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Управление cookie ответа.
 */
class Cookie
{
    /**
     * Допустимые символы имени cookie согласно RFC 6265.
     */
    private const string NAME_REGEX = '/^[!#$%&\'*+\-.0-9A-Z^_`a-z|~]+$/';

    private array $cookies = [];

    public function set(string $name, string $value, array $options = []): self
    {
        if (!preg_match(self::NAME_REGEX, $name)) {
            throw new InvalidArgumentException(
                'Имя cookie не соответствует RFC 6265: ' . $name
            );
        }

        if (isset($options['samesite'])) {
            $options['samesite'] = ucfirst(strtolower((string) $options['samesite']));

            if (!in_array($options['samesite'], ['Strict', 'Lax', 'None'], true)) {
                throw new InvalidArgumentException(
                    'Недопустимое значение SameSite. Допустимы: Strict, Lax, None'
                );
            }

            if ($options['samesite'] === 'None' && empty($options['secure'])) {
                throw new InvalidArgumentException(
                    'Атрибут Secure обязателен при использовании SameSite=None'
                );
            }
        }

        $this->cookies[$name] = [
            'value' => $value,
            'options' => $options,
        ];

        return $this;
    }

    public function setSigned(
        string $name,
        string $value,
        #[SensitiveParameter] string $secret,
        array $options = []
    ): self {
        $signature = hash_hmac('sha256', $value, $secret);
        $signedValue = base64_encode($value) . '|' . $signature;

        return $this->set($name, $signedValue, $options);
    }

    public function remove(string $name, string $path = '/', ?string $domain = null): self
    {
        $this->cookies[$name] = [
            'value' => '',
            'options' => [
                'expires' => time() - 3600,
                'path' => $path,
                'domain' => $domain,
            ],
        ];

        return $this;
    }

    public function all(): array
    {
        return $this->cookies;
    }

    public function clear(): void
    {
        $this->cookies = [];
    }

    /**
     * Проверяет подписанное значение cookie.
     *
     * @return string|false Возвращает исходное значение или false при ошибке подписи.
     */
    public static function verifySigned(
        string $rawValue,
        #[SensitiveParameter] string $secret
    ): string|false {
        if (!str_contains($rawValue, '|')) {
            return false;
        }

        [$encodedValue, $signature] = explode('|', $rawValue, 2);

        $value = base64_decode($encodedValue, true);

        if ($value === false) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $value, $secret);

        return hash_equals($expectedSignature, $signature) ? $value : false;
    }
}