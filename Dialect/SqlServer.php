<?php

declare(strict_types=1);

namespace CodeX\DataBase\Dialect;

use CodeX\Contract\DataBase\Sql;

class SqlServer implements Sql
{
    public function buildLimitOffset(?int $limit, ?int $offset): string
    {
        $sql = '';

        if ($offset !== null) {
            $sql .= 'OFFSET ' . $offset . ' ROWS';

            if ($limit !== null) {
                $sql .= ' FETCH NEXT ' . $limit . ' ROWS ONLY';
            }
        } elseif ($limit !== null) {
            $sql = 'OFFSET 0 ROWS FETCH NEXT ' . $limit . ' ROWS ONLY';
        }

        return $sql;
    }

    public function buildLock(bool $forUpdate, bool $forShare): string
    {
        if ($forUpdate) {
            return 'WITH (UPDLOCK, ROWLOCK)';
        }

        if ($forShare) {
            return 'WITH (HOLDLOCK, ROWLOCK)';
        }

        return '';
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '[' . str_replace(']', ']]', $identifier) . ']';
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
        return 'N\'' . str_replace('\'', '\'\'', $value) . '\'';
    }
}