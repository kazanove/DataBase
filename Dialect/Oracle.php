<?php
declare(strict_types=1);
namespace CodeX\DataBase\Dialect;

use CodeX\Contract\DataBase\Sql;

class Oracle implements Sql
{
    public function buildLimitOffset(?int $limit, ?int $offset): string
    {
        $sql = '';
        if ($offset !== null) {
            $sql .= ' OFFSET ' . $offset . ' ROWS';
        }
        if ($limit !== null) {
            $sql .= ' FETCH FIRST ' . $limit . ' ROWS ONLY';
        }
        return $sql;
    }

    public function buildLock(bool $forUpdate, bool $forShare): string
    {
        return $forUpdate ? 'FOR UPDATE' : '';
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
        return ' RETURNING ' . implode(', ', $quoted) . ' INTO ' . implode(', ', array_map(static fn($c) => $c . '_out', $quoted));
    }

    public function quoteString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}