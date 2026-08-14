<?php

declare(strict_types=1);

namespace CodeX\Http\Request;

/**
 * Заголовки HTTP-запроса.
 */
class Header
{
    private array $parameters;

    public function __construct(?array $server = null)
    {
        $server ??= $_SERVER;

        if (PHP_SAPI !== 'cli' && function_exists('getallheaders')) {
            $headers = getallheaders();

            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    $this->parameters[$this->normalizeName((string) $name)] = (string) $value;
                }

                return;
            }
        }

        $this->parameters = $this->normalizeFromServer($server);
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

    private function normalizeFromServer(array $source): array
    {
        $headers = [];

        foreach ($source as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                // ✅ Исправлено: $this->normalizeName(...) вместо $this(...)
                $name = substr((string) $key, 5)
                        |> strtolower(...)
                        |> (static fn($x) => str_replace('_', '-', $x))
                        |> $this->normalizeName(...);

                $headers[$name] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[$this->normalizeName($key)] = (string) $value;
            }
        }

        return $headers;
    }

    private function normalizeName(string $name): string
    {
        // ✅ Применяем Pipe Operator для устранения цепочки присваиваний
        return $name
                |> strtolower(...)
                |> (static fn($x) => str_replace(['-', '_'], ' ', $x))
                |> ucwords(...)
                |> (static fn($x) => str_replace(' ', '-', $x));
    }
}