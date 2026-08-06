<?php
declare(strict_types=1);

namespace CodeX\Http\Security;

use CodeX\Http\Exception\AccessDenied;
use CodeX\Http\Request;
use CodeX\Http\Session\Manager;
use Random\RandomException;

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
     * @throws RandomException
     */
    public function getToken(): string
    {
        if (!$this->session->has(self::TOKEN_KEY)) {
            $this->generateToken();
        }

        return $this->session->get(self::TOKEN_KEY);
    }

    /**
     * @throws RandomException
     */
    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::TOKEN_KEY, $token);
        return $token;
    }

    /**
     * @throws RandomException
     * @throws AccessDenied
     */
    public function verify(Request $request): void
    {
        $method = $request->getMethod();

        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $token = $request->post->get(self::TOKEN_KEY)
            ?? $request->headers->get(self::HEADER_NAME);

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

    public function validateToken(?string $token): bool
    {
        if ($token === null || !$this->session->has(self::TOKEN_KEY)) {
            return false;
        }

        $sessionToken = $this->session->get(self::TOKEN_KEY);
        return hash_equals($sessionToken, $token);
    }

    /**
     * @throws RandomException
     */
    public function getTokenField(): string
    {
        $token = htmlspecialchars($this->getToken(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<input type="hidden" name="' . self::TOKEN_KEY . '" value="' . $token . '">';
    }

    /**
     * @throws RandomException
     */
    public function getMetaTag(): string
    {
        $token = htmlspecialchars($this->getToken(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<meta name="csrf-token" content="' . $token . '">';
    }

    /**
     * @throws RandomException
     */
    public function regenerate(): string
    {
        return $this->generateToken();
    }
}