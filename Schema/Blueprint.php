<?php

declare(strict_types=1);

namespace CodeX\DataBase\Schema;

use CodeX\Contract\DataBase\Sql;

class Blueprint
{
    /** @var array<int, ColumnDefinition> */
    private array $columns = [];

    /** @var array<int, string> */
    private array $commands = [];

    public function __construct(private readonly string $table)
    {
    }

    public function id(string $name = 'id'): self
    {
        $this->columns[] = new ColumnDefinition($name, 'INT', null, false, null, false, true);
        $this->commands[] = 'PRIMARY KEY (' . $name . ')';

        return $this;
    }

    public function integer(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'INT');
        $this->columns[] = $col;

        return $col;
    }

    public function text(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'TEXT');
        $this->columns[] = $col;

        return $col;
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'VARCHAR', $length);
        $this->columns[] = $col;

        return $col;
    }

    public function timestamps(): self
    {
        $this->columns[] = new ColumnDefinition('created_at', 'DATETIME')->nullable();
        $this->columns[] = new ColumnDefinition('updated_at', 'DATETIME')->nullable();

        return $this;
    }

    public function softDeletes(): self
    {
        $this->columns[] = new ColumnDefinition('deleted_at', 'DATETIME')->nullable();

        return $this;
    }

    public function toCreateSql(Sql $dialect): string
    {
        $cols = array_map(static fn($c) => $c->toSql($dialect), $this->columns);
        $allParts = array_merge($cols, $this->commands);

        return 'CREATE TABLE ' . $dialect->quoteIdentifier($this->table) . ' (' . implode(', ', $allParts) . ')';
    }

    public function toDropSql(Sql $dialect): string
    {
        return 'DROP TABLE IF EXISTS ' . $dialect->quoteIdentifier($this->table);
    }
}