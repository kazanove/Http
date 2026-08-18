<?php

declare(strict_types=1);

namespace CodeX\Http\Middleware;

use CodeX\Http\Request;
use CodeX\Http\Response;
use JsonException;
use RuntimeException;
use Throwable;

class RateLimit implements Middleware
{
    private string $storagePath;

    public function __construct(
        private readonly int    $maxAttempts = 60,
        private readonly int    $decaySeconds = 60,
        private readonly string $prefix = 'ratelimit',
        string                  $storagePath = ''
    ) {
        $this->storagePath = $storagePath !== ''
            ? $storagePath
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'codex_ratelimit';

        if (!is_dir($this->storagePath) && !mkdir($this->storagePath, 0755, true) && !is_dir($this->storagePath)) {
            throw new RuntimeException(
                'Не удалось создать директорию для хранения данных RateLimit: ' . $this->storagePath
            );
        }
    }

    /**
     * @throws Throwable
     */
    public function handle(Request $request, callable $next): Response
    {
        $key = $this->resolveRequestSignature($request);
        $filePath = $this->storagePath . DIRECTORY_SEPARATOR . $key . '.json';

        // 1. Читаем данные и блокируем файл
        $result = $this->readDataAndLock($filePath);

        // Fail-open: если не удалось открыть файл или получить блокировку,
        // пропускаем проверку, чтобы не блокировать легитимных пользователей.
        if ($result === null) {
            return $next($request);
        }

        $data = $result['data'];
        $now = time();

        try {
            // Если окно истекло — сбрасываем счётчик
            if ($data !== null && ($data['expires_at'] ?? 0) <= $now) {
                $data = null;
            }

            $attempts = $data['attempts'] ?? 0;

            // Превышение лимита
            if ($attempts >= $this->maxAttempts) {
                $retryAfter = max(1, ($data['expires_at'] ?? $now) - $now);

                // Снимаем блокировку без записи, так как данные не изменились
                $this->releaseLock($result);

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

            // 2. Записываем новые данные и снимаем блокировку
            // СТРОГО ДО выполнения самого запроса ($next)
            $this->writeDataAndUnlock($filePath, $result, $newData);

            // 3. Выполняем следующий middleware / контроллер (файл уже разблокирован)
            $response = $next($request);

            if ($response instanceof Response) {
                $response->header->set('X-RateLimit-Limit', (string) $this->maxAttempts);
                $response->header->set('X-RateLimit-Remaining', (string) max(0, $this->maxAttempts - $newData['attempts']));
            }

            return $response;

        } catch (Throwable $e) {
            // Гарантированно снимаем блокировку при любых непредвиденных ошибках
            $this->releaseLock($result);
            throw $e;
        }
    }

    private function resolveRequestSignature(Request $request): string
    {
        return md5($this->prefix . ':' . $request->getIp() . ':' . $request->getUri());
    }

    /**
     * Читает данные и накладывает эксклюзивную блокировку (LOCK_EX).
     * Возвращает массив с дескриптором файла и данными, либо null при ошибке.
     */
    private function readDataAndLock(string $filePath): ?array
    {
        $fp = fopen($filePath, 'cb+');
        if ($fp === false) {
            return null;
        }

        // Блокируем файл для чтения и записи
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return null;
        }

        $content = stream_get_contents($fp);

        if ($content === false || $content === '') {
            // Оставляем блокировку, она снимется в writeData или releaseLock
            return ['_fp' => $fp, 'data' => null];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return ['_fp' => $fp, 'data' => $data];
        } catch (JsonException) {
            return ['_fp' => $fp, 'data' => null];
        }
    }

    /**
     * Записывает данные, сбрасывает буфер и снимает блокировку.
     */
    private function writeDataAndUnlock(string $filePath, array $result, array $data): void
    {
        $fp = $result['_fp'] ?? null;

        if ($fp === null) {
            $fp = fopen($filePath, 'cb+');
            if ($fp === false) {
                return;
            }
            flock($fp, LOCK_EX);
        }

        try {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_THROW_ON_ERROR));
            fflush($fp);
        } catch (JsonException $e) {
            error_log('RateLimit: ошибка сериализации данных: ' . $e->getMessage());
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Просто снимает блокировку и закрывает файл без записи.
     * Используется, когда данные не нужно обновлять (например, при 429 Too Many Requests).
     */
    private function releaseLock(array $result): void
    {
        $fp = $result['_fp'] ?? null;
        if ($fp !== null) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}