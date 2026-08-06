<?php
declare(strict_types=1);
namespace CodeX\DataBase\Dialect;

use CodeX\Contract\DataBase\Sql;

class PostgreSql implements Sql
{
    public function buildLimitOffset(?int $limit, ?int $offset): string
    {
        $sql = '';
        if ($limit !== null) {
            $sql .= 'LIMIT ' . $limit;
        }
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
        if (empty($columns)) {
            return '';
        }
        $quoted = array_map([$this, 'quoteIdentifier'], $columns);
        return 'RETURNING ' . implode(', ', $quoted);
    }

    public function quoteString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}