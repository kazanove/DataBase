<?php
declare(strict_types=1);

namespace CodeX\DataBase\Schema;

use CodeX\Contract\DataBase\Sql;

class Blueprint
{
    /**
     * Колонки хранятся по имени для возможности обновления
     * при вызове fluent-методов: nullable(), default(), unsigned().
     *
     * @var array<string, ColumnDefinition>
     */
    private array $columns = [];

    /**
     * Порядок колонок.
     *
     * @var array<int, string>
     */
    private array $columnOrder = [];

    /**
     * Дополнительные команды: PRIMARY KEY, уникальные ключи и т.д.
     * Теперь это могут быть как строки, так и массивы с типом.
     *
     * @var array<int, string|array>
     */
    private array $commands = [];

    /**
     * Внешние ключи.
     *
     * @var array<int, ForeignKeyDefinition>
     */
    private array $foreignKeys = [];
    /**
     * Внешние ключи для удаления.
     *
     * @var array<int, string>
     */
    private array $dropForeignKeys = [];
    /** @var array<int, string> */
    private array $dropColumns = [];

    public function __construct(private readonly string $table)
    {
    }

    public function id(string $name = 'id'): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'INT', unsigned: true);
        $col->autoIncrement()->primary();          // PK встраивается в определение колонки
        $this->storeColumn($name, $col);
        return $col;                               // НЕ добавляем команду PRIMARY KEY
    }

    /**
     * Добавляет составной первичный ключ.
     *
     * @param array<int, string> $columns
     */
    public function primary(array $columns): self
    {
        $this->commands[] = ['type' => 'primary', 'columns' => $columns];
        return $this;
    }

    private function storeColumn(string $name, ColumnDefinition $col): void
    {
        if (!isset($this->columns[$name])) {
            $this->columnOrder[] = $name;
        }
        $this->columns[$name] = $col;
    }

    public function integer(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'INT');
        $this->storeColumn($name, $col);
        return $col;
    }

    /**
     * Создаёт колонку BIGINT UNSIGNED.
     */
    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'BIGINT', unsigned: true);
        $this->storeColumn($name, $col);
        return $col;
    }

    /**
     * Создаёт колонку TINYINT.
     */
    public function tinyInteger(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'TINYINT');
        $this->storeColumn($name, $col);
        return $col;
    }

    /**
     * Создаёт колонку SMALLINT.
     */
    public function smallInteger(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'SMALLINT');
        $this->storeColumn($name, $col);
        return $col;
    }

    public function text(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'TEXT');
        $this->storeColumn($name, $col);
        return $col;
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'VARCHAR', length: $length);
        $this->storeColumn($name, $col);
        return $col;
    }

    /**
     * Создаёт колонку DATE.
     */
    public function date(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'DATE');
        $this->storeColumn($name, $col);
        return $col;
    }

    public function boolean(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'TINYINT', length: 1);
        $this->storeColumn($name, $col);
        return $col;
    }

    public function timestamps(): self
    {
        $this->timestamp('created_at')->nullable()->useCurrent();
        $this->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
        return $this;
    }

    /**
     * Добавляет колонку типа TIMESTAMP.
     */
    public function timestamp(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'TIMESTAMP');
        $this->storeColumn($name, $col);
        return $col;
    }

    public function softDeletes(): self
    {
        $deletedAt = $this->timestamp('deleted_at');
        $this->set($deletedAt->nullable());
        return $this;
    }

    /**
     * Обновляет сохранённое определение колонки.
     */
    public function set(ColumnDefinition $col): void
    {
        $this->storeColumn($col->getName(), $col);
    }

    /**
     * Добавляет составной уникальный ключ.
     *
     * @param array<int, string> $columns
     */
    public function unique(array $columns, ?string $name = null): self
    {
        $indexName = $name ?? ('unique_' . $this->table . '_' . implode('_', $columns));
        $this->commands[] = ['type' => 'unique', 'name' => $indexName, 'columns' => $columns];
        return $this;
    }

    /**
     * Добавляет индекс.
     *
     * @param array<int, string> $columns
     */
    public function index(array $columns, ?string $name = null): self
    {
        $indexName = $name ?? ('idx_' . $this->table . '_' . implode('_', $columns));
        $this->commands[] = ['type' => 'index', 'name' => $indexName, 'columns' => $columns];
        return $this;
    }

    /**
     * Создаёт определение внешнего ключа.
     *
     * Использование:
     *   $table->foreign('role_id')
     *       ->references('id')
     *       ->on('roles')
     *       ->onDelete('cascade');
     */
    public function foreign(string $column): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition($column, $this->table);
        $this->foreignKeys[] = $fk;
        return $fk;
    }

    public function toCreateSql(Sql $dialect): string
    {
        $cols = [];
        foreach ($this->columnOrder as $name) {
            $cols[] = $this->columns[$name]->toSql($dialect);
        }

        $allParts = $cols;

        foreach ($this->commands as $command) {
            if (!is_array($command)) {
                $allParts[] = $command;
                continue;
            }

            $type = $command['type'] ?? '';

            if ($type === 'index') {
                continue;
            }

            $quoted = array_map(static fn(string $col): string => $dialect->quoteIdentifier($col), $command['columns'] ?? []);
            $colsStr = implode(', ', $quoted);

            $part = match ($type) {
                'primary' => 'PRIMARY KEY (' . $colsStr . ')',
                'unique' => 'CONSTRAINT ' . $dialect->quoteIdentifier($command['name'] ?? '') . ' UNIQUE (' . $colsStr . ')',
                default => '',
            };

            if ($part !== '') {
                $allParts[] = $part;
            }
        }

        foreach ($this->foreignKeys as $fk) {
            $allParts[] = $fk->toSql($dialect);
        }

        return 'CREATE TABLE ' . $dialect->quoteIdentifier($this->table) . ' (' . implode(', ', $allParts) . ')';
    }

    public function toDropSql(Sql $dialect): string
    {
        return 'DROP TABLE IF EXISTS ' . $dialect->quoteIdentifier($this->table);
    }

    /**
     * Создаёт колонку DECIMAL.
     */
    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'DECIMAL', precision: $precision, scale: $scale);
        $this->storeColumn($name, $col);
        return $col;
    }

    /**
     * Добавляет колонку типа ENUM.
     *
     * Реализована как VARCHAR(50) для кроссплатформенной совместимости.
     * Валидация допустимых значений выполняется на уровне модели.
     *
     * @param string $name Имя колонки.
     * @return ColumnDefinition
     */
    public function enum(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'VARCHAR', length: 50);
        $this->storeColumn($name, $col);
        return $col;
    }

    /**
     * Генерирует SQL для изменения таблицы (ALTER TABLE).
     * Поддерживает добавление колонок, индексов, FK и их удаление.
     */
    public function toAlterSql(Sql $dialect): string
    {
        $parts = [];

        // 1. Новые колонки
        foreach ($this->columnOrder as $name) {
            $col = $this->columns[$name];
            $parts[] = 'ADD COLUMN ' . $col->toSql($dialect);
        }

        // 2. Команды (индексы, уникальные ключи, первичные ключи)
        foreach ($this->commands as $command) {
            if (!is_array($command)) {
                if ($command !== '') {
                    $parts[] = 'ADD ' . $command;
                }
                continue;
            }

            $type = $command['type'] ?? '';

            if ($type === 'index') {
                continue;
            }

            $quoted = array_map(static fn(string $col): string => $dialect->quoteIdentifier($col), $command['columns'] ?? []);
            $colsStr = implode(', ', $quoted);

            $part = match ($type) {
                'primary' => 'ADD PRIMARY KEY (' . $colsStr . ')',
                'unique' => 'ADD CONSTRAINT ' . $dialect->quoteIdentifier($command['name'] ?? '') . ' UNIQUE (' . $colsStr . ')',
                default => '',
            };

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        // 3. Добавление внешних ключей
        foreach ($this->foreignKeys as $fk) {
            $parts[] = 'ADD ' . $fk->toSql($dialect);
        }

        // 4. Удаление внешних ключей
        foreach ($this->dropForeignKeys as $fkName) {
            $parts[] = $dialect->dropForeignKey($fkName);
        }
        foreach ($this->dropColumns as $col) {
            $parts[] = 'DROP COLUMN ' . $dialect->quoteIdentifier($col);
        }
        if ($parts === []) {
            return '';
        }

        return 'ALTER TABLE ' . $dialect->quoteIdentifier($this->table) . ' ' . implode(', ', $parts);
    }

    /**
     * Создаёт колонку BIGINT UNSIGNED AUTO_INCREMENT с первичным ключом.
     */
    public function bigIncrements(string $name = 'id'): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'BIGINT', unsigned: true);
        $col->autoIncrement();
        $this->storeColumn($name, $col);
        $this->commands[] = ['type' => 'primary', 'columns' => [$name]];
        return $col;
    }

    /**
     * Создаёт колонку INT UNSIGNED.
     */
    public function unsignedInteger(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'INT', unsigned: true);
        $this->storeColumn($name, $col);
        return $col;
    }

    /**
     * Создаёт колонку TINYINT UNSIGNED.
     */
    public function unsignedTinyInteger(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'TINYINT', unsigned: true);
        $this->storeColumn($name, $col);
        return $col;
    }

    /**
     * Создаёт колонку SMALLINT UNSIGNED.
     */
    public function unsignedSmallInteger(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'SMALLINT', unsigned: true);
        $this->storeColumn($name, $col);
        return $col;
    }

    /**
     * Создаёт колонку JSON.
     */
    public function json(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'JSON');
        $this->storeColumn($name, $col);
        return $col;
    }

    /**
     * Удаляет внешний ключ по имени.
     */
    public function dropForeign(string $name): self
    {
        $this->dropForeignKeys[] = $name;
        return $this;
    }


    /**
     * Добавляет колонку типа DATETIME.
     */
    public function dateTime(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition(name: $name, type: 'DATETIME');
        $this->storeColumn($name, $col);

        return $col;
    }

    /**
     * Удаляет одну или несколько колонок.
     *
     * @param string|array<int, string> $columns
     */
    public function dropColumn(string|array $columns): static
    {
        foreach ((array)$columns as $column) {
            $this->dropColumns[] = $column;
        }
        return $this;
    }

    public function getIndexSql(Sql $dialect): array
    {
        $statements = [];
        foreach ($this->commands as $command) {
            if (!is_array($command) || ($command['type'] ?? '') !== 'index') {
                continue;
            }
            $quoted = array_map([$dialect, 'quoteIdentifier'], $command['columns'] ?? []);
            $statements[] = 'CREATE INDEX ' . $dialect->quoteIdentifier($command['name'] ?? '') . ' ON ' . $dialect->quoteIdentifier($this->table) . ' (' . implode(', ', $quoted) . ')';
        }
        return $statements;
    }
}