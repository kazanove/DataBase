<?php

declare(strict_types=1);

namespace CodeX\DataBase\Dialect;

use CodeX\Contract\DataBase\Sql;
use RuntimeException;

class Sqlite implements Sql
{
    public function buildLimitOffset(?int $limit, ?int $offset): string
    {
        if ($limit === null) {
            return '';
        }

        $sql = 'LIMIT ' . $limit;

        if ($offset !== null) {
            $sql .= ' OFFSET ' . $offset;
        }

        return $sql;
    }

    public function buildLock(bool $forUpdate, bool $forShare): string
    {
        return '';
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function getPlaceholderName(string $baseName, int $counter): string
    {
        return ':' . $baseName . '_' . $counter;
    }

    public function getPlaceholderPrefix(): string
    {
        return ':';
    }

    public function supportsReturning(): bool
    {
        return true;
    }

    public function buildReturning(string $table, array $columns): string
    {
        if ($columns === []) {
            return '';
        }

        $quoted = array_map([$this, 'quoteIdentifier'], $columns);

        return 'RETURNING ' . implode(', ', $quoted);
    }

    public function quoteString(string $value): string
    {
        return '\'' . str_replace('\'', '\'\'', $value) . '\'';
    }
    public function getAutoIncrementKeyword(): string
    {
        // SQLite: автоинкремент работает через INTEGER PRIMARY KEY,
        // дополнительное ключевое слово не требуется.
        return '';
    }

    public function getAutoIncrementType(string $baseType): string
    {
        return 'INTEGER';
    }

    public function supportsUnsigned(): bool
    {
        return false;
    }

    public function dropForeignKey(string $name): string
    {
        throw new RuntimeException('SQLite не поддерживает удаление внешнего ключа без пересоздания таблицы.');
    }
    public function buildDecimalType(int $precision, int $scale): string
    {
        return 'NUMERIC';
    }

}
