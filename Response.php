<?php

declare(strict_types=1);

namespace CodeX\Http;

use CodeX\Http\Exception\Redirect;
use CodeX\Http\Response\Cookie;
use CodeX\Http\Response\Header;
use finfo;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * HTTP-ответ.
 *
 * Поддерживает:
 * - обычные ответы;
 * - JSON-ответы;
 * - редиректы через исключение;
 * - отправку файлов;
 * - Early Hints / preload через заголовок Link.
 */
class Response
{
    public readonly Header $header;
    public readonly Cookie $cookies;

    /**
     * Тело ответа.
     *
     * Хук свойства PHP 8.4 автоматически убирает лишние пробелы по краям.
     */
    public string $content = '' {
        set => trim($value);
    }

    /**
     * Код ответа доступен для чтения снаружи,
     * но изменяется только внутри класса.
     */
    private(set) int $statusCode = 200;

    /**
     * Путь к файлу, если ответ должен отправить файл.
     */
    private ?string $filePath = null;

    public function __construct()
    {
        $this->header = new Header();
        $this->cookies = new Cookie();
    }

    /**
     * Устанавливает HTTP-статус.
     */
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
     * Вместо exit выбрасывается исключение,
     * которое должно быть обработано HTTP-ядром приложения.
     */
    public function redirect(string $uri, int $statusCode = 302): never
    {
        $uri = $this->normalizeRedirectUri($uri);

        throw new Redirect($uri, $statusCode);
    }

    /**
     * Подготавливает JSON-ответ.
     */
    public function json(mixed $data, int $statusCode = 200): self
    {
        $this->setStatus($statusCode);

        $this->header->set('Content-Type', 'application/json; charset=utf-8');

        try {
            $this->content = json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Не удалось сериализовать ответ в JSON.', 0, $e);
        }

        return $this;
    }

    /**
     * Добавляет заголовок Link для preload/preconnect.
     *
     * @throws InvalidArgumentException При недопустимой схеме URI.
     */
    public function addLink(
        string $uri,
        string $rel = 'preload',
        string $as = '',
        string $type = ''
    ): self {
        // Защита от опасных схем (XSS через заголовок Link)
        if (preg_match('/^(javascript|data|vbscript):/i', $uri)) {
            throw new InvalidArgumentException(
                'Недопустимая схема URI для заголовка Link.'
            );
        }

        // Экранирование специальных символов RFC 8288
        $safeUri = str_replace(['<', '>', '"'], '', $uri);

        $link = '<' . $safeUri . '>; rel=' . $rel;

        if ($as !== '') {
            $link .= '; as=' . $as;
        }

        if ($type !== '') {
            $link .= '; type=' . $type;
        }

        if (!headers_sent()) {
            header('Link: ' . $link, false);
        }

        $this->header->set('Link', $link);

        return $this;
    }

    /**
     * Отправляет 103 Early Hints, если сервер/SAPI это поддерживает.
     *
     * Обычно вызывается после добавления Link-заголовков.
     */
    public function sendEarlyHints(): void
    {
        if ( PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        header('HTTP/1.1 103 Early Hints');

        foreach ($this->header->all() as $name => $value) {
            if (strtolower($name) === 'link') {
                header('Link: ' . $value, false);
            }
        }

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * Подготавливает ответ файлом.
     *
     * @param string $path Путь к файлу.
     * @param string|null $downloadName Имя файла для клиента.
     * @param bool $inline true — открыть в браузере, false — скачать.
     * @param string|null $allowedBaseDir Ограничивает выдачу файлов разрешённой директорией.
     */
    public function download(
        string $path,
        ?string $downloadName = null,
        bool $inline = false,
        ?string $allowedBaseDir = null
    ): self {
        $realPath = $this->resolveSafeFilePath($path, $allowedBaseDir);

        if (!is_readable($realPath)) {
            throw new RuntimeException('Файл недоступен для чтения.');
        }

        $fileSize = filesize($realPath);

        if ($fileSize === false) {
            throw new RuntimeException('Не удалось определить размер файла.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($realPath) ?: 'application/octet-stream';

        $filename = $downloadName ?? basename($realPath);
        $fallback = preg_replace('/[^\x20-\x7e]/', '_', $filename) ?: 'file';
        $fallback = str_replace(['"', '\\'], '', $fallback);

        $disposition = $inline ? 'inline' : 'attachment';

        $this->filePath = $realPath;

        $this->header->set('Content-Type', $mime);
        $this->header->set('Content-Length', (string)$fileSize);
        $this->header->set('Accept-Ranges', 'bytes');
        $filename
            |> rawurlencode(...)
            |> (static fn($x) => sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $disposition, $fallback, $x))
            |> (fn($x) => $this->header->set('Content-Disposition', $x));

        return $this;
    }

    /**
     * Отправляет ответ клиенту.
     */
    public function send(): self
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (headers_sent($filename, $line)) {
            error_log(
                'Заголовки уже отправлены в файле ' . $filename . ' на строке ' . $line
            );

            if ($this->filePath !== null) {
                readfile($this->filePath);
            } else {
                echo $this->content;
            }

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

        if ($this->filePath !== null) {
            readfile($this->filePath);
            $this->filePath = null;
        } else {
            echo $this->content;
        }

        return $this;
    }

    /**
     * Нормализует URI для редиректа.
     *
     * Разрешены:
     * - относительные пути;
     * - абсолютные HTTPS-адреса.
     */
    private function normalizeRedirectUri(string $uri): string
    {
        $uri = trim($uri);

        if ($uri === '') {
            return '/';
        }

        if (parse_url($uri) === false) {
            return '/';
        }

        $scheme = parse_url($uri, PHP_URL_SCHEME);

        if (is_string($scheme)) {
            if (strtolower($scheme) !== 'https') {
                throw new InvalidArgumentException(
                    'Абсолютный URI для редиректа должен использовать HTTPS.'
                );
            }

            return $uri;
        }

        if (str_starts_with($uri, '//')) {
            throw new InvalidArgumentException(
                'Protocol-relative URI для редиректа запрещены.'
            );
        }

        return '/' . ltrim($uri, '/');
    }

    /**
     * Проверяет путь к файлу и защищает от выхода за пределы разрешённой директории.
     */
    private function resolveSafeFilePath(string $path, ?string $allowedBaseDir = null): string
    {
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Недопустимый путь к файлу.');
        }

        $realPath = realpath($path);

        if ($realPath === false) {
            throw new RuntimeException('Файл не найден.');
        }

        if ($allowedBaseDir !== null) {
            $baseDir = realpath($allowedBaseDir);

            if ($baseDir === false) {
                throw new RuntimeException('Разрешённая директория для файлов не найдена.');
            }

            if (!str_starts_with($realPath, $baseDir . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Доступ к файлу вне разрешённой директории запрещён.');
            }
        }

        return $realPath;
    }
}