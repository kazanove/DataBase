<?php
declare(strict_types=1);
namespace CodeX\DataBase\Migration;

use CodeX\DataBase\Connection\Manager;
use CodeX\DataBase\Dialect\Factory;
use CodeX\DataBase\Query\Builder;
use CodeX\DataBase\Schema\Blueprint;
use CodeX\DataBase\Schema\Schema;
use NoDiscard;
use PDO;

class Migrator
{
    private const string TABLE_NAME = 'migrations';

    public function __construct(private readonly Manager $manager, private readonly string $migrationsPath)
    {
        $this->ensureMigrationsTableExists();
    }

    /**
     * Проверяет существование таблицы миграций и создает её при необходимости.
     * Примечание: Использование конкатенации для SHOW TABLES / sqlite_master обусловлено
     * спецификой СУБД, которые не всегда поддерживают параметризованные запросы для DDL/метаданных.
     */
    private function ensureMigrationsTableExists(): void
    {
        $schema = new Schema($this->manager);
        $pdo = $this->manager->connection();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $sql = match ($driver) {
            'mysql' => 'SHOW TABLES LIKE \'' . self::TABLE_NAME . '\'',
            'pgsql' => 'SELECT to_regclass(\'' . self::TABLE_NAME . '\')',
            'sqlite' => 'SELECT name FROM sqlite_master WHERE type=\'table\' AND name=\'' . self::TABLE_NAME . '\'',
            default => 'SELECT 1 FROM ' . self::TABLE_NAME . ' LIMIT 1',
        };

        $stmt = $pdo->query($sql);
        if ($stmt->rowCount() === 0) {
            (void) $schema->create(self::TABLE_NAME, function (Blueprint $table) {
                $table->id();
                $table->string('migration', 255);
                $table->integer('batch');
            });
        }
    }

    #[NoDiscard]
    public function run(): int
    {
        $files = glob($this->migrationsPath . '/*.php');
        if (empty($files)) {
            return 0;
        }

        sort($files);
        $ran = $this->getRanMigrations();
        $batch = $this->getNextBatchNumber();
        $count = 0;

        foreach ($files as $file) {
            $migrationName = pathinfo($file, PATHINFO_FILENAME);

            if (in_array($migrationName, $ran, true)) {
                continue;
            }

            require_once $file;
            $className = $this->getClassNameFromFile($file);

            if (!class_exists($className)) {
                continue;
            }

            $migration = new $className();
            $migration->up();

            $this->logMigration($migrationName, $batch);
            $count++;
        }

        return $count;
    }

    private function getRanMigrations(): array
    {
        $pdo = $this->manager->connection();
        $dialect = Factory::createFromPdo($pdo);
        $builder = new Builder($dialect, $pdo);

        $builder->from(self::TABLE_NAME)->select('migration');
        [$sql, $bindings] = $builder->getSQL();

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'migration');
    }

    private function getNextBatchNumber(): int
    {
        return $this->getLastBatchNumber() + 1;
    }

    private function getLastBatchNumber(): int
    {
        $pdo = $this->manager->connection();
        $dialect = Factory::createFromPdo($pdo);
        $builder = new Builder($dialect, $pdo);

        $builder->from(self::TABLE_NAME)->selectRaw('MAX(batch) as max_batch');
        [$sql, $bindings] = $builder->getSQL();

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['max_batch'] ?? 0);
    }

    private function getClassNameFromFile(string $file): string
    {
        $content = file_get_contents($file);
        $namespace = '';
        $class = '';

        if (preg_match('/namespace\s+(.+?);/', $content, $matches)) {
            $namespace = $matches[1];
        }

        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $class = $matches[1];
        }

        return $namespace ? $namespace . '\\' . $class : $class;
    }

    private function logMigration(string $name, int $batch): void
    {
        $pdo = $this->manager->connection();
        $dialect = Factory::createFromPdo($pdo);
        $builder = new Builder($dialect, $pdo);

        $builder->insert(self::TABLE_NAME, ['migration' => $name, 'batch' => $batch]);
    }

    #[NoDiscard]
    public function rollback(): int
    {
        $batch = $this->getLastBatchNumber();
        if ($batch === 0) {
            return 0;
        }

        $migrations = $this->getMigrationsByBatch($batch);
        $migrations = array_reverse($migrations);
        $count = 0;

        foreach ($migrations as $migrationName) {
            $file = $this->migrationsPath . '/' . $migrationName . '.php';
            if (!file_exists($file)) {
                continue;
            }

            require_once $file;
            $className = $this->getClassNameFromFile($file);

            if (!class_exists($className)) {
                continue;
            }

            $migration = new $className();
            $migration->down();

            $this->deleteMigrationLog($migrationName);
            $count++;
        }

        return $count;
    }

    private function getMigrationsByBatch(int $batch): array
    {
        $pdo = $this->manager->connection();
        $dialect = Factory::createFromPdo($pdo);
        $builder = new Builder($dialect, $pdo);

        $builder->from(self::TABLE_NAME)->select('migration')->where('batch', '=', $batch);

        [$sql, $bindings] = $builder->getSQL();

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'migration');
    }

    private function deleteMigrationLog(string $name): void
    {
        $pdo = $this->manager->connection();
        $dialect = Factory::createFromPdo($pdo);
        $builder = new Builder($dialect, $pdo);

        $builder->from(self::TABLE_NAME)->where('migration', '=', $name)->delete();
    }
}