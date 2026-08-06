<?php
declare(strict_types=1);

namespace CodeX\DataBase\Schema;

use CodeX\Contract\DataBase\Sql;

/**
 * Класс определения колонки.
 * Использует паттерн Immutable (неизменяемый объект).
 */
class ColumnDefinition
{
    private bool $nullable = false;
    private mixed $default = null;
    private bool $hasDefault = false;
    private bool $unsigned = false;

    public function __construct(private readonly string $name, private readonly string $type, private readonly ?int $length = null)
    {
    }

    /**
     * Использование PHP 8.5 Clone With для иммутабельного изменения свойств.
     */
    public function nullable(): self
    {
        return clone($this, ['nullable' => true]);
    }

    public function default(mixed $value): self
    {
        return clone($this, ['default' => $value, 'hasDefault' => true]);
    }

    public function unsigned(): self
    {
        return clone($this, ['unsigned' => true]);
    }

    public function toSql(Sql $dialect): string
    {
        $sql = $dialect->quoteIdentifier($this->name) . ' ' . $this->type;

        if ($this->length !== null && in_array($this->type, ['VARCHAR', 'CHAR'], true)) {
            $sql .= '(' . $this->length . ')';
        }

        if ($this->unsigned) {
            $sql .= ' UNSIGNED';
        }
        if (!$this->nullable) {
            $sql .= ' NOT NULL';
        }

        if ($this->hasDefault) {
            $sql .= ' DEFAULT ' . $dialect->quoteString((string)$this->default);
        }

        return $sql;
    }
}