<?php

declare(strict_types=1);

namespace CodeX\Http\Security;

use CodeX\Http\Exception\AccessDenied;
use CodeX\Http\Request;
use CodeX\Http\Response;

/**
 * Обработка CORS (Cross-Origin Resource Sharing).
 *
 * Класс отвечает за проверку Origin и установку соответствующих заголовков.
 * Чтение Origin и определение preflight-запроса выполняются через Request.
 */
class Cors
{
    public const int DEFAULT_MAX_AGE = 3600;

    private const array DEFAULT_METHODS = [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ];

    public const array DEFAULT_HEADERS = [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'Accept',
        'Origin',
        'X-CSRF-TOKEN',
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
        $this->maxAge = isset($config['max_age'])
            ? max(0, (int)$config['max_age'])
            : self::DEFAULT_MAX_AGE;
        $this->blockAll = (bool)($config['block_all'] ?? false);

        $this->allowedOrigins = $this->normalizeOrigins($config['origins'] ?? []);
        $this->allowedMethods = $this->normalizeList($config['methods'] ?? self::DEFAULT_METHODS);
        $this->allowedHeaders = $this->normalizeList($config['headers'] ?? self::DEFAULT_HEADERS);
        $this->exposedHeaders = $this->normalizeList($config['expose_headers'] ?? []);
    }

    /**
     * Обрабатывает CORS для входящего запроса.
     *
     * @return bool true, если это preflight и дальнейшая обработка маршрута не нужна.
     *
     * @throws AccessDenied если Origin запрещён и включён block_all.
     */
    public function handle(Request $request, Response $response): bool
    {
        // Заголовок Origin отсутствует — это обычный (не кросс-доменный) запрос.
        $origin = $request->getOrigin();

        if ($origin === null) {
            return false;
        }

        if (!$this->validateOrigin($origin)) {
            if ($this->blockAll) {
                throw new AccessDenied('Запрос заблокирован политикой CORS.');
            }

            return false;
        }

        $this->applyHeaders($response, $origin, $request);

        // Для preflight достаточно вернуть 204 и заголовки, без тела.
        if ($request->isPreflight()) {
            $response->setStatus(204);

            return true;
        }

        return false;
    }

    /**
     * Проверяет, разрешён ли метод.
     */
    #[\NoDiscard]
    public function isMethodAllowed(string $method): bool
    {
        return in_array(strtoupper($method), $this->allowedMethods, true);
    }

    /**
     * Проверяет, разрешён ли заголовок.
     */
    #[\NoDiscard]
    public function isHeaderAllowed(string $header): bool
    {
        $header = strtolower($header);

        return in_array(
            $header,
            array_map(strtolower(...), $this->allowedHeaders),
            true
        );
    }

    /**
     * Возвращает текущую конфигурацию (для отладки/логирования).
     */
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

    // ------------------------------------------------------------------
    // Внутренние методы
    // ------------------------------------------------------------------

    /**
     * Проверяет Origin по списку разрешённых, включая шаблоны с «*».
     */
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
                $regex = '/^'
                    . str_replace('\*', '.*', preg_quote($pattern, '/'))
                    . '$/i';

                if (preg_match($regex, $origin)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Устанавливает CORS-заголовки в ответ.
     */
    private function applyHeaders(Response $response, string $origin, Request $request): void
    {
        // При credentials=true нельзя использовать «*» — возвращаем конкретный Origin.
        if (!$this->allowCredentials && in_array('*', $this->allowedOrigins, true)) {
            $response->header->set('Access-Control-Allow-Origin', '*');
        } else {
            $response->header->set('Access-Control-Allow-Origin', $origin);
            $response->header->set('Vary', 'Origin');
        }

        if ($this->allowCredentials) {
            $response->header->set('Access-Control-Allow-Credentials', 'true');
        }

        if ($this->exposedHeaders !== []) {
            $response->header->set(
                'Access-Control-Expose-Headers',
                implode(', ', $this->exposedHeaders)
            );
        }

        // Дополнительные заголовки нужны только для preflight-запросов.
        if ($request->isPreflight()) {
            $response->header->set(
                'Access-Control-Allow-Methods',
                implode(', ', $this->allowedMethods)
            );
            $response->header->set(
                'Access-Control-Allow-Headers',
                implode(', ', $this->allowedHeaders)
            );

            if ($this->maxAge > 0) {
                $response->header->set('Access-Control-Max-Age', (string)$this->maxAge);
            }
        }
    }

    /**
     * Нормализует список разрешённых Origin.
     * Если присутствует «*», список сводится к единственному элементу.
     */
    private function normalizeOrigins(array $origins): array
    {
        $clean = array_map(
            static fn($origin) => strtolower(trim((string)$origin)),
            $origins
        );

        if (in_array('*', $clean, true)) {
            return ['*'];
        }

        $clean = array_filter($clean, static fn($origin) => $origin !== '');

        return array_values(array_unique($clean));
    }

    /**
     * Нормализует произвольный список строк (методы, заголовки).
     */
    private function normalizeList(array $list): array
    {
        $list = array_map(
            static fn($item) => trim((string)$item),
            $list
        );

        $list = array_filter($list, static fn($item) => $item !== '');

        return array_values(array_unique($list));
    }
}