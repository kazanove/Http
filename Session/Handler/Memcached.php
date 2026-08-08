<?php

declare(strict_types=1);

namespace CodeX\Http\Session\Handler;

use Memcached as PhpMemcached;
use SessionHandlerInterface;

/**
 * Memcached-обработчик сессий.
 */
readonly class Memcached implements SessionHandlerInterface
{
    public function __construct(
        private PhpMemcached $memcached,
        private int          $lifetime = 1800,
        private string       $prefix = 'codex_session:'
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $data = $this->memcached->get($this->prefix . $id);

        if ($this->memcached->getResultCode() === PhpMemcached::RES_NOTFOUND) {
            return '';
        }

        return $data !== false ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        return $this->memcached->set($this->prefix . $id, $data, $this->lifetime);
    }

    public function destroy(string $id): bool
    {
        return $this->memcached->delete($this->prefix . $id);
    }

    public function gc(int $max_lifetime): int|false
    {
        // Memcached сам удаляет ключи по TTL.
        return 0;
    }
}