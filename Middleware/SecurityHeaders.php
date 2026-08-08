<?php

declare(strict_types=1);

namespace CodeX\Http\Middleware;

use CodeX\Http\Request;
use CodeX\Http\Response;
use CodeX\Http\Security\Context;
use JsonException;

/**
 * Добавляет безопасные HTTP-заголовки.
 */
class SecurityHeaders implements Middleware
{
    private array $config;

    public function __construct(
        private readonly Context $securityContext,
        array $config = []
    ) {
        $this->config = array_merge([
            'x_content_type_options' => 'nosniff',
            'x_frame_options' => 'DENY',
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'permissions_policy' => 'camera=(), microphone=(), geolocation=()',
            'hsts' => [
                'enabled' => true,
                'max_age' => 31536000,
                'include_subdomains' => true,
                'preload' => false,
            ],
            'csp' => [
                'enabled' => true,
                'directives' => [
                    'default-src' => ["'self'"],
                    'script-src' => ["'self'"],
                    'style-src' => ["'self'", 'https://fonts.googleapis.com'],
                    'font-src' => ["'self'", 'https://fonts.gstatic.com'],
                    'img-src' => ["'self'", 'data:', 'https:'],
                    'frame-ancestors' => ["'none'"],
                ],
            ],
        ], $config);
    }

    public function handle(Request $request, callable $next): Response
    {
        $result = $next($request);

        if ($result instanceof Response) {
            $response = $result;
        } else {
            $response = new Response();

            if ($result === null) {
                $response->content = '';
            } elseif (is_scalar($result)) {
                $response->content = (string)$result;
            } else {
                try {
                    $response->content = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    $response->header->set('Content-Type', 'application/json; charset=utf-8');
                } catch (JsonException) {
                    $response->content = '';
                }
            }
        }

        // Удаляем устаревшие и потенциально опасные заголовки.
        $response->header->remove('X-Powered-By');
        $response->header->remove('X-XSS-Protection');
        $response->header->remove('Expires');

        // Базовые заголовки безопасности.
        if (!empty($this->config['x_content_type_options'])) {
            $response->header->set('X-Content-Type-Options', $this->config['x_content_type_options']);
        }

        if (!empty($this->config['referrer_policy'])) {
            $response->header->set('Referrer-Policy', $this->config['referrer_policy']);
        }

        if (!empty($this->config['permissions_policy'])) {
            $response->header->set('Permissions-Policy', $this->config['permissions_policy']);
        }

        if (!empty($this->config['x_frame_options'])) {
            $response->header->set('X-Frame-Options', $this->config['x_frame_options']);
        }

        // HSTS имеет смысл только для HTTPS.
        if (($this->config['hsts']['enabled'] ?? false) && $request->isHttps()) {
            $hsts = 'max-age=' . ($this->config['hsts']['max_age'] ?? 31536000);

            if ($this->config['hsts']['include_subdomains'] ?? false) {
                $hsts .= '; includeSubDomains';
            }

            if ($this->config['hsts']['preload'] ?? false) {
                $hsts .= '; preload';
            }

            $response->header->set('Strict-Transport-Security', $hsts);
        }

        // Content Security Policy с nonce вместо unsafe-inline.
        if ($this->config['csp']['enabled'] ?? false) {
            $directives = $this->config['csp']['directives'] ?? [];

            $nonce = $this->securityContext->getCspDirective();

            if (isset($directives['script-src']) && is_array($directives['script-src'])) {
                $directives['script-src'] = $this->removeUnsafeInline($directives['script-src']);
                $directives['script-src'][] = $nonce;
            }

            if (isset($directives['style-src']) && is_array($directives['style-src'])) {
                $directives['style-src'] = $this->removeUnsafeInline($directives['style-src']);
                $directives['style-src'][] = $nonce;
            }

            $csp = $this->buildCspHeader($directives);

            if ($csp !== '') {
                $response->header->set('Content-Security-Policy', $csp);
            }
        }

        return $response;
    }

    /**
     * Собирает строку CSP из массива директив.
     */
    private function buildCspHeader(array $directives): string
    {
        $parts = [];

        foreach ($directives as $directive => $sources) {
            if (is_array($sources) && $sources !== []) {
                $sources = array_values(array_unique($sources));
                $parts[] = $directive . ' ' . implode(' ', $sources);
            } elseif (is_string($sources) && $sources !== '') {
                $parts[] = $directive . ' ' . $sources;
            }
        }

        return implode('; ', $parts);
    }

    /**
     * Удаляет unsafe-inline, так как вместо него используется nonce.
     */
    private function removeUnsafeInline(array $sources): array
    {
        return array_values(
            array_filter(
                $sources,
                static fn($source) => $source !== "'unsafe-inline'"
            )
        );
    }
}