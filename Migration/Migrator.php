<?php

declare(strict_types=1);

namespace CodeX\DataBase\Migration;

use CodeX\DataBase\Connection\Manager;
use CodeX\DataBase\Dialect\Factory;
use CodeX\DataBase\Migration;
use CodeX\DataBase\Query\Builder;
use CodeX\DataBase\Schema\Blueprint;
use CodeX\DataBase\Schema\Schema;
use CodeX\Exception\DataBase;
use NoDiscard;
use PDO;
use RuntimeException;


class Migrator
{
    private const string TABLE_NAME = 'migrations';

    public function __construct(private readonly Manager $manager, private readonly string $migrationsPath)
    {
        $this->ensureMigrationsTableExists();
    }

    private function ensureMigrationsTableExists(): void
    {
        $schema = new Schema($this->manager);
        $pdo = $this->manager->connection();
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        $exists = match ($driver) {
            'mysql' => (bool)$pdo->query('SHOW TABLES LIKE ' . $pdo->quote(self::TABLE_NAME))->fetchColumn(),
            'pgsql' => (bool)$pdo->prepare("SELECT to_regclass(?) IS NOT NULL")->execute([self::TABLE_NAME]),            'sqlite' => (bool)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote(self::TABLE_NAME))->fetchColumn(),
            'sqlsrv', 'dblib' => (bool)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES " . "WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME = " . $pdo->quote(self::TABLE_NAME))->fetchColumn(),
            'oci', 'oracle' => (bool) $pdo->query(
                "SELECT COUNT(*) FROM USER_TABLES WHERE TABLE_NAME = '" . self::TABLE_NAME . "'"
            )->fetchColumn(),
            'firebird' => (bool) $pdo->query(
                "SELECT COUNT(*) FROM RDB\$TABLES "
                . "WHERE RDB\$SYSTEM_FLAG = 0 AND RDB\$RELATION_NAME = '" . self::TABLE_NAME . "'"
            )->fetchColumn(),
            default => false,
        };

        if ($exists) {
            return;
        }

        (void)$schema->create(self::TABLE_NAME, static function (Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });
    }

    /**
     * Откатывает последнюю партию миграций.
     *
     * @return int Количество откаченных миграций.
     */
    #[NoDiscard]
    public function rollback(): int
    {
        $batch = $this->getLastBatchNumber();

        if ($batch === 0) {
            return 0;
        }

        $migrations = array_reverse($this->getMigrationsByBatch($batch));
        $count = 0;

        foreach ($migrations as $migrationName) {
            $file = $this->migrationsPath . '/' . $migrationName . '.php';

            if (!file_exists($file)) {
                $this->deleteMigrationLog($migrationName);
                continue;
            }

            require_once $file;

            $className = $this->getClassNameFromFile($file);

            if (!class_exists($className)) {
                throw DataBase::invalidConfig('Не найден класс миграции в файле ' . $file);
            }

            /** @var Migration $migration */
            $migration = new $className();
            $migration->setManager($this->manager);  // ← ДОБАВЛЕНО
            $migration->down();

            $this->deleteMigrationLog($migrationName);
            $count++;
        }

        return $count;
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

    /**
     * Извлекает полное имя класса из файла миграции.
     *
     * Стратегия:
     * 1. Пытаемся вывести класс из имени файла (соглашение: 2026_10_15_..._create_users_table → CreateUsersTable).
     * 2. Если не удалось — используем токенизатор с проверкой уникальности.
     */
    private function getClassNameFromFile(string $file): string
    {
        $namespace = $this->extractNamespace($file);
        $className = $this->classNameFromFilename($file);

        if ($className !== null) {
            $fqcn = $namespace !== '' ? $namespace . '\\' . $className : $className;
            if (class_exists($fqcn, false) || $this->classDeclaredInFile($file, $className)) {
                return $fqcn;
            }
        }

        // Fallback: токенизатор с защитой от нескольких классов
        return $this->extractClassByTokens($file, $namespace);
    }

    private function extractNamespace(string $file): string
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return '';
        }
        if (preg_match('/namespace\s+([^;]+);/', $content, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function classNameFromFilename(string $file): ?string
    {
        $basename = pathinfo($file, PATHINFO_FILENAME);
        // Формат: 2026_10_15_143640_create_tools_table
        // Убираем дату и время (первые 4 сегмента)
        $parts = explode('_', $basename);
        if (count($parts) < 5) {
            return null;
        }
        $nameParts = array_slice($parts, 4);
        $snake = implode('_', $nameParts);

        return $this->toPascalCase($snake);
    }

    private function toPascalCase(string $name): string
    {
        return str_replace('_', ' ', $name)
                |> ucwords(...)
                |> (static fn($x) => str_replace(' ', '', $x));
    }

    /**
     * Проверяет, действительно ли класс объявлен в файле.
     */
    private function classDeclaredInFile(string $file, string $className): bool
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return false;
        }
        // Ищем "class ClassName" с учётом границ слов
        return (bool)preg_match('/\bclass\s+' . preg_quote($className, '/') . '\b/', $content);
    }

    /**
     * Резервный парсинг через токенизатор.
     * Возвращает ПЕРВЫЙ объявленный класс, игнорируя анонимные и вложенные.
     */
    private function extractClassByTokens(string $file, string $namespace): string
    {
        $content = file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException('Не удалось прочитать файл миграции: ' . $file);
        }

        $tokens = token_get_all($content);
        $class = '';
        $expectingClass = false;
        $found = 0;

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_CLASS) {
                $expectingClass = true;
                continue;
            }
            if ($expectingClass && $token[0] === T_STRING) {
                $class = $token[1];
                $found++;
                $expectingClass = false;
                // не break — идём до конца, чтобы посчитать все классы
            } elseif ($token[0] !== T_WHITESPACE && $token[0] !== T_DOUBLE_COLON) {
                $expectingClass = false;
            }
        }

        if ($class === '') {
            throw new RuntimeException('В файле миграции не найден класс: ' . $file);
        }
        if ($found > 1) {
            throw new RuntimeException('Файл миграции содержит более одного класса: ' . $file);
        }

        return $namespace !== '' ? $namespace . '\\' . $class : $class;
    }

    /**
     * Возвращает статус всех миграций.
     *
     * @return array<int, array{migration: string, ran: bool, batch: int|null}>
     */
    #[NoDiscard]
    public function getStatus(): array
    {
        $ranWithBatch = $this->getRanMigrationsWithBatch();
        $ranMap = [];

        foreach ($ranWithBatch as $row) {
            $ranMap[$row['migration']] = (int)$row['batch'];
        }

        $files = glob($this->migrationsPath . '/*.php');

        if ($files === false || $files === []) {
            return [];
        }

        sort($files);

        $status = [];

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $ran = isset($ranMap[$name]);

            $status[] = ['migration' => $name, 'ran' => $ran, 'batch' => $ran ? $ranMap[$name] : null,];
        }

        return $status;
    }

    /**
     * Возвращает выполненные миграции с номером партии.
     */
    private function getRanMigrationsWithBatch(): array
    {
        $pdo = $this->manager->connection();
        $dialect = Factory::createFromPdo($pdo);
        $builder = new Builder($dialect, $pdo);

        $builder->from(self::TABLE_NAME)->select(['migration', 'batch'])->orderBy('batch')->orderBy('id');

        [$sql, $bindings] = $builder->getSQL();

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): array => ['migration' => (string)$row['migration'], 'batch' => (int)$row['batch'],], $rows);
    }

    /**
     * Удаляет все таблицы и запускает миграции заново.
     *
     * @return int Количество выполненных миграций.
     */
    #[NoDiscard]
    public function fresh(): int
    {
        $this->dropAllTables();
        $this->ensureMigrationsTableExists();

        return $this->run();
    }

    /**
     * Удаляет все таблицы и представления в базе данных.
     */
    private function dropAllTables(): void
    {
        $pdo = $this->manager->connection();
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $dialect = Factory::createFromPdo($pdo);

        $this->disableForeignKeyConstraints($pdo, $driver);

        // 1. Сначала удаляем все VIEW (представления), чтобы они не блокировали удаление таблиц
        $views = $this->getAllViews($pdo, $driver);
        foreach ($views as $view) {
            $quotedView = $dialect->quoteIdentifier($view);

            if ($driver === 'oci' || $driver === 'oracle') {
                $pdo->exec('DROP VIEW ' . $quotedView);
                continue;
            }
            if ($driver === 'firebird') {
                $pdo->exec('DROP VIEW ' . $quotedView);
                continue;
            }

            $pdo->exec('DROP VIEW IF EXISTS ' . $quotedView);
        }

        // 2. Затем удаляем все базовые таблицы
        $tables = $this->getAllTables($pdo, $driver);
        foreach ($tables as $table) {
            $quotedTable = $dialect->quoteIdentifier($table);

            if ($driver === 'oci' || $driver === 'oracle') {
                $pdo->exec('DROP TABLE ' . $quotedTable . ' CASCADE CONSTRAINTS');
                continue;
            }

            $cascade = $driver === 'pgsql' ? ' CASCADE' : '';
            $pdo->exec('DROP TABLE IF EXISTS ' . $quotedTable . $cascade);
        }

        $this->enableForeignKeyConstraints($pdo, $driver);
    }

    private function disableForeignKeyConstraints(PDO $pdo, string $driver): void
    {
        match ($driver) {
            'mysql' => $pdo->exec('SET FOREIGN_KEY_CHECKS = 0'),
            'sqlite' => $pdo->exec('PRAGMA foreign_keys = OFF'),
            'sqlsrv', 'dblib' => $pdo->exec("EXEC sp_MSforeachtable 'ALTER TABLE ? NOCHECK CONSTRAINT all'"),  // ← добавлено
            default => null,
        };
    }

    /**
     * Возвращает список всех представлений (VIEW) в базе данных.
     */
    private function getAllViews(PDO $pdo, string $driver): array
    {
        return match ($driver) {
            'sqlsrv', 'dblib' => array_column($pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = 'dbo'")->fetchAll(PDO::FETCH_ASSOC), 'TABLE_NAME'),
            'oci', 'oracle' => array_column($pdo->query('SELECT VIEW_NAME FROM USER_VIEWS')->fetchAll(PDO::FETCH_ASSOC), 'VIEW_NAME'),
            'firebird' => array_column($pdo->query("SELECT RDB\$RELATION_NAME FROM RDB\$RELATIONS " . "WHERE RDB\$VIEW_BL_CONTEXT IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC), 'RDB$RELATION_NAME'),
            default => [],
        };
    }

    /**
     * Возвращает список всех таблиц в базе данных.
     *
     * @return array<int, string>
     */
    private function getAllTables(PDO $pdo, string $driver): array
    {
        return match ($driver) {
            'mysql' => $this->getMysqlTables($pdo),
            'pgsql' => $this->getPostgresTables($pdo),
            'sqlite' => $this->getSqliteTables($pdo),
            'sqlsrv', 'dblib' => $this->getSqlServerTables($pdo),
            'oci', 'oracle' => array_column($pdo->query('SELECT TABLE_NAME FROM USER_TABLES')->fetchAll(PDO::FETCH_ASSOC), 'TABLE_NAME'),
            'firebird' => array_column($pdo->query("SELECT RDB\$RELATION_NAME FROM RDB\$RELATIONS " . "WHERE RDB\$SYSTEM_FLAG = 0 AND RDB\$VIEW_BL_CONTEXT IS NULL")->fetchAll(PDO::FETCH_ASSOC), 'RDB$RELATION_NAME'),
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private function getMysqlTables(PDO $pdo): array
    {
        $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'");
        return array_column($stmt->fetchAll(PDO::FETCH_NUM), 0);
    }

    /**
     * @return array<int, string>
     */
    private function getPostgresTables(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT tablename FROM pg_tables WHERE schemaname = ' . $pdo->quote('public'));

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'tablename');
    }

    /**
     * @return array<int, string>
     */
    private function getSqliteTables(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT name FROM sqlite_master WHERE type=' . $pdo->quote('table') . ' AND name NOT LIKE ' . $pdo->quote('sqlite_%'));

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    /**
     * @return array<int, string>
     */
    private function getSqlServerTables(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = ' . $pdo->quote('BASE TABLE'));

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'TABLE_NAME');
    }

    private function enableForeignKeyConstraints(PDO $pdo, string $driver): void
    {
        match ($driver) {
            'mysql' => $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'),
            'sqlite' => $pdo->exec('PRAGMA foreign_keys = ON'),
            'sqlsrv', 'dblib' => $pdo->exec("EXEC sp_MSforeachtable 'ALTER TABLE ? WITH CHECK CHECK CONSTRAINT all'"),
            default => null,
        };
    }

    /**
     * Запускает все ожидающие миграции.
     *
     * @return int Количество выполненных миграций.
     */
    #[NoDiscard]
    public function run(): int
    {
        $files = glob($this->migrationsPath . '/*.php');

        if ($files === false || $files === []) {
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
                throw DataBase::invalidConfig('Не найден класс миграции в файле ' . $file);
            }

            /** @var Migration $migration */
            $migration = new $className();
            $migration->setManager($this->manager);
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

    private function logMigration(string $name, int $batch): void
    {
        $pdo = $this->manager->connection();
        $dialect = Factory::createFromPdo($pdo);
        $builder = new Builder($dialect, $pdo);
        $builder->insert(self::TABLE_NAME, ['migration' => $name, 'batch' => $batch]);
    }

    public function getManager(): Manager
    {
        return $this->manager;
    }

}