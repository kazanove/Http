<?php

declare(strict_types=1);

namespace CodeX\Http\Middleware;

use CodeX\Http\Request;
use CodeX\Http\Response;

/**
 * Общий контракт для middleware.
 */
interface Middleware
{
    /**
     * @param Request $request Текущий HTTP-запрос.
     * @param callable $next Следующий обработчик.
     */
    public function handle(Request $request, callable $next): Response;
}