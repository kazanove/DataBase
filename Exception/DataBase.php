<?php
declare(strict_types=1);
namespace CodeX\Exception;

use RuntimeException;
use Throwable;

class DataBase extends RuntimeException
{
    public const int CONNECTION_NOT_CONFIGURED = 1;
    public const int DRIVER_NOT_SPECIFIED = 2;
    public const int DRIVER_NOT_SUPPORTED = 3;
    public const int HOST_NOT_SPECIFIED = 4;
    public const int DATABASE_NOT_SPECIFIED = 5;
    public const int CONNECTION_FAILED = 6;
    public const int DIRECTORY_NOT_CREATED = 7;
    public const int TRANSACTION_FAILED = 8;
    public const int INVALID_CONFIG = 9;
    public const int RESOLVER_NOT_SET = 10;
    public const int TABLE_NOT_SPECIFIED = 11;
    public const int INVALID_SORT_DIRECTION = 12;
    public const int UNKNOWN_WHERE_TYPE = 13;
    public const int INVALID_OPERATOR = 14;
    public const int UNKNOWN_QUERY_TYPE = 15;
    public const int PARENT_MODEL_NOT_SAVED = 16;
    public const int JSON_SERIALIZATION_FAILED = 17;
    public const int METHOD_NOT_EXIST = 18;

    public static function connectionNotConfigured(string $name, array $available = []): self
    {
        $message = 'Подключение [' . $name . '] не сконфигурировано';
        if (!empty($available)) {
            $message .= '. Доступные подключения: ' . implode(', ', $available);
        }
        return new self($message, self::CONNECTION_NOT_CONFIGURED);
    }

    public static function driverNotSpecified(string $connection): self
    {
        return new self('Драйвер БД не указан для подключения [' . $connection . ']', self::DRIVER_NOT_SPECIFIED);
    }

    public static function driverNotSupported(string $driver): self
    {
        return new self('Неподдерживаемый драйвер БД: [' . $driver . ']', self::DRIVER_NOT_SUPPORTED);
    }

    public static function hostNotSpecified(string $driver): self
    {
        return new self('Не указан хост для подключения к ' . $driver, self::HOST_NOT_SPECIFIED);
    }

    public static function databaseNotSpecified(): self
    {
        return new self('Не указан путь к базе данных SQLite', self::DATABASE_NOT_SPECIFIED);
    }

    public static function connectionFailed(string $connection, Throwable $previous): self
    {
        return new self('Не удалось подключиться к БД [' . $connection . ']: ' . $previous->getMessage(), self::CONNECTION_FAILED, $previous);
    }

    public static function directoryNotCreated(string $path): self
    {
        return new self('Не удалось создать директорию для SQLite: ' . $path, self::DIRECTORY_NOT_CREATED);
    }

    public static function transactionFailed(string $connection, Throwable $previous): self
    {
        return new self('Транзакция в подключении [' . $connection . '] завершилась с ошибкой: ' . $previous->getMessage(), self::TRANSACTION_FAILED, $previous);
    }

    public static function invalidConfig(string $message): self
    {
        return new self('Некорректная конфигурация базы данных: ' . $message, self::INVALID_CONFIG);
    }

    public static function resolverNotSet(): self
    {
        return new self('Резолвер подключений БД не установлен.', self::RESOLVER_NOT_SET);
    }

    public static function tableNotSpecified(): self
    {
        return new self('Имя таблицы не указано.', self::TABLE_NOT_SPECIFIED);
    }

    public static function invalidSortDirection(string $direction): self
    {
        return new self('Недопустимое направление сортировки: ' . $direction, self::INVALID_SORT_DIRECTION);
    }

    public static function unknownWhereType(string $type): self
    {
        return new self('Неизвестный тип условия WHERE: ' . $type, self::UNKNOWN_WHERE_TYPE);
    }

    public static function invalidOperator(string $operator): self
    {
        return new self('Недопустимый оператор: ' . $operator, self::INVALID_OPERATOR);
    }

    public static function unknownQueryType(string $type): self
    {
        return new self('Неизвестный тип запроса: ' . $type, self::UNKNOWN_QUERY_TYPE);
    }

    public static function parentModelNotSaved(): self
    {
        return new self('Родительская модель не сохранена.', self::PARENT_MODEL_NOT_SAVED);
    }

    public static function jsonSerializationFailed(string $type, string $key): self
    {
        return new self('Невозможно сериализовать значение типа ' . $type . ' в JSON-поле [' . $key . ']', self::JSON_SERIALIZATION_FAILED);
    }

    public static function methodNotExist(string $method): self
    {
        return new self('Метод ' . $method . ' не существует.', self::METHOD_NOT_EXIST);
    }
}