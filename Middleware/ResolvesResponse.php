<?php

declare(strict_types=1);

namespace CodeX\Http\Middleware;

use CodeX\Http\Response;
use JsonException;

/**
 * Трейт для преобразования произвольного результата обработчика
 * в объект HTTP-ответа.
 *
 * Устраняет дублирование кода в middleware, реализующих паттерн
 * «обёртывания» ответа (например, SecurityHeaders, CacheControl).
 */
trait ResolvesResponse
{
    /**
     * Преобразует результат выполнения следующего обработчика в Response.
     */
    private function resolveResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        $response = new Response();

        if ($result === null) {
            $response->content = '';
        } elseif (is_scalar($result)) {
            $response->content = (string) $result;
        } else {
            try {
                $response->content = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $response->header->set('Content-Type', 'application/json; charset=utf-8');
            } catch (JsonException) {
                $response->content = '';
            }
        }

        return $response;
    }
}