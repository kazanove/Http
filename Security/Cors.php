<?php
declare(strict_types=1);

namespace CodeX\Http\Security;

use CodeX\Http\Request;
use CodeX\Http\Response;
use RuntimeException;

class Cors
{
    public const int DEFAULT_MAX_AGE = 3600;

    private const array DEFAULT_METHODS = [
        'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'QUERY'
    ];

    public const array DEFAULT_HEADERS = [
        'Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'Origin', 'X-CSRF-TOKEN'
    ];

    private bool $allowCredentials;
    private int $maxAge;
    private array $allowedOrigins;
    private array $allowedMethods;
    private array $allowedHeaders;
    private array $exposedHeaders;
    private bool $blockAll;

    public function __construct(array $config = [])
    {
        $this->allowCredentials = (bool)($config['credentials'] ?? false);
        $this->maxAge = isset($config['max_age']) ? max(0, (int)$config['max_age']) : self::DEFAULT_MAX_AGE;
        $this->blockAll = (bool)($config['block_all'] ?? false);

        $this->allowedOrigins = $this->normalizeOrigins($config['origins'] ?? []);
        $this->allowedMethods = $this->normalizeList($config['methods'] ?? self::DEFAULT_METHODS);
        $this->allowedHeaders = $this->normalizeList($config['headers'] ?? self::DEFAULT_HEADERS);
        $this->exposedHeaders = $this->normalizeList($config['expose_headers'] ?? []);
    }

    public function handle(Request $request, Response $response): bool
    {
        $origin = $request->headers->get('Origin');

        if ($origin === null) {
            return false;
        }

        if (!$this->validateOrigin($origin)) {
            if ($this->blockAll) {
                throw new RuntimeException('Запрос заблокирован политикой CORS: недопустимый Origin.');
            }
            return false;
        }

        $this->applyHeaders($response, $origin, $request);

        if ($this->isPreflight($request)) {
            $response->setStatus(204);
            return true;
        }

        return false;
    }

    private function validateOrigin(string $origin): bool
    {
        if (in_array('*', $this->allowedOrigins, true)) {
            return true;
        }

        $originLower = strtolower($origin);

        if (in_array($originLower, $this->allowedOrigins, true)) {
            return true;
        }

        foreach ($this->allowedOrigins as $pattern) {
            if (str_contains($pattern, '*')) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
                if (preg_match($regex, $origin)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isPreflight(Request $request): bool
    {
        return $request->getMethod() === 'OPTIONS'
            && $request->headers->has('Access-Control-Request-Method');
    }

    private function applyHeaders(Response $response, string $origin, Request $request): void
    {
        if (!$this->allowCredentials && in_array('*', $this->allowedOrigins, true)) {
            $response->header->set('Access-Control-Allow-Origin', '*');
        } else {
            $response->header->set('Access-Control-Allow-Origin', $origin);
            $response->header->set('Vary', 'Origin');
        }

        if ($this->allowCredentials) {
            $response->header->set('Access-Control-Allow-Credentials', 'true');
        }

        if (!empty($this->exposedHeaders)) {
            $response->header->set('Access-Control-Expose-Headers', implode(', ', $this->exposedHeaders));
        }

        if ($this->isPreflight($request)) {
            $response->header->set('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods));
            $response->header->set('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));

            if ($this->maxAge > 0) {
                $response->header->set('Access-Control-Max-Age', (string)$this->maxAge);
            }
        }
    }

    private function normalizeOrigins(array $origins): array
    {
        $clean = array_map('strtolower', array_map('trim', $origins));
        if (in_array('*', $clean, true)) {
            return ['*'];
        }
        return $clean
                |> array_unique(...)
                |> (static fn($x) => array_filter($x, static fn($o) => $o !== ''))
                |> array_values(...);
    }

    private function normalizeList(array $list): array
    {
        return $list
                |> array_unique(...)
                |> (static fn($x) => array_map('trim', $x))
                |> (static fn($x) => array_filter($x, static fn($item) => $item !== ''))
                |> array_values(...);
    }

    public function isMethodAllowed(string $method): bool
    {
        return in_array(strtoupper($method), $this->allowedMethods, true);
    }

    public function isHeaderAllowed(string $header): bool
    {
        return in_array(strtolower($header), array_map('strtolower', $this->allowedHeaders), true);
    }

    public function getConfig(): array
    {
        return [
            'origins' => $this->allowedOrigins,
            'methods' => $this->allowedMethods,
            'headers' => $this->allowedHeaders,
            'expose_headers' => $this->exposedHeaders,
            'credentials' => $this->allowCredentials,
            'max_age' => $this->maxAge,
        ];
    }
}