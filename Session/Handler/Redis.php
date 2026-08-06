<?php
declare(strict_types=1);

namespace CodeX\Http\Session\Handler;

use SessionHandlerInterface;

class Redis implements SessionHandlerInterface
{
    private \Redis $redis;
    private int $lifetime;
    private string $prefix;

    public function __construct(\Redis $redis, int $lifetime, string $prefix = 'codex_session:')
    {
        $this->redis = $redis;
        $this->lifetime = $lifetime;
        $this->prefix = $prefix;
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

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

    public function gc(int $max_lifetime): int|false { return 0; }
}