<?php

declare(strict_types=1);

namespace CodeX\Http\Security;

use NoDiscard;
use Random\RandomException;

/**
 * Контекст безопасности запроса.
 *
 * Хранит данные, которые должны быть уникальными в рамках одного запроса,
 * например nonce для Content Security Policy.
 */
final class Context
{
    private string $cspNonce;

    /**
     * @throws RandomException
     */
    public function __construct()
    {
        $this->cspNonce = base64_encode(random_bytes(16));
    }

    /**
     * Возвращает nonce для использования в HTML.
     */
    #[NoDiscard]
    public function getCspNonce(): string
    {
        return $this->cspNonce;
    }

    /**
     * Возвращает nonce в формате директивы CSP.
     */
    #[NoDiscard]
    public function getCspDirective(): string
    {
        return '\'nonce-' . $this->cspNonce . '\'';
    }
}