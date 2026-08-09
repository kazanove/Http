<?php

declare(strict_types=1);

namespace CodeX\Http\Security;

use CodeX\Http\Exception\AccessDenied;
use CodeX\Http\Request;
use CodeX\Http\Session\Manager;
use Random\RandomException;

/**
 * CSRF-защита на основе сессионных токенов.
 *
 * Класс отвечает за генерацию, хранение и проверку токенов.
 * Извлечением токена из запроса занимается сам Request через getCsrfToken().
 */
readonly class Csrf
{
    /**
     * Имя поля формы с токеном.
     */
    private const string TOKEN_KEY = '_csrf_token';

    /**
     * Имя HTTP-заголовка с токеном.
     */
    private const string HEADER_NAME = 'X-CSRF-TOKEN';

    public function __construct(
        private Manager $session,
        private bool $regenerateAfterVerify = false
    ) {
    }

    /**
     * Возвращает текущий CSRF-токен, создавая его при отсутствии.
     *
     * @throws RandomException
     */
    public function getToken(): string
    {
        $token = $this->session->get(self::TOKEN_KEY);

        if (!is_string($token) || $token === '') {
            return $this->generateToken();
        }

        return $token;
    }

    /**
     * Генерирует новый криптографически стойкий токен и сохраняет его в сессии.
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
     * Проверяет CSRF-токен запроса.
     *
     * Безопасные методы (GET, HEAD, OPTIONS) пропускаются без проверки.
     *
     * @throws AccessDenied если токен отсутствует или не совпал.
     * @throws RandomException
     */
    public function verify(Request $request): void
    {
        // Безопасные методы не изменяют состояние — проверка не требуется.
        if ($request->isSafeMethod()) {
            return;
        }

        // Извлекаем токен из поля формы или заголовка.
        $token = $request->getCsrfToken(self::TOKEN_KEY, self::HEADER_NAME);

        if (!$this->validateToken($token)) {
            if ($this->regenerateAfterVerify) {
                $this->generateToken();
            }

            $this->session->addFlash(
                'error',
                'Неверный CSRF-токен. Пожалуйста, обновите страницу.'
            );

            throw new AccessDenied('Неверный CSRF-токен.');
        }

        if ($this->regenerateAfterVerify) {
            $this->generateToken();
        }
    }

    /**
     * Проверяет токен без побочных эффектов.
     *
     * Сравнение выполняется через hash_equals для защиты от timing-атак.
     */
    #[\NoDiscard]
    public function validateToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $sessionToken = $this->session->get(self::TOKEN_KEY);

        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * Возвращает скрытое HTML-поле с токеном для вставки в форму.
     *
     * @throws RandomException
     */
    public function getTokenField(): string
    {
        $token = htmlspecialchars($this->getToken(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<input type="hidden" name="' . self::TOKEN_KEY . '" value="' . $token . '">';
    }

    /**
     * Возвращает meta-тег с токеном для AJAX-запросов.
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