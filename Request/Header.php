<?php
declare(strict_types=1);

namespace CodeX\Http\Request;

class Header
{
    private array $parameters;

    public function __construct()
    {
        if (PHP_SAPI !== 'cli' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if ($headers !== false) {
                foreach ($headers as $name => $value) {
                    $this->parameters[$this->normalizeName($name)] = $value;
                }
                return;
            }
        }

        $this->parameters = $this->normalizeFromServer($_SERVER);
    }

    private function normalizeFromServer(array $source): array
    {
        $headers = [];
        foreach ($source as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = substr($key, 5)
                        |> strtolower(...)
                        |> (static fn($x) => str_replace('_', '-', $x))
                        |> (fn($x) => $this->normalizeName($x));
                $headers[$name] = (string)$value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[$this->normalizeName($key)] = (string)$value;
            }
        }
        return $headers;
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

    private function normalizeName(string $name): string
    {
        return $name
                |> strtolower(...)
                |> (static fn($x) => str_replace(['-', '_'], ' ', $x))
                |> ucwords(...)
                |> (static fn($x) => str_replace(' ', '-', $x));
    }
}