<?php

declare(strict_types=1);

namespace CodeX\Http\Middleware;

use CodeX\Http\Response;

class SecurityHeaders
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'x_content_type_options' => 'nosniff',
            'x_frame_options' => 'DENY', // DENY, SAMEORIGIN или false
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'permissions_policy' => 'camera=(), microphone=(), geolocation=()',

            'csp' => [
                'enabled' => true,
                'directives' => [
                    'default-src' => ["'self'"],
                    'script-src' => ["'self'", "'unsafe-inline'"],
                    'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
                    'font-src' => ["'self'", 'https://fonts.gstatic.com'],
                    'img-src' => ["'self'", 'data:', 'https:'],
                    'frame-ancestors' => ["'none'"],
                ],
            ],
        ], $config);
    }

    public function handle(array $params, callable $next): Response
    {
        $result = $next($params);

        // ИСПРАВЛЕНО: Убран Service Locator (app()), исправлен баг приведения типов.
        if (!$result instanceof Response) {
            $response = new Response();
            $response->content = (string) $result;
        } else {
            $response = $result;
        }

        // 1. Удаляем опасные и устаревшие заголовки
        $response->header->remove('X-Powered-By');
        $response->header->remove('X-XSS-Protection');
        $response->header->remove('Expires');

        // 2. Базовые заголовки безопасности
        if ($this->config['x_content_type_options']) {
            $response->header->set('X-Content-Type-Options', $this->config['x_content_type_options']);
        }

        if ($this->config['referrer_policy']) {
            $response->header->set('Referrer-Policy', $this->config['referrer_policy']);
        }

        if ($this->config['permissions_policy']) {
            $response->header->set('Permissions-Policy', $this->config['permissions_policy']);
        }

        // 3. X-Frame-Options (Fallback для старых браузеров)
        if (!empty($this->config['x_frame_options'])) {
            $response->header->set('X-Frame-Options', $this->config['x_frame_options']);
        }

        // 4. Content Security Policy
        if ($this->config['csp']['enabled'] ?? false) {
            // ИСПРАВЛЕНО: Теперь используется конфиг и метод buildCspHeader, а не хардкод
            $cspString = $this->buildCspHeader($this->config['csp']['directives']);
            $response->header->set('Content-Security-Policy', $cspString);
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
            if (is_array($sources) && !empty($sources)) {
                $parts[] = $directive . ' ' . implode(' ', $sources);
            } elseif (is_string($sources)) {
                $parts[] = $directive . ' ' . $sources;
            }
        }
        return implode('; ', $parts);
    }
}