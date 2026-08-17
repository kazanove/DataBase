<?php

declare(strict_types=1);

namespace CodeX\DataBase\Connection;

use CodeX\Exception\DataBase;
use PDO;
use PDOException;
use Throwable;

/**
 * Менеджер подключений к БД.
 *
 * Поддерживает несколько именованных подключений, ленивую инициализацию
 * и кастомные драйверы.
 */
class Manager
{
    /** @var array<string, PDO> */
    private array $connections = [];

    /** @var array<string, array<string, mixed>> */
    private array $configs = [];

    /** @var array<string, callable> */
    private array $driverCreators = [];

    public function __construct(private readonly ?string $defaultConnection = null)
    {
    }

    public function addConnection(string $name, array $config): void
    {
        $this->configs[$name] = $config;
    }

    public function registerDriver(string $driver, callable $creator): void
    {
        $this->driverCreators[$driver] = $creator;
    }

    /**
     * Выполняет callback внутри транзакции с автоматическим commit/rollback.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public function transaction(string $connection, callable $callback): mixed
    {
        $pdo = $this->connection($connection);
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw DataBase::transactionFailed($connection, $e);
        }
    }

    public function connection(?string $name = null): PDO
    {
        $name ??= $this->defaultConnection;

        if ($name === null) {
            throw DataBase::invalidConfig('Не указано имя подключения и не задано подключение по умолчанию');
        }

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        if (!isset($this->configs[$name])) {
            throw DataBase::connectionNotConfigured($name, array_keys($this->configs));
        }

        $config = $this->configs[$name];
        $driver = $config['driver'] ?? throw DataBase::driverNotSpecified($name);

        try {
            $pdo = isset($this->driverCreators[$driver])
                ? ($this->driverCreators[$driver])($config)
                : $this->createConnection($driver, $config);

            $this->configurePdo($pdo, $driver, $config);
            $this->connections[$name] = $pdo;

            return $pdo;
        } catch (PDOException $e) {
            throw DataBase::connectionFailed($name, $e);
        }
    }

    private function createConnection(string $driver, array $config): PDO
    {
        return match ($driver) {
            'mysql' => $this->createMysqlConnection($config),
            'pgsql' => $this->createPgsqlConnection($config),
            'sqlite' => $this->createSqliteConnection($config),
            'sqlsrv', 'dblib' => $this->createSqlServerConnection($config),
            'oci', 'oracle' => $this->createOracleConnection($config),
            'firebird' => $this->createFirebirdConnection($config),
            default => throw DataBase::driverNotSupported($driver),
        };
    }

    private function createMysqlConnection(array $config): PDO
    {
        $dsn = 'mysql:';
        $parts = [];

        if (!empty($config['unix_socket'])) {
            $parts[] = 'unix_socket=' . $config['unix_socket'];
        } else {
            if (empty($config['host'])) {
                throw DataBase::hostNotSpecified('mysql');
            }
            $parts[] = 'host=' . $config['host'];
            $parts[] = 'port=' . ($config['port'] ?? 3306);
        }

        if (!empty($config['database'])) {
            $parts[] = 'dbname=' . $config['database'];
        }

        if (!empty($config['charset'])) {
            $parts[] = 'charset=' . $config['charset'];
        }

        $dsn .= implode(';', $parts);

        return new PDO($dsn, $config['username'] ?? null, $config['password'] ?? null, $config['options'] ?? []);
    }

    private function createPgsqlConnection(array $config): PDO
    {
        $parts = [];

        if (!empty($config['host'])) {
            $parts[] = 'host=' . $config['host'];
        }

        $parts[] = 'port=' . ($config['port'] ?? 5432);

        if (!empty($config['database'])) {
            $parts[] = 'dbname=' . $config['database'];
        }

        if (!empty($config['sslmode'])) {
            $parts[] = 'sslmode=' . $config['sslmode'];
        }

        return new PDO(
            'pgsql:' . implode(';', $parts),
            $config['username'] ?? null,
            $config['password'] ?? null,
            $config['options'] ?? []
        );
    }

    private function createSqliteConnection(array $config): PDO
    {
        $database = $config['database'] ?? throw DataBase::databaseNotSpecified();

        if ($database === ':memory:') {
            return new PDO('sqlite::memory:', null, null, $config['options'] ?? []);
        }

        $dir = dirname($database);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw DataBase::directoryNotCreated($dir);
        }

        return new PDO('sqlite:' . $database, null, null, $config['options'] ?? []);
    }

    private function createSqlServerConnection(array $config): PDO
    {
        $driver = $config['driver'] ?? 'sqlsrv';
        $parts = [];

        if (!empty($config['host'])) {
            $parts[] = 'Server=' . $config['host'] . ',' . ($config['port'] ?? 1433);
        }

        if (!empty($config['database'])) {
            $parts[] = 'Database=' . $config['database'];
        }

        return new PDO(
            $driver . ':' . implode(';', $parts),
            $config['username'] ?? null,
            $config['password'] ?? null,
            $config['options'] ?? []
        );
    }

    private function createOracleConnection(array $config): PDO
    {
        $dsn = 'oci:dbname=' . ($config['dbname'] ?? '');

        if (!empty($config['charset'])) {
            $dsn .= ';charset=' . $config['charset'];
        }

        return new PDO($dsn, $config['username'] ?? null, $config['password'] ?? null, $config['options'] ?? []);
    }

    private function createFirebirdConnection(array $config): PDO
    {
        $dsn = 'firebird:dbname=' . ($config['host'] ?? 'localhost') . ':' . ($config['database'] ?? '');

        return new PDO($dsn, $config['username'] ?? null, $config['password'] ?? null, $config['options'] ?? []);
    }

    private function configurePdo(PDO $pdo, string $driver, array $config): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        match ($driver) {
            'mysql' => $this->configureMysql($pdo, $config),
            'pgsql' => $this->configurePgsql($pdo, $config),
            'sqlite' => $this->configureSqlite($pdo),
            default => null,
        };
    }

    private function configureMysql(PDO $pdo, array $config): void
    {
        $charset = preg_replace('/[^a-zA-Z0-9_\-]/', '', $config['charset'] ?? 'utf8mb4');
        $collation = preg_replace('/[^a-zA-Z0-9_\-]/', '', $config['collation'] ?? 'utf8mb4_unicode_ci');

        $pdo->exec('SET NAMES \'' . $charset . '\' COLLATE \'' . $collation . '\'');

        if (!empty($config['strict'])) {
            $pdo->exec('SET sql_mode=\'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION\'');
        }
    }

    private function configurePgsql(PDO $pdo, array $config): void
    {
        if (!empty($config['schema'])) {
            $pdo->exec('SET search_path TO ' . $config['schema']);
        }
    }

    private function configureSqlite(PDO $pdo): void
    {
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}