<?php

declare(strict_types=1);

namespace CodeX\DataBase\Dialect;

use CodeX\Contract\DataBase\Sql;

class MySql implements Sql
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
        if ($forUpdate) {
            return 'FOR UPDATE';
        }

        if ($forShare) {
            return 'FOR SHARE';
        }

        return '';
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
        return false;
    }

    public function buildReturning(string $table, array $columns): string
    {
        return '';
    }

    public function quoteString(string $value): string
    {
        return '\'' . str_replace('\'', '\'\'', $value) . '\'';
    }

    public function getAutoIncrementKeyword(): string
    {
        return 'AUTO_INCREMENT';
    }

    public function buildDecimalType(int $precision, int $scale): string
    {
        return 'DECIMAL(' . $precision . ', ' . $scale . ')';
    }

    public function dropForeignKey(string $name): string
    {
        return 'DROP FOREIGN KEY ' . $this->quoteIdentifier($name);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function getAutoIncrementType(string $baseType): string
    {
        return $baseType;
    }

    public function supportsUnsigned(): bool
    {
        return true;
    }
}