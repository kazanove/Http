<?php

declare(strict_types=1);

namespace CodeX\Http\Middleware;

use CodeX\Contract\Middleware;
use CodeX\Contract\RateLimit\Store;
use CodeX\Http\Middleware\RateLimit\FileStore;
use CodeX\Http\Request;
use CodeX\Http\Response;
use Throwable;

readonly class RateLimit implements Middleware
{
    private Store $store;

    public function __construct(
        private int    $maxAttempts = 60,
        private int    $decaySeconds = 60,
        private string $prefix = 'ratelimit',
        ?Store         $store = null,
        string         $storagePath = ''
    ) {
        $this->store = $store ?? new FileStore($storagePath);
    }

    /**
     * @throws Throwable
     */
    public function handle(Request $request, callable $next): Response
    {
        $key = $this->resolveRequestSignature($request);
        $now = time();

        $data = $this->store->read($key);

        // Если окно истекло — сбрасываем счётчик
        if ($data !== null && ($data['expires_at'] ?? 0) <= $now) {
            $data = null;
        }

        $attempts = $data['attempts'] ?? 0;

        // Превышение лимита
        if ($attempts >= $this->maxAttempts) {
            $retryAfter = max(1, ($data['expires_at'] ?? $now) - $now);

            $response = new Response();
            $response->setStatus(429);
            $response->header->set('Content-Type', 'text/html; charset=utf-8');
            $response->header->set('Retry-After', (string) $retryAfter);
            $response->header->set('X-RateLimit-Limit', (string) $this->maxAttempts);
            $response->header->set('X-RateLimit-Remaining', '0');
            $response->content = 'Слишком много запросов. Попробуйте позже.';
            return $response;
        }

        // Инкрементируем счётчик
        $newData = [
            'attempts' => $attempts + 1,
            'expires_at' => $data['expires_at'] ?? $now + $this->decaySeconds,
        ];
        $this->store->write($key, $newData);

        // Выполняем следующий middleware / контроллер
        $response = $next($request);

        if ($response instanceof Response) {
            $response->header->set('X-RateLimit-Limit', (string) $this->maxAttempts);
            $response->header->set('X-RateLimit-Remaining', (string) max(0, $this->maxAttempts - $newData['attempts']));
        }

        return $response;
    }

    private function resolveRequestSignature(Request $request): string
    {
        return md5($this->prefix . ':' . $request->getIp() . ':' . $request->getUri());
    }
}