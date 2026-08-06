<?php

declare(strict_types=1);

namespace CodeX\Http;

use CodeX\Http\Exception\Redirect;
use CodeX\Http\Response\Cookie;
use CodeX\Http\Response\Header;
use InvalidArgumentException;

class Response
{
    public readonly Header $header;
    public readonly Cookie $cookies;

    /**
     * Тело ответа.
     *
     * При установке автоматически выполняется trim().
     */
    public string $content = '' {
        set => trim($value);
    }

    /**
     * HTTP-статус ответа.
     *
     * Чтение доступно извне, изменение — только внутри класса.
     */
    public private(set) int $statusCode = 200;

    public function __construct()
    {
        $this->header = new Header();
        $this->cookies = new Cookie();
    }

    public function setStatus(int $code): void
    {
        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException(
                'Код состояния HTTP должен быть в диапазоне 100-599'
            );
        }

        $this->statusCode = $code;
    }

    /**
     * Подготавливает редирект.
     *
     * Вместо exit выбрасывается RedirectException,
     * который должно перехватить HTTP-ядро приложения.
     */
    public function redirect(string $uri, int $statusCode = 302): never
    {
        $uri = $this->normalizeRedirectUri($uri);

        throw new Redirect($uri, $statusCode);
    }

    public function send(): self
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (headers_sent($filename, $line)) {
            error_log(
                'Заголовки уже отправлены в файле ' . $filename . ' на строке ' . $line
            );

            echo $this->content;

            return $this;
        }

        // Базовые заголовки безопасности.
        if (!$this->header->has('X-Content-Type-Options')) {
            $this->header->set('X-Content-Type-Options', 'nosniff');
        }

        if (!$this->header->has('X-Frame-Options')) {
            $this->header->set('X-Frame-Options', 'SAMEORIGIN');
        }

        if (!$this->header->has('Referrer-Policy')) {
            $this->header->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        http_response_code($this->statusCode);

        foreach ($this->header->all() as $name => $value) {
            header($name . ': ' . $value);
        }

        foreach ($this->cookies->all() as $name => $data) {
            setcookie($name, $data['value'], $data['options']);
        }

        header_remove('X-Powered-By');

        echo $this->content;

        return $this;
    }

    /**
     * Нормализует URI для редиректа.
     *
     * Разрешены:
     * - относительные пути вида /profile, profile, /admin/dashboard;
     * - абсолютные HTTPS-ссылки.
     *
     * Абсолютные ссылки с небезопасной схемой запрещены.
     */
    private function normalizeRedirectUri(string $uri): string
    {
        $uri = trim($uri);

        if ($uri === '') {
            return '/';
        }

        $scheme = parse_url($uri, PHP_URL_SCHEME);

        // Если указана схема, значит ссылка абсолютная.
        if (is_string($scheme)) {
            if (strtolower($scheme) !== 'https') {
                throw new InvalidArgumentException(
                    'Абсолютный URI для редиректа должен использовать HTTPS.'
                );
            }

            return $uri;
        }

        // Protocol-relative ссылки вида //example.com/path потенциально опасны.
        if (str_starts_with($uri, '//')) {
            throw new InvalidArgumentException(
                'Protocol-relative URI для редиректа запрещены.'
            );
        }

        return '/' . ltrim($uri, '/');
    }
}