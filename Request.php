<?php

declare(strict_types=1);

namespace CodeX\Http;

use CodeX\Http\Exception\Request as BadRequestException;
use CodeX\Http\Request\Cookie;
use CodeX\Http\Request\FileBag;
use CodeX\Http\Request\Header;
use CodeX\Http\Request\Input;
use CodeX\Http\Request\Server;
use CodeX\Http\Request\UploadedFile;
use JsonException;
use Uri\Rfc3986\Uri;

/**
 * HTTP-запрос.
 *
 * Инкапсулирует входные данные:
 * - $_GET, $_POST, $_COOKIE, $_SERVER, $_FILES;
 * - заголовки запроса;
 * - JSON-тело;
 * - загруженные файлы.
 *
 * Класс остаётся «тонким» DTO: он только читает данные.
 * Решения по безопасности (CSRF, CORS) принимают отдельные сервисы,
 * но используют для этого read-only помощники, объявленные ниже.
 */
final class Request
{
    public readonly Server $server;
    public readonly Input $get;
    public readonly Input $post;
    public readonly Cookie $cookies;
    public readonly Header $headers;
    public readonly FileBag $files;

    private ?array $jsonData = null;
    private bool $jsonLoaded = false;

    public function __construct(
        ?array $server = null,
        ?array $get = null,
        ?array $post = null,
        ?array $cookies = null,
        ?array $files = null
    ) {
        $this->server = new Server($server ?? $_SERVER);
        $this->get = new Input($get ?? $_GET);
        $this->post = new Input($post ?? $_POST);
        $this->cookies = new Cookie($cookies ?? $_COOKIE);
        $this->headers = new Header($server ?? $_SERVER);
        $this->files = new FileBag($files ?? $_FILES);
    }

    // ------------------------------------------------------------------
    // Базовые сведения о запросе
    // ------------------------------------------------------------------

    /**
     * Возвращает HTTP-метод в верхнем регистре.
     */
    public function getMethod(): string
    {
        return strtoupper($this->server->get('REQUEST_METHOD') ?? 'GET');
    }

    /**
     * Возвращает путь запроса без query string.
     */
    public function getUri(): string
    {
        $uri = $this->server->get('REQUEST_URI') ?? '/';
        $path = strtok($uri, '?');

        return $path ?: '/';
    }

    /**
     * Возвращает объект URI (модуль URI из PHP 8.5).
     */
    public function getUriObject(): Uri
    {
        $scheme = $this->isHttps() ? 'https' : 'http';
        $host = $this->headers->get('Host') ?? 'localhost';
        $requestUri = $this->server->get('REQUEST_URI') ?? '/';

        return new Uri($scheme . '://' . $host . $requestUri);
    }

    /**
     * Возвращает строку запроса (query string) без ведущего «?».
     */
    public function getQueryString(): string
    {
        $uri = $this->server->get('REQUEST_URI') ?? '/';
        $query = parse_url($uri, PHP_URL_QUERY);

        return is_string($query) ? $query : '';
    }

    /**
     * Возвращает имя хоста из заголовка Host.
     */
    public function getHost(): string
    {
        return $this->headers->get('Host') ?? 'localhost';
    }

    /**
     * Определяет, выполнен ли запрос по HTTPS.
     *
     * Учитываются как прямой флаг HTTPS, так и заголовок
     * X-Forwarded-Proto для запросов через прокси.
     */
    public function isHttps(): bool
    {
        $https = $this->server->get('HTTPS');

        if ($https !== null && $https !== 'off' && $https !== '') {
            return true;
        }

        return $this->server->get('HTTP_X_FORWARDED_PROTO') === 'https';
    }

    /**
     * Возвращает IP-адрес клиента.
     */
    public function getIp(): string
    {
        return $this->server->get('REMOTE_ADDR') ?? '0.0.0.0';
    }

    /**
     * Определяет AJAX-запрос по заголовку X-Requested-With.
     */
    public function isAjax(): bool
    {
        return strtolower($this->headers->get('X-Requested-With') ?? '')
            === 'xmlhttprequest';
    }

    // ------------------------------------------------------------------
    // JSON-тело запроса
    // ------------------------------------------------------------------

    /**
     * Возвращает данные JSON-тела запроса.
     *
     * @param string $key Ключ первого уровня. Пустая строка вернёт весь массив.
     * @param mixed $default Значение по умолчанию при отсутствии ключа.
     */
    public function json(string $key = '', mixed $default = null): mixed
    {
        if (!$this->jsonLoaded) {
            $this->jsonData = $this->parseJsonBody();
            $this->jsonLoaded = true;
        }

        if ($key === '') {
            return $this->jsonData !== [] ? $this->jsonData : $default;
        }

        return array_key_exists($key, $this->jsonData)
            ? $this->jsonData[$key]
            : $default;
    }

    // ------------------------------------------------------------------
    // Загруженные файлы
    // ------------------------------------------------------------------

    /**
     * Возвращает один загруженный файл по ключу.
     */
    public function file(string $key): ?UploadedFile
    {
        return $this->files->get($key);
    }

    /**
     * Возвращает массив файлов по ключу.
     */
    public function files(string $key = ''): array
    {
        return $this->files->all($key);
    }

    /**
     * Проверяет наличие загруженного файла по ключу.
     */
    public function hasFile(string $key): bool
    {
        return $this->file($key) !== null;
    }

    // ------------------------------------------------------------------
    // Помощники для безопасности (CSRF / CORS)
    //
    // Это read-only методы: они только читают данные запроса.
    // Итоговое решение о допуске принимают классы Csrf и Cors.
    // ------------------------------------------------------------------

    /**
     * Возвращает значение заголовка Origin.
     *
     * Используется CORS-политикой. Пустой заголовок трактуется как null,
     * что означает «запрос не является кросс-доменным».
     */
    public function getOrigin(): ?string
    {
        $origin = $this->headers->get('Origin');

        return ($origin !== null && $origin !== '') ? $origin : null;
    }

    /**
     * Определяет preflight-запрос CORS.
     *
     * Preflight — это OPTIONS-запрос с заголовком Access-Control-Request-Method.
     */
    public function isPreflight(): bool
    {
        return $this->getMethod() === 'OPTIONS'
            && $this->headers->has('Access-Control-Request-Method');
    }

    /**
     * Проверяет, относится ли метод к «безопасным» по RFC 9110.
     *
     * Безопасные методы не предназначены для изменения состояния сервера,
     * поэтому для них не требуется CSRF-проверка.
     */
    public function isSafeMethod(): bool
    {
        return in_array($this->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * Извлекает CSRF-токен из тела запроса или заголовка.
     *
     * Приоритет: сначала поле формы, затем HTTP-заголовок.
     * Пустые значения игнорируются и приводят к возврату null.
     *
     * @param string $fieldName Имя поля в теле запроса.
     * @param string $headerName Имя HTTP-заголовка с токеном.
     */
    public function getCsrfToken(
        string $fieldName = '_csrf_token',
        string $headerName = 'X-CSRF-TOKEN'
    ): ?string {
        $postToken = $this->post->get($fieldName);

        if (is_string($postToken) && $postToken !== '') {
            return $postToken;
        }

        $headerToken = $this->headers->get($headerName);

        return ($headerToken !== null && $headerToken !== '')
            ? $headerToken
            : null;
    }

    // ------------------------------------------------------------------
    // Внутренние методы
    // ------------------------------------------------------------------

    /**
     * Разбирает тело запроса как JSON.
     *
     * Если тело непустое, но JSON некорректен, выбрасывается исключение.
     */
    private function parseJsonBody(): array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false || $raw === '') {
            return [];
        }

        if (!json_validate($raw)) {
            throw new BadRequestException('Тело запроса содержит некорректный JSON.');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new BadRequestException('Не удалось декодировать JSON.', $e);
        }

        return is_array($decoded) ? $decoded : [];
    }
}