<?php

declare(strict_types=1);

namespace CodeX\DataBase\Schema;

use CodeX\DataBase\Connection\Manager;
use CodeX\DataBase\Dialect\Factory;
use CodeX\Exception\DataBase;
use NoDiscard;
use PDO;
use PDOException;

/**
 * Фасад для выполнения DDL-операций над базой данных.
 */
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

        try {
            if ($pdo->exec($blueprint->toCreateSql($dialect)) === false) {
                return false;
            }
            foreach ($blueprint->getIndexSql($dialect) as $sql) {
                $pdo->exec($sql);
            }
            return true;
        } catch (PDOException $e) {
            throw DataBase::invalidConfig('Ошибка создания таблицы: ' . $e->getMessage());
        }
    }

    /**
     * Изменяет существующую таблицу (ALTER TABLE).
     */
    #[NoDiscard]
    public function table(string $table, callable $callback): bool
    {
        $pdo = $this->manager->connection($this->connection);
        $dialect = Factory::createFromPdo($pdo);
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $sql = $blueprint->toAlterSql($dialect);
        if ($sql !== '' && $pdo->exec($sql) === false) {
            return false;
        }

        foreach ($blueprint->getIndexSql($dialect) as $sql) {
            $pdo->exec($sql);
        }

        return true;
    }

    #[NoDiscard]
    public function drop(string $table): bool
    {
        $pdo = $this->manager->connection($this->connection);
        $dialect = Factory::createFromPdo($pdo);
        $quoted = $dialect->quoteIdentifier($table);

        try {
            return $pdo->exec('DROP TABLE ' . $quoted) !== false;
        } catch (PDOException $e) {
            throw DataBase::invalidConfig('Ошибка удаления таблицы: ' . $e->getMessage());
        }
    }

    /**
     * Удаляет таблицу, если она существует.
     */
    #[NoDiscard]
    public function dropIfExists(string $table): bool
    {
        $pdo = $this->manager->connection($this->connection);
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $dialect = Factory::createFromPdo($pdo);
        $quoted = $dialect->quoteIdentifier($table);

        try {
            if ($driver === 'oci' || $driver === 'oracle') {
                if (!$this->hasTable($table)) {
                    return true;
                }
                return $pdo->exec('DROP TABLE ' . $quoted . ' CASCADE CONSTRAINTS') !== false;
            }

            return $pdo->exec('DROP TABLE IF EXISTS ' . $quoted) !== false;
        } catch (PDOException) {
            return false;
        }
    }

    #[NoDiscard]
    public function hasTable(string $table): bool
    {
        $pdo = $this->manager->connection($this->connection);
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        try {
            return match ($driver) {
                'mysql'           => $this->hasMysqlTable($pdo, $table),
                'pgsql'           => $this->hasPostgresTable($pdo, $table),
                'sqlite'          => $this->hasSqliteTable($pdo, $table),
                'sqlsrv', 'dblib' => $this->hasSqlServerTable($pdo, $table),
                'oci', 'oracle'   => $this->hasOracleTable($pdo, $table),
                'firebird'        => $this->hasFirebirdTable($pdo, $table),
                default           => false,
            };
        } catch (PDOException) {
            return false;
        }
    }
    private function hasOracleTable(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM USER_TABLES WHERE TABLE_NAME = ?');
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function hasFirebirdTable(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM RDB$RELATIONS '
            . 'WHERE RDB$SYSTEM_FLAG = 0 AND RDB$RELATION_NAME = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }
    private function hasMysqlTable(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables ' . 'WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function hasPostgresTable(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT to_regclass(?) IS NOT NULL');
        $stmt->execute([$table]);

        return (bool)$stmt->fetchColumn();
    }

    private function hasSqliteTable(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sqlite_master WHERE type = \'table\' AND name = ?');
        $stmt->execute([$table]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function hasSqlServerTable(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES ' . 'WHERE TABLE_TYPE = \'BASE TABLE\' AND TABLE_NAME = ?');
        $stmt->execute([$table]);

        return (int)$stmt->fetchColumn() > 0;
    }
}