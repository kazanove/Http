<?php

declare(strict_types=1);

namespace CodeX\Http\Request;

use CodeX\Http\Exception\Request;
use finfo;
use Random\RandomException;
use RuntimeException;

/**
 * Загруженный файл.
 */
final class UploadedFile
{
    /**
     * Потенциально опасные расширения.
     */
    private const array DANGEROUS_EXTENSIONS = [
        'php',
        'phtml',
        'php3',
        'php4',
        'php5',
        'phar',
        'sh',
        'bash',
        'bat',
        'cmd',
        'exe',
        'dll',
        'cgi',
        'pl',
        'py',
    ];

    public function __construct(
        private readonly string $tmpName,
        private readonly string $clientName,
        private readonly string $clientType,
        private readonly int $size,
        private readonly int $error
    ) {
    }

    /**
     * Проверка успешной загрузки.
     */
    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK
            && $this->tmpName !== ''
            && is_uploaded_file($this->tmpName);
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    public function getClientExtension(): string
    {
        return strtolower(pathinfo($this->clientName, PATHINFO_EXTENSION));
    }

    public function getClientMimeType(): string
    {
        return $this->clientType;
    }

    /**
     * Определяет реальный MIME-тип через fileinfo.
     */
    public function getMimeType(): string
    {
        if ($this->tmpName === '' || !is_file($this->tmpName)) {
            return $this->clientType;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($this->tmpName);

        return $mime ?: $this->clientType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    /**
     * Безопасно перемещает загруженный файл.
     *
     * @param string $targetDirectory Целевая директория.
     * @param string|null $newName Новое имя файла. Если null, будет сгенерировано случайное.
     * @param array $allowedExtensions Разрешённые расширения.
     * @param array $allowedMimeTypes Разрешённые MIME-типы.
     * @param int $maxSize Максимальный размер в байтах. 0 — без ограничения.
     * @param bool $allowNonUploaded Используется только для тестов.
     *
     * @return string Полный путь к сохранённому файлу.
     * @throws RandomException
     */
    public function moveTo(
        string $targetDirectory,
        ?string $newName = null,
        array $allowedExtensions = [],
        array $allowedMimeTypes = [],
        int $maxSize = 0,
        bool $allowNonUploaded = false
    ): string {
        if (!$this->isValid()) {
            if (!$allowNonUploaded || $this->error !== UPLOAD_ERR_OK) {
                throw new Request('Файл не был корректно загружен.');
            }
        }

        if ($maxSize > 0 && $this->size > $maxSize) {
            throw new Request('Файл превышает максимально допустимый размер.');
        }

        $clientExtension = $this->getClientExtension();

        if ($allowedExtensions !== [] && !in_array($clientExtension, $allowedExtensions, true)) {
            throw new Request('Расширение файла запрещено.');
        }

        $mime = $this->getMimeType();

        if ($allowedMimeTypes !== [] && !in_array($mime, $allowedMimeTypes, true)) {
            throw new Request('MIME-тип файла запрещён.');
        }

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Не удалось создать директорию для загрузки файлов.');
        }

        if (!is_writable($targetDirectory)) {
            throw new RuntimeException('Директория для загрузки файлов недоступна для записи.');
        }

        if ($newName === null) {
            $newName = bin2hex(random_bytes(16));

            if ($clientExtension !== '') {
                $newName .= '.' . $clientExtension;
            }
        }

        // Защита от path traversal в пользовательском имени файла.
        $newName = basename($newName);

        $finalExtension = strtolower(pathinfo($newName, PATHINFO_EXTENSION));

        if (in_array($finalExtension, self::DANGEROUS_EXTENSIONS, true)) {
            throw new Request('Файл имеет потенциально опасное расширение.');
        }

        if ($allowedExtensions !== [] && !in_array($finalExtension, $allowedExtensions, true)) {
            throw new Request('Итоговое расширение файла запрещено.');
        }

        $targetPath = rtrim($targetDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $newName;

        // Если файл уже существует, создаём уникальное имя.
        if (file_exists($targetPath)) {
            $newName = bin2hex(random_bytes(8)) . '_' . $newName;
            $targetPath = rtrim($targetDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $newName;
        }

        $moved = $allowNonUploaded
            ? rename($this->tmpName, $targetPath)
            : move_uploaded_file($this->tmpName, $targetPath);

        if (!$moved) {
            throw new RuntimeException('Не удалось переместить загруженный файл.');
        }

        @chmod($targetPath, 0644);

        return $targetPath;
    }
}