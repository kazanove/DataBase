<?php

declare(strict_types=1);

namespace CodeX\DataBase\Dialect;

use CodeX\Contract\DataBase\Sql;
use CodeX\Exception\DataBase;
use PDO;

/**
 * Фабрика SQL-диалектов.
 *
 * Поддерживает регистрацию кастомных диалектов для нестандартных драйверов.
 */
class Factory
{
    private static array $customDialects = [];

    public static function register(string $driver, string $dialectClass): void
    {
        self::$customDialects[$driver] = $dialectClass;
    }

    public static function createFromPdo(PDO $pdo): Sql
    {
        return self::createFromDsn($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . ':');
    }

    public static function createFromDsn(string $dsn): Sql
    {
        $driver = strtolower(parse_url($dsn, PHP_URL_SCHEME));

        if (!$driver) {
            $driver = explode(':', $dsn)[0];
        }

        if (isset(self::$customDialects[$driver])) {
            $class = self::$customDialects[$driver];
            return new $class();
        }

        return match ($driver) {
            'mysql' => new MySql(),
            'pgsql', 'postgres', 'postgresql' => new PostgreSql(),
            'sqlite' => new Sqlite(),
            'sqlsrv', 'dblib' => new SqlServer(),
            'oci', 'oracle' => new Oracle(),
            'firebird' => new Firebird(),
            default => throw DataBase::driverNotSupported($driver),
        };
    }
}