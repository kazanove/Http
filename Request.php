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
use Uri\InvalidUriException;
use Uri\Rfc3986\Uri;

/**
 * HTTP-запрос.
 *
 * Инкапсулирует:
 * - $_GET;
 * - $_POST;
 * - $_COOKIE;
 * - $_SERVER;
 * - $_FILES;
 * - заголовки;
 * - JSON-тело.
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
     * Возвращает объект URI PHP 8.5.
     * @throws InvalidUriException
     */
    public function getUriObject(): Uri
    {
        $scheme = $this->isHttps() ? 'https' : 'http';
        $host = $this->headers->get('Host') ?? 'localhost';
        $requestUri = $this->server->get('REQUEST_URI') ?? '/';

        return new Uri($scheme . '://' . $host . $requestUri);
    }

    public function getQueryString(): string
    {
        $uri = $this->server->get('REQUEST_URI') ?? '/';
        $query = parse_url($uri, PHP_URL_QUERY);

        return is_string($query) ? $query : '';
    }

    public function getHost(): string
    {
        return $this->headers->get('Host') ?? 'localhost';
    }

    public function isHttps(): bool
    {
        $https = $this->server->get('HTTPS');

        if ($https !== null && $https !== 'off') {
            return true;
        }

        return $this->server->get('HTTP_X_FORWARDED_PROTO') === 'https';
    }

    public function getIp(): string
    {
        return $this->server->get('REMOTE_ADDR') ?? '0.0.0.0';
    }

    public function isAjax(): bool
    {
        return strtolower($this->headers->get('X-Requested-With') ?? '') === 'xmlhttprequest';
    }

    /**
     * Возвращает данные JSON-тела запроса.
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

    /**
     * Возвращает один загруженный файл.
     */
    public function file(string $key): ?UploadedFile
    {
        return $this->files->get($key);
    }

    /**
     * Возвращает массив файлов.
     */
    public function files(string $key = ''): array
    {
        return $this->files->all($key);
    }

    public function hasFile(string $key): bool
    {
        return $this->file($key) !== null;
    }

    /**
     * Разбирает тело запроса как JSON.
     *
     * Если тело не пустое и JSON невалиден, выбрасывается исключение.
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