<?php

declare(strict_types=1);

namespace CodeX\Http\Session\Handler;

use RuntimeException;
use SessionHandlerInterface;

/**
 * Файловый обработчик сессий.
 */
class File implements SessionHandlerInterface
{
    /**
     * Директория хранения сессий.
     */
    private readonly string $path;

    /**
     * Резервное время жизни сессии.
     */
    private readonly int $lifetime;

    /**
     * Открытые файловые дескрипторы с блокировками.
     *
     * @var array<string, resource>
     */
    private array $locks = [];

    public function __construct(string $path, int $lifetime)
    {
        $this->path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->lifetime = max(1, $lifetime);

        if (!is_dir($this->path) && !mkdir($this->path, 0755, true) && !is_dir($this->path)) {
            throw new RuntimeException('Не удалось создать директорию для сессий: ' . $this->path);
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        foreach ($this->locks as $fp) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        $this->locks = [];

        return true;
    }

    public function read(string $id): string|false
    {
        $file = $this->filePath($id);

        if ($file === false) {
            return '';
        }

        if (!is_file($file)) {
            return '';
        }

        $fp = fopen($file, 'cb+');

        if ($fp === false) {
            return false;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);

            return false;
        }

        $this->locks[$id] = $fp;

        clearstatcache(true, $file);

        $size = filesize($file);

        if ($size === false || $size === 0) {
            return '';
        }

        $data = fread($fp, $size);

        return is_string($data) ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        $file = $this->filePath($id);

        if ($file === false) {
            return false;
        }

        $fp = $this->locks[$id] ?? fopen($file, 'cb+');

        if ($fp === false) {
            return false;
        }

        if (!flock($fp, LOCK_EX)) {
            if (!isset($this->locks[$id])) {
                fclose($fp);
            }

            return false;
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $data);
        fflush($fp);

        flock($fp, LOCK_UN);
        fclose($fp);

        unset($this->locks[$id]);

        return true;
    }

    public function destroy(string $id): bool
    {
        $file = $this->filePath($id);

        if ($file === false) {
            return false;
        }

        if (isset($this->locks[$id])) {
            flock($this->locks[$id], LOCK_UN);
            fclose($this->locks[$id]);
            unset($this->locks[$id]);
        }

        return !is_file($file) || unlink($file);
    }

    public function gc(int $max_lifetime): int|false
    {
        $lifetime = $max_lifetime > 0 ? $max_lifetime : $this->lifetime;

        $threshold = time() - $lifetime;
        $count = 0;

        $dir = opendir($this->path);

        if ($dir === false) {
            return false;
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $this->path . $file;

            if (!is_file($filePath)) {
                continue;
            }

            $mtime = filemtime($filePath);

            if ($mtime !== false && $mtime < $threshold && unlink($filePath)) {
                $count++;
            }
        }

        closedir($dir);

        return $count;
    }

    /**
     * Возвращает путь к файлу сессии.
     *
     * Запрещает любые символы, которые могут привести к path traversal.
     */
    private function filePath(string $id): string|false
    {
        if ($id === '' || preg_match('/^[A-Za-z0-9,\-]{1,128}$/', $id) !== 1) {
            return false;
        }

        return $this->path . $id;
    }
}