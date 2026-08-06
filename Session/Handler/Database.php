<?php
declare(strict_types=1);

namespace CodeX\Http\Session\Handler;

use CodeX\DataBase\Dialect\Factory;
use CodeX\DataBase\Query\Builder;
use PDO;
use SessionHandlerInterface;
use Throwable;

class Database implements SessionHandlerInterface
{
    private PDO $pdo;
    private Builder $baseBuilder;
    private string $table;
    private int $lifetime;

    public function __construct(PDO $pdo, string $table, int $lifetime)
    {
        $this->pdo = $pdo;
        $this->table = $table;
        $this->lifetime = $lifetime;
        $dialect = Factory::createFromPdo($pdo);
        $this->baseBuilder = new Builder($dialect, $pdo);
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
        $builder = $this->newBuilder();
        $builder->from($this->table)->select(['data'])->where('id', '=', $id)->where('last_activity', '>=', time() - $this->lifetime);

        [$sql, $bindings] = $builder->getSQL();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $row['data'] : '';
    }

    private function newBuilder(): Builder
    {
        return clone $this->baseBuilder;
    }

    public function write(string $id, string $data): bool
    {
        $time = time();

        $this->pdo->beginTransaction();
        try {
            $updateBuilder = $this->newBuilder();
            $updateBuilder->prepareUpdate($this->table, ['data' => $data, 'last_activity' => $time])->where('id', '=', $id);

            [$sql, $bindings] = $updateBuilder->getSQL();
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);

            if ($stmt->rowCount() === 0) {
                $insertBuilder = $this->newBuilder();
                $insertBuilder->prepareInsert($this->table, ['id' => $id, 'data' => $data, 'last_activity' => $time]);

                [$sql, $bindings] = $insertBuilder->getSQL();

                try {
                    $this->pdo->prepare($sql)->execute($bindings);
                } catch (\PDOException $e) {
                    // Код 23000 - нарушение уникальности (Integrity constraint violation).
                    // Игнорируем ошибку, так как другой параллельный процесс уже успешно
                    // создал запись. Данные будут обновлены при следующем запросе.
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }
                }
            }

            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            error_log('Ошибка записи сессии в БД: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        $builder = $this->newBuilder();
        $builder->prepareDelete($this->table)->where('id', '=', $id);

        [$sql, $bindings] = $builder->getSQL();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount() > 0;
    }

    public function gc(int $max_lifetime): int|false
    {
        $builder = $this->newBuilder();
        $builder->prepareDelete($this->table)->where('last_activity', '<', time() - $max_lifetime);

        [$sql, $bindings] = $builder->getSQL();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }
}