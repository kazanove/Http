<?php

declare(strict_types=1);

namespace CodeX\Http\Session\Handler;

use InvalidArgumentException;
use PDO;
use PDOException;
use SessionHandlerInterface;

/**
 * База данных для сессий.
 *
 * Ожидаемая структура таблицы:
 *
 * CREATE TABLE sessions (
 *     id VARCHAR(128) PRIMARY KEY,
 *     data TEXT NOT NULL,
 *     expires_at INT UNSIGNED NOT NULL
 * );
 */
class Database implements SessionHandlerInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'sessions',
        private readonly int $lifetime = 1800
    ) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $this->table) !== 1) {
            throw new InvalidArgumentException('Недопустимое имя таблицы сессий.');
        }
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
        $stmt = $this->pdo->prepare(
            sprintf(
                'SELECT data FROM %s WHERE id = :id AND expires_at > :now LIMIT 1',
                $this->table
            )
        );

        $stmt->execute([
            'id' => $id,
            'now' => time(),
        ]);

        $data = $stmt->fetchColumn();

        return is_string($data) ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        $expiresAt = time() + $this->lifetime;

        $update = $this->pdo->prepare(
            sprintf(
                'UPDATE %s SET data = :data, expires_at = :expires_at WHERE id = :id',
                $this->table
            )
        );

        $update->execute([
            'data' => $data,
            'expires_at' => $expiresAt,
            'id' => $id,
        ]);

        if ($update->rowCount() === 0) {
            $insert = $this->pdo->prepare(
                sprintf(
                    'INSERT INTO %s (id, data, expires_at) VALUES (:id, :data, :expires_at)',
                    $this->table
                )
            );

            try {
                $insert->execute([
                    'id' => $id,
                    'data' => $data,
                    'expires_at' => $expiresAt,
                ]);
            } catch (PDOException) {
                // Возможна гонка при параллельной записи.
                // Повторно обновляем уже существующую запись.
                $update->execute([
                    'data' => $data,
                    'expires_at' => $expiresAt,
                    'id' => $id,
                ]);
            }
        }

        return true;
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare(
            sprintf('DELETE FROM %s WHERE id = :id', $this->table)
        );

        $stmt->execute(['id' => $id]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare(
            sprintf('DELETE FROM %s WHERE expires_at < :now', $this->table)
        );

        $stmt->execute(['now' => time()]);

        return $stmt->rowCount();
    }
}