<?php

declare(strict_types=1);

namespace CodeX\Http\Session\Handler;

use Redis as PhpRedis;
use SessionHandlerInterface;

/**
 * Redis-обработчик сессий.
 */
readonly class Redis implements SessionHandlerInterface
{
    public function __construct(
        private PhpRedis $redis,
        private int      $lifetime = 1800,
        private string   $prefix = 'codex_session:'
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
        $data = $this->redis->get($this->prefix . $id);

        return $data !== false ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        return $this->redis->setex($this->prefix . $id, $this->lifetime, $data);
    }

    public function destroy(string $id): bool
    {
        return $this->redis->del($this->prefix . $id) > 0;
    }

    public function gc(int $max_lifetime): int|false
    {
        // Redis сам удаляет ключи по TTL.
        return 0;
    }
}