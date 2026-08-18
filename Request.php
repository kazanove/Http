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

final class Request
{
    public readonly Server $server;
    public readonly Input $get;
    public readonly Input $post;
    public readonly Cookie $cookies;
    public readonly Header $headers;
    public readonly FileBag $files;

    /**
     * Список доверенных прокси-серверов.
     * Только если запрос пришёл от доверенного прокси,
     * заголовки X-Forwarded-For и X-Real-IP считаются достоверными.
     *
     * @var array<int, string>
     */
    private readonly array $trustedProxies;

    private ?array $jsonData = null;
    private bool $jsonLoaded = false;

    public function __construct(
        ?array $server = null,
        ?array $get = null,
        ?array $post = null,
        ?array $cookies = null,
        ?array $files = null,
        array $trustedProxies = []
    ) {
        $this->server = new Server($server ?? $_SERVER);
        $this->get = new Input($get ?? $_GET);
        $this->post = new Input($post ?? $_POST);
        $this->cookies = new Cookie($cookies ?? $_COOKIE);
        $this->headers = new Header($server ?? $_SERVER);
        $this->files = new FileBag($files ?? $_FILES);
        $this->trustedProxies = $trustedProxies;
    }

    public function getMethod(): string
    {
        return strtoupper($this->server->get('REQUEST_METHOD') ?? 'GET');
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

        if ($https !== null && $https !== 'off' && $https !== '') {
            return true;
        }

        return $this->server->get('HTTP_X_FORWARDED_PROTO') === 'https';
    }

    /**
     * Возвращает IP-адрес клиента с учётом доверенных прокси.
     *
     * Если запрос пришёл НЕ от доверенного прокси, возвращается
     * только REMOTE_ADDR. Это защищает от подделки X-Forwarded-For.
     */
    public function getIp(): string
    {
        $remoteAddr = $this->server->get('REMOTE_ADDR') ?? '0.0.0.0';

        // Если доверенные прокси не настроены или запрос не от прокси
        if ($this->trustedProxies === [] || !in_array($remoteAddr, $this->trustedProxies, true)) {
            return $remoteAddr;
        }

        // Запрос от доверенного прокси — проверяем X-Forwarded-For
        $forwardedFor = $this->headers->get('X-Forwarded-For');

        if ($forwardedFor !== null) {
            $ips = explode(',', $forwardedFor);
            $clientIp = trim($ips[0]);

            if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                return $clientIp;
            }
        }

        // Fallback на X-Real-IP (nginx)
        $realIp = $this->headers->get('X-Real-IP');

        if ($realIp !== null && filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }

        return $remoteAddr;
    }

    public function isAjax(): bool
    {
        return strtolower($this->headers->get('X-Requested-With') ?? '')
            === 'xmlhttprequest';
    }

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

    public function file(string $key): ?UploadedFile
    {
        return $this->files->get($key);
    }

    public function files(string $key = ''): array
    {
        return $this->files->all($key);
    }

    public function hasFile(string $key): bool
    {
        return $this->file($key) !== null;
    }

    public function getOrigin(): ?string
    {
        $origin = $this->headers->get('Origin');

        return ($origin !== null && $origin !== '') ? $origin : null;
    }

    public function isPreflight(): bool
    {
        return $this->getMethod() === 'OPTIONS'
            && $this->headers->has('Access-Control-Request-Method');
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

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