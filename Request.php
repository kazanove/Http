<?php
declare(strict_types=1);

namespace CodeX\Http;

use JsonException;
use Uri\InvalidUriException;
use Uri\Rfc3986\Uri;
use InvalidArgumentException;

class Request
{
    public readonly Request\Header $headers;
    public readonly Request\Server $server;
    public readonly Request\Input $post;
    public readonly Request\Input $get;
    public readonly Request\Cookie $cookie;

    public function __construct()
    {
        $this->post = new Request\Input($_POST);
        $this->get = new Request\Input($_GET);
        $this->cookie = new Request\Cookie($_COOKIE);
        $this->headers = new Request\Header();
        $this->server = new Request\Server($_SERVER);
        $this->jsonData = null;
    }

    public function getMethod(): string
    {
        return $this->server->get('REQUEST_METHOD') ?? 'GET';
    }

    public function getUri(): string
    {
        $uri = $this->server->get('REQUEST_URI') ?? '/';
        $path = strtok($uri, '?');
        return $path ?: '/';
    }

    /**
     * @throws InvalidUriException
     */
    public function getUriObject(): Uri
    {
        $scheme = ($this->server->get('HTTPS') && $this->server->get('HTTPS') !== 'off') ? 'https' : 'http';
        $host = $this->headers->get('Host') ?? 'localhost';
        $requestUri = $this->server->get('REQUEST_URI') ?? '/';

        // Использование нативного модуля URI (PHP 8.5)
        return new Uri($scheme . '://' . $host . $requestUri);
    }

    public function getQueryString(): string
    {
        $uri = $this->server->get('REQUEST_URI') ?? '/';
        $query = parse_url($uri, PHP_URL_QUERY);
        return is_string($query) ? $query : '';
    }

    private ?array $jsonData;

    public function json(string $key = '', mixed $default = null): mixed
    {
        if ($this->jsonData === null) {
            $this->jsonData = $this->parseJsonBody();
        }

        if ($key === '') {
            return $this->jsonData !== [] ? $this->jsonData : $default;
        }

        return array_key_exists($key, $this->jsonData)
            ? $this->jsonData[$key]
            : $default;
    }

    /**
     * ИЗМЕНЕНО: Теперь метод выбрасывает исключение при невалидном JSON,
     * вместо того чтобы молча возвращать пустой массив.
     * Это позволяет клиенту API получить корректный ответ 400 Bad Request.
     */
    private function parseJsonBody(): array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false || $raw === '') {
            return [];
        }

        // json_validate() - PHP 8.3
        if (!json_validate($raw)) {
            throw new InvalidArgumentException('Получен синтаксически некорректный JSON в теле запроса.');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Ошибка декодирования JSON: ' . $e->getMessage(), 0, $e);
        }

        return is_array($decoded) ? $decoded : [];
    }
}