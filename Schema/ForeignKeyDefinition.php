<?php

declare(strict_types=1);

namespace CodeX\DataBase\Schema;

use CodeX\Contract\DataBase\Sql;
use InvalidArgumentException;

/**
 * Fluent-определение внешнего ключа.
 *
 * Использование:
 *   $table->foreign('role_id')
 *       ->references('id')
 *       ->on('roles')
 *       ->onDelete('cascade');
 */
class ForeignKeyDefinition
{
    private const array ALLOWED_ACTIONS = ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION', 'SET DEFAULT'];
    private string $referenceColumn = '';
    private string $referenceTable = '';
    private string $onDelete = 'RESTRICT';
    private string $onUpdate = 'RESTRICT';
    private ?string $constraintName = null;

    public function __construct(private readonly string $column, private readonly string $table = '')
    {
    }

    /**
     * Указывает колонку в родительской таблице.
     */
    public function references(string $column): self
    {
        $this->referenceColumn = $column;
        return $this;
    }

    /**
     * Указывает родительскую таблицу.
     */
    public function on(string $table): self
    {
        $this->referenceTable = $table;
        return $this;
    }

    /**
     * Задаёт действие при удалении.
     */
    public function onDelete(string $action): self
    {
        $action = strtoupper(trim($action));
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new InvalidArgumentException('Недопустимое действие ON DELETE: ' . $action);
        }
        $this->onDelete = $action;
        return $this;
    }

    /**
     * Задаёт действие при обновлении.
     */
    public function onUpdate(string $action): self
    {
        $action = strtoupper(trim($action));
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new InvalidArgumentException('Недопустимое действие ON UPDATE: ' . $action);
        }
        $this->onUpdate = $action;
        return $this;
    }

    /**
     * Задаёт имя ограничения вручную (опционально).
     */
    public function name(string $name): self
    {
        $this->constraintName = $name;
        return $this;
    }

    /**
     * Генерирует SQL для внешнего ключа.
     *
     * Имя ограничения строится по шаблону fk_{текущая_таблица}_{колонка},
     * что гарантирует уникальность в рамках всей базы данных.
     */
    public function toSql(Sql $dialect): string
    {
        $constraintName = $this->constraintName ?? ('fk_' . $this->table . '_' . $this->column);

        return 'CONSTRAINT ' . $dialect->quoteIdentifier($constraintName) . ' FOREIGN KEY (' . $dialect->quoteIdentifier($this->column) . ')' . ' REFERENCES ' . $dialect->quoteIdentifier($this->referenceTable) . ' (' . $dialect->quoteIdentifier($this->referenceColumn) . ')' . ' ON DELETE ' . $this->onDelete . ' ON UPDATE ' . $this->onUpdate;
    }

    /**
     * Возвращает имя колонки внешнего ключа.
     */
    public function getColumn(): string
    {
        return $this->column;
    }

    /**
     * Возвращает имя родительской таблицы.
     */
    public function getReferenceTable(): string
    {
        return $this->referenceTable;
    }

    /**
     * Возвращает имя текущей таблицы.
     */
    public function getTable(): string
    {
        return $this->table;
    }
}