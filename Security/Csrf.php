<?php

declare(strict_types=1);

namespace CodeX\Http\Security;

use CodeX\Http\Exception\AccessDenied;
use CodeX\Http\Request;
use CodeX\Http\Session\Manager;
use NoDiscard;
use Random\RandomException;

/**
 * CSRF-защита.
 */
readonly class Csrf
{
    private const string TOKEN_KEY = '_csrf_token';
    private const string HEADER_NAME = 'X-CSRF-TOKEN';

    public function __construct(
        private Manager $session,
        private bool $regenerateAfterVerify = false
    ) {
    }

    /**
     * Возвращает текущий CSRF-токен.
     *
     * @throws RandomException
     */
    public function getToken(): string
    {
        $token = $this->session->get(self::TOKEN_KEY);

        if (!is_string($token)) {
            return $this->generateToken();
        }

        return $token;
    }

    /**
     * Генерирует новый CSRF-токен.
     *
     * @throws RandomException
     */
    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));

        $this->session->set(self::TOKEN_KEY, $token);

        return $token;
    }

    /**
     * Проверяет CSRF-токен.
     *
     * @throws AccessDeniedException
     * @throws RandomException
     */
    public function verify(Request $request): void
    {
        $method = $request->getMethod();

        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $postToken = $request->post->get(self::TOKEN_KEY);
        $headerToken = $request->headers->get(self::HEADER_NAME);

        $token = is_string($postToken)
            ? $postToken
            : (is_string($headerToken) ? $headerToken : null);

        if (!$this->validateToken($token)) {
            if ($this->regenerateAfterVerify) {
                $this->generateToken();
            }

            $this->session->addFlash('error', 'Неверный CSRF-токен. Пожалуйста, обновите страницу.');

            throw new AccessDenied('Неверный CSRF-токен.');
        }

        if ($this->regenerateAfterVerify) {
            $this->generateToken();
        }
    }

    /**
     * Проверяет токен без побочных эффектов.
     */
    #[NoDiscard]
    public function validateToken(?string $token): bool
    {
        if ($token === null) {
            return false;
        }

        $sessionToken = $this->session->get(self::TOKEN_KEY);

        if (!is_string($sessionToken)) {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * Возвращает скрытое HTML-поле с токеном.
     *
     * @throws RandomException
     */
    public function getTokenField(): string
    {
        $token = htmlspecialchars($this->getToken(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<input type="hidden" name="' . self::TOKEN_KEY . '" value="' . $token . '">';
    }

    /**
     * Возвращает meta-тег с токеном.
     *
     * @throws RandomException
     */
    public function getMetaTag(): string
    {
        $token = htmlspecialchars($this->getToken(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<meta name="csrf-token" content="' . $token . '">';
    }

    /**
     * Принудительно пересоздаёт токен.
     *
     * @throws RandomException
     */
    public function regenerate(): string
    {
        return $this->generateToken();
    }
}