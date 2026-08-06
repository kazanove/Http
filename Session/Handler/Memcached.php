<?php
declare(strict_types=1);

namespace CodeX\Http\Session\Handler;

use SessionHandlerInterface;

class Memcached implements SessionHandlerInterface
{
    private \Memcached $memcached;
    private int $lifetime;
    private string $prefix;

    public function __construct(\Memcached $memcached, int $lifetime, string $prefix = 'codex_session:')
    {
        $this->memcached = $memcached;
        $this->lifetime = $lifetime;
        $this->prefix = $prefix;
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        $data = $this->memcached->get($this->prefix . $id);

        if ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
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

    public function gc(int $max_lifetime): int|false { return 0; }
}