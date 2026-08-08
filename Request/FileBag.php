<?php

declare(strict_types=1);

namespace CodeX\Http\Request;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Нормализует массив $_FILES и предоставляет удобный доступ к файлам.
 *
 * Поддерживает:
 * - одиночные файлы;
 * - множественные файлы;
 * - вложенные массивы файлов.
 */
final class FileBag implements IteratorAggregate, Countable
{
    private array $files;

    public function __construct(array $files = [])
    {
        $this->files = $this->normalize($files);
    }

    /**
     * Возвращает первый файл по ключу.
     *
     * Пример:
     * $request->files->get('avatar')
     * $request->files->get('document.attachment')
     */
    public function get(string $key): ?UploadedFile
    {
        $value = $this->resolve($this->files, $key);

        if ($value instanceof UploadedFile) {
            return $value;
        }

        if (is_array($value)) {
            return $this->firstFile($value);
        }

        return null;
    }

    /**
     * Возвращает все файлы по ключу.
     */
    public function all(string $key = ''): array
    {
        $value = $this->resolve($this->files, $key);

        if ($value === null) {
            return [];
        }

        if ($value instanceof UploadedFile) {
            return [$value];
        }

        return is_array($value) ? $value : [];
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function count(): int
    {
        return $this->countFiles($this->files);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->files);
    }

    /**
     * Рекурсивно нормализует массив файлов.
     */
    private function normalize(array $files): array
    {
        $result = [];

        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFile) {
                $result[$key] = $value;
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            if (isset($value['tmp_name'])) {
                $result[$key] = $this->normalizePhpFiles($value);
            } else {
                $result[$key] = $this->normalize($value);
            }
        }

        return $result;
    }

    /**
     * Приводит классический массив $_FILES к удобному виду.
     */
    private function normalizePhpFiles(array $data): array|UploadedFile
    {
        if (!is_array($data['tmp_name'])) {
            return $this->createUploadedFile($data);
        }

        $result = [];

        foreach ($data['tmp_name'] as $key => $tmpName) {
            $row = [
                'tmp_name' => $tmpName,
                'name' => $data['name'][$key] ?? '',
                'type' => $data['type'][$key] ?? '',
                'size' => $data['size'][$key] ?? 0,
                'error' => $data['error'][$key] ?? UPLOAD_ERR_OK,
            ];

            if (is_array($tmpName)) {
                $result[$key] = $this->normalizePhpFiles($row);
            } else {
                $result[$key] = $this->createUploadedFile($row);
            }
        }

        return $result;
    }

    private function createUploadedFile(array $data): UploadedFile
    {
        return new UploadedFile(
            (string)($data['tmp_name'] ?? ''),
            (string)($data['name'] ?? ''),
            (string)($data['type'] ?? ''),
            (int)($data['size'] ?? 0),
            (int)($data['error'] ?? UPLOAD_ERR_OK)
        );
    }

    /**
     * Возвращает значение по dot-ключу.
     */
    private function resolve(array $data, string $key): mixed
    {
        if ($key === '') {
            return $data;
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return null;
            }

            $data = $data[$segment];
        }

        return $data;
    }

    /**
     * Ищет первый загруженный файл в массиве.
     */
    private function firstFile(array $files): ?UploadedFile
    {
        $first = null;

        array_walk_recursive(
            $files,
            static function ($item) use (&$first): void {
                if ($first === null && $item instanceof UploadedFile) {
                    $first = $item;
                }
            }
        );

        return $first;
    }

    /**
     * Считает количество файлов.
     */
    private function countFiles(array $files): int
    {
        $count = 0;

        array_walk_recursive(
            $files,
            static function ($item) use (&$count): void {
                if ($item instanceof UploadedFile) {
                    $count++;
                }
            }
        );

        return $count;
    }
}