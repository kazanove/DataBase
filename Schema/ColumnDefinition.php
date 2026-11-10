<?php

declare(strict_types=1);

namespace CodeX\DataBase\Schema;

use CodeX\Contract\DataBase\Sql;
use InvalidArgumentException;
use JsonException;
use Stringable;

/**
 * Определение колонки таблицы.
 *
 * Изменяемый (mutable) класс: fluent-методы модифицируют текущий объект
 * и возвращают $this. Это позволяет писать цепочки:
 *
 *   $table->unsignedInteger('parent_id')->nullable()->comment('...');
 *
 * без потери изменений, поскольку Blueprint хранит ссылку на этот же объект.
 */
class ColumnDefinition
{
    private string $name;
    private string $type;
    private ?int $length;
    private bool $nullable;
    private mixed $default;
    private bool $hasDefault;
    private bool $unsigned;
    private bool $autoIncrement;
    private ?int $precision;
    private ?int $scale;
    private bool $unique;
    private bool $primary;
    private ?string $comment = null;
    private bool $useCurrent = false;
    private bool $useCurrentOnUpdate = false;
    private ?string $storedAs = null;

    public function __construct(string $name, string $type, ?int $length = null, bool $nullable = false, mixed $default = null, bool $hasDefault = false, bool $unsigned = false, bool $autoIncrement = false, ?int $precision = null, ?int $scale = null, bool $unique = false, bool $primary = false)
    {
        $this->name = $name;
        $this->type = $type;
        $this->length = $length;
        $this->nullable = $nullable;
        $this->default = $default;
        $this->hasDefault = $hasDefault;
        $this->unsigned = $unsigned;
        $this->autoIncrement = $autoIncrement;
        $this->precision = $precision;
        $this->scale = $scale;
        $this->unique = $unique;
        $this->primary = $primary;
    }

    /**
     * Делает колонку nullable.
     *
     * Для генерируемой колонки этот модификатор игнорируется при генерации SQL,
     * так как MySQL не допускает явное NULL / NOT NULL в определении
     * GENERATED ALWAYS AS (...).
     */
    public function nullable(): self
    {
        $this->nullable = true;

        return $this;
    }

    /**
     * Задаёт значение по умолчанию.
     *
     * Boolean-значения нормализуются в 0/1, чтобы корректно
     * обрабатываться числовыми типами данных.
     */
    public function default(mixed $value): self
    {
        $this->assertNotStored('DEFAULT');

        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }

        $this->default = $value;
        $this->hasDefault = true;

        return $this;
    }

    /**
     * Запрещает применять несовместимые модификаторы к генерируемой колонке.
     *
     * @param string $modifier Человекочитаемое имя модификатора для сообщения об ошибке.
     *
     * @throws InvalidArgumentException Если колонка уже является генерируемой.
     */
    private function assertNotStored(string $modifier): void
    {
        if ($this->storedAs !== null) {
            throw new InvalidArgumentException('Модификатор не может быть применён к генерируемой колонке: ' . $modifier);
        }
    }

    /**
     * Делает числовую колонку UNSIGNED.
     *
     * Для генерируемой колонки UNSIGNED допустим как часть типа данных.
     */
    public function unsigned(): self
    {
        $this->unsigned = true;

        return $this;
    }

    /**
     * Делает колонку AUTO_INCREMENT.
     */
    public function autoIncrement(): self
    {
        $this->assertNotStored('AUTO_INCREMENT');

        $this->autoIncrement = true;

        return $this;
    }

    /**
     * Добавляет встроенный UNIQUE к колонке.
     *
     * Для генерируемых колонок встроенный UNIQUE не поддерживается.
     * Используйте отдельный индекс через Blueprint::unique().
     */
    public function unique(): self
    {
        $this->assertNotStored('UNIQUE');

        $this->unique = true;

        return $this;
    }

    /**
     * Добавляет встроенный PRIMARY KEY к колонке.
     *
     * Для генерируемых колонок встроенный PRIMARY KEY не поддерживается.
     */
    public function primary(): self
    {
        $this->assertNotStored('PRIMARY KEY');

        $this->primary = true;

        return $this;
    }

    /**
     * Задаёт комментарий к колонке.
     */
    public function comment(string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    /**
     * Задаёт DEFAULT CURRENT_TIMESTAMP.
     */
    public function useCurrent(): self
    {
        $this->assertNotStored('DEFAULT CURRENT_TIMESTAMP');

        $this->useCurrent = true;

        return $this;
    }

    /**
     * Задаёт ON UPDATE CURRENT_TIMESTAMP.
     */
    public function useCurrentOnUpdate(): self
    {
        $this->assertNotStored('ON UPDATE CURRENT_TIMESTAMP');

        $this->useCurrentOnUpdate = true;

        return $this;
    }

    /**
     * Делает колонку stored generated column.
     *
     * Пример:
     *
     *   $table->string('inventory_active', 50)
     *       ->storedAs('IF(deleted_at IS NULL, inventory_number, NULL)');
     *
     * Сгенерирует:
     *
     *   `inventory_active` varchar(50)
     *   GENERATED ALWAYS AS (IF(deleted_at IS NULL, inventory_number, NULL)) STORED
     *
     * @param string $expression SQL-выражение без завершающей точки с запятой.
     *
     * @throws InvalidArgumentException Если выражение пустое или колонка
     *                                  уже имеет несовместимые модификаторы.
     */
    public function storedAs(string $expression): static
    {
        $expression = trim($expression);
        $expression = rtrim($expression, ';');
        $expression = trim($expression);

        if ($expression === '') {
            throw new InvalidArgumentException('Выражение для генерируемой колонки не может быть пустым.');
        }

        if ($this->autoIncrement === true) {
            throw new InvalidArgumentException('Генерируемая колонка не может быть AUTO_INCREMENT.');
        }

        if ($this->hasDefault === true || $this->useCurrent === true || $this->useCurrentOnUpdate === true) {
            throw new InvalidArgumentException('Генерируемая колонка не может иметь значение по умолчанию.');
        }
        if ($this->nullable === true) {
            throw new InvalidArgumentException('Генерируемая колонка не может быть nullable.');
        }
        if ($this->unique === true || $this->primary === true) {
            throw new InvalidArgumentException('Генерируемая колонка не может иметь встроенные ограничения UNIQUE или PRIMARY KEY.');
        }

        $this->storedAs = $expression;

        return $this;
    }

    /**
     * Генерирует SQL-определение колонки.
     */
    public function toSql(Sql $dialect): string
    {
        if ($this->storedAs !== null) {
            return $this->toStoredSql($dialect);
        }

        $sql = $dialect->quoteIdentifier($this->name) . ' ' . $this->compileType($dialect);

        if ($this->autoIncrement === true) {
            $keyword = $dialect->getAutoIncrementKeyword();

            if ($keyword !== '') {
                $sql .= ' ' . $keyword;
            }
        }

        $sql .= $this->nullable ? ' NULL' : ' NOT NULL';

        if ($this->useCurrent === true) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif ($this->hasDefault === true) {
            $sql .= ' DEFAULT ' . $this->compileDefaultValue($dialect);
        }

        if ($this->useCurrentOnUpdate === true) {
            $sql .= ' ON UPDATE CURRENT_TIMESTAMP';
        }

        if ($this->unique === true) {
            $sql .= ' UNIQUE';
        }

        if ($this->primary === true) {
            $sql .= ' PRIMARY KEY';
        }

        if ($this->comment !== null) {
            $sql .= ' COMMENT ' . $dialect->quoteString($this->comment);
        }

        return $sql;
    }

    /**
     * Генерирует SQL для stored generated column.
     */
    private function toStoredSql(Sql $dialect): string
    {
        $type = $this->compileType($dialect);

        $sql = $dialect->quoteIdentifier($this->name) . ' ' . $type;
        $sql .= ' GENERATED ALWAYS AS (' . $this->storedAs . ') STORED';

        if ($this->comment !== null) {
            $sql .= ' COMMENT ' . $dialect->quoteString($this->comment);
        }

        return $sql;
    }

    /**
     * Собирает SQL-тип колонки.
     */
    private function compileType(Sql $dialect): string
    {
        $type = $this->autoIncrement === true ? $dialect->getAutoIncrementType($this->type) : $this->type;

        if ($this->precision !== null && strtolower($this->type) === 'decimal') {
            $type = $dialect->buildDecimalType($this->precision, $this->scale ?? 0);
        }

        if ($this->length !== null && in_array(strtoupper($this->type), ['VARCHAR', 'CHAR', 'TINYINT', 'SMALLINT', 'INT', 'INTEGER', 'BIGINT'], true)) {
            $type .= '(' . $this->length . ')';
        }

        if ($this->unsigned === true && $dialect->supportsUnsigned()) {
            $type .= ' UNSIGNED';
        }

        return $type;
    }

    /**
     * Формирует SQL-значение DEFAULT с учётом типа колонки.
     */
    private function compileDefaultValue(Sql $dialect): string
    {
        $value = $this->default;

        if ($value === null) {
            return 'NULL';
        }

        if ($this->isNumericType()) {
            return $this->compileNumericDefault($value);
        }

        return $this->compileStringDefault($dialect, $value);
    }

    /**
     * Проверяет, является ли тип колонки числовым.
     */
    private function isNumericType(): bool
    {
        return in_array(strtoupper($this->type), ['INT', 'INTEGER', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT', 'DECIMAL', 'NUMERIC', 'FLOAT', 'DOUBLE', 'REAL',], true);
    }

    /**
     * Формирует числовое значение DEFAULT без кавычек.
     *
     * @throws InvalidArgumentException Если значение не может быть числом.
     */
    private function compileNumericDefault(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string)$value;
        }

        if (is_float($value)) {
            return $this->formatFloat($value);
        }

        if ($value instanceof Stringable) {
            $value = (string)$value;
        }

        if (is_string($value) && is_numeric($value)) {
            return $this->normalizeNumericString($value);
        }

        throw new InvalidArgumentException('Некорректное числовое значение по умолчанию для колонки «' . $this->name . '».');
    }

    /**
     * Форматирует float без локальной запятой и лишних нулей.
     */
    private function formatFloat(float $value): string
    {
        $formatted = sprintf('%.10F', $value)
                |> (static fn($x) => rtrim($x, '0'))
                |> (static fn($x) => rtrim($x, '.'));

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * Нормализует числовую строку для SQL-литерала.
     */
    private function normalizeNumericString(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '0';
        }

        if ($value[0] === '+') {
            $value = substr($value, 1);
        }

        if (str_contains($value, '.') || stripos($value, 'e') !== false) {
            return $this->formatFloat((float)$value);
        }

        if ($value[0] === '-') {
            $digits = ltrim(substr($value, 1), '0');

            return '-' . ($digits === '' ? '0' : $digits);
        }

        $digits = ltrim($value, '0');

        return $digits === '' ? '0' : $digits;
    }

    /**
     * Формирует строковое значение DEFAULT с экранированием.
     *
     * @throws InvalidArgumentException Если значение не удалось сериализовать.
     */
    private function compileStringDefault(Sql $dialect, mixed $value): string
    {
        if (is_bool($value)) {
            return $dialect->quoteString($value ? '1' : '0');
        }

        if ($value instanceof Stringable) {
            $value = (string)$value;
        }

        if (is_int($value) || is_float($value)) {
            return $dialect->quoteString((string)$value);
        }

        if (is_array($value)) {
            try {
                $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('Не удалось сериализовать значение по умолчанию для колонки «' . $this->name . '».', 0, $exception);
            }

            return $dialect->quoteString($encoded);
        }

        if (is_string($value)) {
            return $dialect->quoteString($value);
        }

        return $dialect->quoteString((string)$value);
    }

    /**
     * Возвращает имя колонки.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Проверяет, является ли колонка встроенной уникальной.
     */
    public function isUnique(): bool
    {
        return $this->unique;
    }

    /**
     * Проверяет, является ли колонка встроенное первичным ключом.
     */
    public function isPrimary(): bool
    {
        return $this->primary;
    }

    /**
     * Проверяет, является ли колонка новой.
     */
    public function isNew(): bool
    {
        return true;
    }
}