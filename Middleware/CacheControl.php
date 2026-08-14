<?php

declare(strict_types=1);

namespace CodeX\Http\Middleware;

use CodeX\Http\Request;
use CodeX\Http\Response;

class CacheControl implements Middleware
{
    use ResolvesResponse;

    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default' => [
                'max_age' => 0,
                'must_revalidate' => true,
                'public' => false,
            ],
            'static' => [
                'max_age' => 31536000,
                'immutable' => true,
                'public' => true,
            ],
            'api' => [
                'max_age' => 60,
                'stale_while_revalidate' => 30,
                'public' => false,
            ],
            'html' => [
                'no_cache' => true,
                'no_store' => true,
                'must_revalidate' => true,
            ],
        ], $config);
    }

    public function handle(Request $request, callable $next): Response
    {
        // Используем трейт — никаких дублирований
        $response = $this->resolveResponse($next($request));

        $response->header->remove('Expires');

        $path = $request->getUri();
        $contentType = $response->header->get('Content-Type') ?? 'text/html';

        if ($this->isStaticAsset($path, $contentType)) {
            $this->applyStaticCache($response);
        } elseif ($this->isApiRequest($path)) {
            $this->applyApiCache($response);
        } elseif (str_contains($contentType, 'text/html')) {
            $this->applyHtmlCache($response);
        } else {
            $this->applyDefaultCache($response);
        }

        return $response;
    }

    private function isStaticAsset(string $path, string $contentType): bool
    {
        $staticExtensions = [
            'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg',
            'webp', 'woff', 'woff2', 'ttf', 'ico',
        ];

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, $staticExtensions, true)
            || str_contains($contentType, 'image/')
            || str_contains($contentType, 'font/')
            || str_contains($contentType, 'text/css')
            || str_contains($contentType, 'application/javascript');
    }

    private function isApiRequest(string $path): bool
    {
        return str_starts_with($path, '/api/') || str_contains($path, '.json');
    }

    private function applyStaticCache(Response $response): void
    {
        $config = $this->config['static'];

        $directives = [
            'public',
            'max-age=' . $config['max_age'],
        ];

        if ($config['immutable'] ?? false) {
            $directives[] = 'immutable';
        }

        $response->header->set('Cache-Control', implode(', ', $directives));
    }

    private function applyApiCache(Response $response): void
    {
        $config = $this->config['api'];

        $directives = [
            ($config['public'] ?? false) ? 'public' : 'private',
            'max-age=' . $config['max_age'],
        ];

        if (isset($config['stale_while_revalidate'])) {
            $directives[] = 'stale-while-revalidate=' . $config['stale_while_revalidate'];
        }

        $response->header->set('Cache-Control', implode(', ', $directives));
    }

    private function applyHtmlCache(Response $response): void
    {
        $config = $this->config['html'];

        $directives = ['private'];

        if ($config['no_cache'] ?? true) {
            $directives[] = 'no-cache';
        }

        if ($config['no_store'] ?? true) {
            $directives[] = 'no-store';
        }

        if ($config['must_revalidate'] ?? true) {
            $directives[] = 'must-revalidate';
        }

        $response->header->set('Cache-Control', implode(', ', $directives));
        $response->header->set('Pragma', 'no-cache');
    }

    private function applyDefaultCache(Response $response): void
    {
        $config = $this->config['default'];

        $directives = [
            ($config['public'] ?? false) ? 'public' : 'private',
            'max-age=' . $config['max_age'],
        ];

        if ($config['must_revalidate'] ?? false) {
            $directives[] = 'must-revalidate';
        }

        $response->header->set('Cache-Control', implode(', ', $directives));
    }
}