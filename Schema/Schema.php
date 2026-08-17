<?php

declare(strict_types=1);

namespace CodeX\DataBase\Schema;

use CodeX\DataBase\Connection\Manager;
use CodeX\DataBase\Dialect\Factory;
use CodeX\Exception\DataBase;
use NoDiscard;
use PDOException;

readonly class Schema
{
    public function __construct(private Manager $manager, private ?string $connection = null)
    {
    }

    #[NoDiscard]
    public function create(string $table, callable $callback): bool
    {
        $pdo = $this->manager->connection($this->connection);
        $dialect = Factory::createFromPdo($pdo);
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        $sql = $blueprint->toCreateSql($dialect);

        try {
            return $pdo->exec($sql) !== false;
        } catch (PDOException $e) {
            throw DataBase::invalidConfig('Ошибка создания таблицы: ' . $e->getMessage());
        }
    }

    #[NoDiscard]
    public function drop(string $table): bool
    {
        $pdo = $this->manager->connection($this->connection);
        $dialect = Factory::createFromPdo($pdo);
        $sql = new Blueprint($table)->toDropSql($dialect);

        try {
            return $pdo->exec($sql) !== false;
        } catch (PDOException $e) {
            throw DataBase::invalidConfig('Ошибка удаления таблицы: ' . $e->getMessage());
        }
    }
}