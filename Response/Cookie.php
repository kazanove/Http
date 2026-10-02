<?php

declare(strict_types=1);

namespace CodeX\Http\Response;

use InvalidArgumentException;
use NoDiscard;
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

    /**
     * Опции по умолчанию для безопасности.
     */
    private const array DEFAULT_OPTIONS = [
        'expires' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    /**
     * @var array<string, array{value: string, options: array}>
     */
    private array $cookies = [];

    /**
     * Устанавливает cookie.
     *
     * @throws InvalidArgumentException При недопустимом имени или опциях.
     */
    public function set(string $name, string $value, array $options = []): self
    {
        $this->assertValidName($name);
        $options = $this->normalizeOptions($options);

        $this->cookies[$name] = [
            'value' => $value,
            'options' => $options,
        ];

        return $this;
    }

    /**
     * Устанавливает подписанный cookie.
     * @throws InvalidArgumentException
     */
    public function setSigned(
        string $name,
        string $value,
        #[SensitiveParameter] string $secret,
        array $options = [],
    ): self {
        $encoded = self::base64UrlEncode($value);
        $signature = hash_hmac('sha256', $encoded, $secret);
        $signedValue = $encoded . '|' . $signature;

        return $this->set($name, $signedValue, $options);
    }

    /**
     * Удаляет cookie.
     */
    public function remove(string $name, string $path = '/', ?string $domain = null): self
    {
        $this->assertValidName($name);

        $existing = $this->cookies[$name] ?? null;
        $options = $existing !== null
            ? $existing['options']
            : self::DEFAULT_OPTIONS;

        $options['expires'] = time() - 3600;
        $options['path'] = $path;
        $options['domain'] = $domain ?? ($options['domain'] ?? '');

        $this->cookies[$name] = [
            'value' => '',
            'options' => $options,
        ];

        return $this;
    }

    /**
     * Проверяет, установлен ли cookie с данным именем.
     */
    #[NoDiscard]
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->cookies);
    }

    /**
     * Возвращает все установленные cookie.
     *
     * @return array<string, array{value: string, options: array}>
     */
    #[NoDiscard]
    public function all(): array
    {
        return $this->cookies;
    }

    /**
     * Очищает все cookie.
     */
    public function clear(): void
    {
        $this->cookies = [];
    }

    /**
     * Проверяет подписанное значение.
     *
     * @return string|false Исходное значение или false при ошибке.
     */
    #[NoDiscard]
    public static function verifySigned(
        string $rawValue,
        #[SensitiveParameter] string $secret,
    ): string|false {
        if ($rawValue === '' || !str_contains($rawValue, '|')) {
            return false;
        }

        [$encoded, $signature] = explode('|', $rawValue, 2);

        if ($encoded === '' || $signature === '') {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $encoded, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        return self::base64UrlDecode($encoded);
    }

    // ==========================================
    // ВНУТРЕННИЕ МЕТОДЫ
    // ==========================================

    /**
     * Проверяет допустимость имени cookie.
     *
     * @throws InvalidArgumentException
     */
    private function assertValidName(string $name): void
    {
        if ($name === '' || !preg_match(self::NAME_REGEX, $name)) {
            throw new InvalidArgumentException(
                'Имя cookie не соответствует RFC 6265: ' . $name
            );
        }
    }

    /**
     * Нормализует и валидирует опции.
     *
     * @throws InvalidArgumentException
     */
    private function normalizeOptions(array $options): array
    {
        $options = array_merge(self::DEFAULT_OPTIONS, $options);

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

        // PHP 8.5: поддержка ключа 'partitioned'
        if (isset($options['partitioned']) && !is_bool($options['partitioned'])) {
            throw new InvalidArgumentException(
                'Атрибут partitioned должен быть булевым значением'
            );
        }

        return $options;
    }

    /**
     * URL-safe base64-кодирование.
     *
     * Заменяет +, / на -, _ и убирает символ = для безопасной
     * передачи в cookie и URL.
     */
    private static function base64UrlEncode(string $data): string
    {
        return $data
                |> base64_encode(...)
                |> (static fn($x) => strtr($x, '+/', '-_'))
                |> (static fn($x) => rtrim($x, '='));
    }

    /**
     * URL-safe base64-декодирование.
     */
    private static function base64UrlDecode(string $data): string|false
    {
        $padded = match (strlen($data) % 4) {
            2 => $data . '==',
            3 => $data . '=',
            default => $data,
        };

        return base64_decode(strtr($padded, '-_', '+/'), true);
    }
}