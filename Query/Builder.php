<?php

declare(strict_types=1);

namespace CodeX\DataBase\Query;

use CodeX\Cache\Manager;
use CodeX\Contract\DataBase\Sql;
use CodeX\Exception\DataBase;
use NoDiscard;
use PDO;

/**
 * Построитель SQL-запросов.
 *
 * Поддерживает SELECT, INSERT, UPDATE, DELETE, JOIN, GROUP BY, HAVING,
 * ORDER BY, LIMIT/OFFSET, блокировки, пакетные вставки и кэширование.
 */
class Builder
{
    private const array ALLOWED_OPERATORS = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS', 'IS NOT',
    ];

    protected Sql $dialect;
    protected PDO $pdo;
    protected string $table = '';
    protected ?string $tableAlias = null;
    protected array $bindings = [];
    protected int $paramCounter = 0;
    protected string $queryType = 'select';
    protected array $columns = ['*'];
    protected array $selects = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected array $wheres = [];
    protected array $joins = [];
    protected array $orderBy = [];
    protected array $orders = [];
    protected array $groups = [];
    protected array $havings = [];
    protected array $setData = [];
    protected bool $forUpdate = false;
    protected bool $forShare = false;
    protected ?int $cacheTtl = null;
    protected ?string $cacheKey = null;
    protected array $cacheTags = [];

    public function __construct(Sql $dialect, PDO $pdo)
    {
        $this->dialect = $dialect;
        $this->pdo = $pdo;
    }

    public function from(string $table, ?string $alias = null): static
    {
        $this->table = $table;
        $this->tableAlias = $alias;

        return $this;
    }

    public function select(array|string ...$columns): static
    {
        $this->selects = [];

        foreach ($columns as $column) {
            if (is_array($column)) {
                foreach ($column as $col) {
                    $this->selects[] = $this->wrap($col);
                }
            } else {
                $this->selects[] = $this->wrap($column);
            }
        }

        return $this;
    }

    protected function wrap(string $column): string
    {
        return $this->quoteColumn($column);
    }

    protected function quoteColumn(string $column): string
    {
        if ($column === '*') {
            return '*';
        }

        if (str_ends_with($column, '.*')) {
            $table = substr($column, 0, -2);
            return $this->dialect->quoteIdentifier($table) . '.*';
        }

        if (str_contains($column, '.')) {
            $parts = explode('.', $column, 2);
            return $this->dialect->quoteIdentifier($parts[0]) . '.' . $this->dialect->quoteIdentifier($parts[1]);
        }

        return $this->dialect->quoteIdentifier($column);
    }

    /**
     * ⚠️ ВНИМАНИЕ: $expression вставляется в SQL БЕЗ экранирования!
     * Используйте только с проверенными данными или параметризацией через $bindings.
     */
    public function selectRaw(string $expression, array $bindings = []): static
    {
        $this->selects[] = $expression;

        if ($bindings !== []) {
            $this->bindings = array_merge($this->bindings, $bindings);
        }

        return $this;
    }

    public function where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        if ($value === null && $operator === '=') {
            return $this->whereNull($column, $boolean);
        }

        if ($value === null && $operator === '!=') {
            return $this->whereNull($column, $boolean, true);
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => (string) $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function whereNull(string $column, string $boolean = 'AND', bool $not = false): static
    {
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'not' => $not,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * ⚠️ ВНИМАНИЕ: $expression вставляется в SQL БЕЗ экранирования!
     */
    public function whereRaw(string $expression, array $bindings = []): static
    {
        $this->wheres[] = [
            'type' => 'raw',
            'expression' => $expression,
            'boolean' => 'AND',
        ];

        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }

    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): static
    {
        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
            'not' => $not,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $direction = strtoupper($direction);

        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw DataBase::invalidSortDirection($direction);
        }

        $this->orderBy[] = ['column' => $column, 'direction' => $direction];

        return $this;
    }

    public function groupBy(string ...$columns): static
    {
        foreach ($columns as $column) {
            $this->groups[] = $this->wrap($column);
        }

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second, ?string $alias = null): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT', $alias);
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER', ?string $alias = null): static
    {
        $this->joins[] = [
            'type' => $type,
            'table' => $table,
            'alias' => $alias,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];

        return $this;
    }

    public function prepareInsert(string $table, array $data): static
    {
        $this->queryType = 'insert';
        $this->table = $table;
        $this->setData = $data;

        return $this;
    }

    public function prepareInsertMultiple(string $table, array $values): static
    {
        if ($values === []) {
            throw DataBase::invalidBatchInsert('Массив значений не может быть пустым');
        }

        $firstRow = reset($values);

        if (!is_array($firstRow)) {
            throw DataBase::invalidBatchInsert('Каждый элемент должен быть массивом');
        }

        $expectedKeys = array_keys($firstRow);

        foreach ($values as $row) {
            if (!is_array($row) || array_keys($row) !== $expectedKeys) {
                throw DataBase::invalidBatchInsert('Все строки должны иметь одинаковые ключи');
            }
        }

        $this->queryType = 'insert_multiple';
        $this->table = $table;
        $this->setData = $values;

        return $this;
    }

    public function prepareUpdate(string $table, array $data): static
    {
        $this->queryType = 'update';
        $this->table = $table;
        $this->setData = $data;

        return $this;
    }

    public function prepareDelete(?string $table = null): static
    {
        $this->queryType = 'delete';

        if ($table !== null) {
            $this->table = $table;
        }

        return $this;
    }

    public function insert(string|array $table, ?array $data = null): bool|int
    {
        if ($data === null && is_array($table)) {
            $data = $table;
            $table = $this->table ?: throw DataBase::tableNotSpecified();
        }

        $this->queryType = 'insert';
        $this->table = $table;
        $this->setData = $data;

        return $this->execute();
    }

    public function insertMultiple(string|array $table, ?array $values = null): bool|int
    {
        if ($values === null && is_array($table)) {
            $values = $table;
            $table = $this->table ?: throw DataBase::tableNotSpecified();
        }

        if ($values === []) {
            return true;
        }

        $this->queryType = 'insert_multiple';
        $this->table = $table;
        $this->setData = $values;

        return $this->execute();
    }

    #[NoDiscard]
    public function execute(): int|bool
    {
        [$sql, $bindings] = $this->getSQL();
        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false) {
            return false;
        }

        $stmt->execute($bindings);

        if (in_array($this->queryType, ['insert', 'insert_multiple'], true)) {
            $id = $this->pdo->lastInsertId();

            if ($id === false) {
                return true;
            }

            return is_numeric($id) ? (int) $id : true;
        }

        if (in_array($this->queryType, ['update', 'delete'], true)) {
            return $stmt->rowCount();
        }

        return true;
    }

    private function fetchRaw(): array
    {
        [$sql, $bindings] = $this->getSQL();
        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false) {
            return [];
        }

        $stmt->execute($bindings);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    #[NoDiscard]
    public function getSQL(): array
    {
        $this->bindings = [];
        $this->paramCounter = 0;

        $sql = match ($this->queryType) {
            'select' => $this->buildSelect(),
            'insert' => $this->buildInsert(),
            'insert_multiple' => $this->buildInsertMultiple(),
            'update' => $this->buildUpdate(),
            'delete' => $this->buildDelete(),
            default => throw DataBase::unknownQueryType($this->queryType),
        };

        return [$sql, $this->bindings];
    }

    protected function buildSelect(): string
    {
        $columns = $this->selects !== [] ? implode(', ', $this->selects) : $this->buildColumns();
        $from = $this->buildFrom();
        $joins = $this->buildJoins();
        $where = $this->buildWheres();
        $group = $this->buildGroupBy();
        $having = $this->buildHavings();
        $order = $this->buildOrderBy();
        $limit = $this->dialect->buildLimitOffset($this->limit, $this->offset);
        $lock = $this->dialect->buildLock($this->forUpdate, $this->forShare);

        return trim('SELECT ' . $columns . ' ' . $from . ' ' . $joins . ' ' . $where . ' ' . $group . ' ' . $having . ' ' . $order . ' ' . $limit . ' ' . $lock);
    }

    protected function buildColumns(): string
    {
        $result = [];

        foreach ($this->columns as $col) {
            if ($col === '*' || str_contains($col, '(')) {
                $result[] = $col;
            } else {
                $result[] = $this->quoteColumn($col);
            }
        }

        return implode(', ', $result);
    }

    protected function buildFrom(): string
    {
        $from = 'FROM ' . $this->dialect->quoteIdentifier($this->table);

        if ($this->tableAlias) {
            $from .= ' AS ' . $this->dialect->quoteIdentifier($this->tableAlias);
        }

        return $from;
    }

    protected function buildJoins(): string
    {
        if ($this->joins === []) {
            return '';
        }

        $sql = '';

        foreach ($this->joins as $j) {
            $table = $this->dialect->quoteIdentifier($j['table']);

            if ($j['alias']) {
                $table .= ' AS ' . $this->dialect->quoteIdentifier($j['alias']);
            }

            $first = $this->quoteColumn($j['first']);
            $second = $this->quoteColumn($j['second']);

            $sql .= ' ' . $j['type'] . ' JOIN ' . $table . ' ON ' . $first . ' ' . $j['operator'] . ' ' . $second;
        }

        return $sql;
    }

    protected function buildWheres(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        return 'WHERE ' . $this->buildWhereConditions($this->wheres);
    }

    protected function buildWhereConditions(array $wheres): string
    {
        $parts = [];

        foreach ($wheres as $i => $w) {
            $bool = $i === 0 ? '' : ' ' . $w['boolean'] . ' ';
            $parts[] = $bool . $this->buildWhereClause($w);
        }

        return implode('', $parts);
    }

    protected function buildWhereClause(array $w): string
    {
        return match ($w['type']) {
            'basic' => $this->buildBasicWhere($w),
            'in' => $this->buildInWhere($w),
            'null' => $this->buildNullWhere($w),
            'raw' => $w['expression'],
            default => throw DataBase::unknownWhereType($w['type']),
        };
    }

    protected function buildBasicWhere(array $w): string
    {
        $col = $this->quoteColumn($w['column']);
        $operator = strtoupper(trim($w['operator']));

        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            throw DataBase::invalidOperator($w['operator']);
        }

        $ph = $this->dialect->getPlaceholderName('w', $this->paramCounter++);
        $this->bindings[$ph] = $w['value'];

        return $col . ' ' . $operator . ' ' . $ph;
    }

    protected function buildInWhere(array $w): string
    {
        $col = $this->quoteColumn($w['column']);

        if ($w['values'] === []) {
            return $w['not'] ? '1=1' : '1=0';
        }

        $placeholders = [];

        foreach ($w['values'] as $val) {
            $ph = $this->dialect->getPlaceholderName('in', $this->paramCounter++);
            $placeholders[] = $ph;
            $this->bindings[$ph] = $val;
        }

        $not = $w['not'] ? 'NOT ' : '';

        return $col . ' ' . $not . 'IN (' . implode(', ', $placeholders) . ')';
    }

    protected function buildNullWhere(array $w): string
    {
        $col = $this->quoteColumn($w['column']);
        $not = $w['not'] ? 'NOT ' : '';

        return $col . ' IS ' . $not . 'NULL';
    }

    protected function buildGroupBy(): string
    {
        if ($this->groups === []) {
            return '';
        }

        return 'GROUP BY ' . implode(', ', $this->groups);
    }

    protected function buildHavings(): string
    {
        if ($this->havings === []) {
            return '';
        }

        $parts = [];

        foreach ($this->havings as $i => $h) {
            $bool = $i === 0 ? '' : ' ' . $h['boolean'] . ' ';
            $col = $this->quoteColumn($h['column']);
            $ph = $this->dialect->getPlaceholderName('h', $this->paramCounter++);
            $this->bindings[$ph] = $h['value'];
            $parts[] = $bool . $col . ' ' . $h['operator'] . ' ' . $ph;
        }

        return 'HAVING ' . implode('', $parts);
    }

    protected function buildOrderBy(): string
    {
        $items = [];

        foreach ($this->orderBy as $item) {
            $items[] = $this->quoteColumn($item['column']) . ' ' . $item['direction'];
        }

        foreach ($this->orders as $rawOrder) {
            $items[] = $rawOrder;
        }

        if ($items === []) {
            return '';
        }

        return 'ORDER BY ' . implode(', ', $items);
    }

    protected function buildInsert(): string
    {
        $cols = array_keys($this->setData);
        $placeholders = [];

        foreach ($cols as $col) {
            $ph = $this->dialect->getPlaceholderName('ins', $this->paramCounter++);
            $placeholders[] = $ph;
            $this->bindings[$ph] = $this->setData[$col];
        }

        $quotedCols = array_map([$this->dialect, 'quoteIdentifier'], $cols);

        return 'INSERT INTO ' . $this->dialect->quoteIdentifier($this->table)
            . ' (' . implode(', ', $quotedCols) . ') VALUES (' . implode(', ', $placeholders) . ')';
    }

    protected function buildInsertMultiple(): string
    {
        $cols = array_keys($this->setData[0]);
        $quotedCols = array_map([$this->dialect, 'quoteIdentifier'], $cols);
        $placeholders = [];

        foreach ($this->setData as $row) {
            $rowPlaceholders = [];

            foreach ($cols as $col) {
                $ph = $this->dialect->getPlaceholderName('ins', $this->paramCounter++);
                $rowPlaceholders[] = $ph;
                $this->bindings[$ph] = $row[$col] ?? null;
            }

            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        return 'INSERT INTO ' . $this->dialect->quoteIdentifier($this->table)
            . ' (' . implode(', ', $quotedCols) . ') VALUES '
            . implode(', ', $placeholders);
    }

    protected function buildUpdate(): string
    {
        $sets = [];

        foreach ($this->setData as $col => $val) {
            $ph = $this->dialect->getPlaceholderName('upd', $this->paramCounter++);
            $sets[] = $this->dialect->quoteIdentifier($col) . ' = ' . $ph;
            $this->bindings[$ph] = $val;
        }

        $sql = 'UPDATE ' . $this->dialect->quoteIdentifier($this->table) . ' SET ' . implode(', ', $sets);
        $where = $this->buildWheres();

        if ($where) {
            $sql .= ' ' . $where;
        }

        return $sql;
    }

    protected function buildDelete(): string
    {
        $sql = 'DELETE FROM ' . $this->dialect->quoteIdentifier($this->table);
        $where = $this->buildWheres();

        if ($where) {
            $sql .= ' ' . $where;
        }

        return $sql;
    }

    public function update(string $table, array $data): static
    {
        $this->queryType = 'update';
        $this->table = $table;
        $this->setData = $data;

        return $this;
    }

    public function delete(?string $table = null): bool|int
    {
        $this->queryType = 'delete';

        if ($table !== null) {
            $this->table = $table;
        }

        return $this->execute();
    }

    public function find(callable $callback): mixed
    {
        return array_find($this->getRaw(), $callback);
    }

    public function getRaw(): array
    {
        if ($this->cacheTtl !== null && Manager::hasStore()) {
            [$sql, $bindings] = $this->getSQL();
            $key = $this->cacheKey ?? md5($sql . serialize($bindings));
            $store = Manager::store();

            if ($this->cacheTags !== []) {
                $store = $store->tags(...$this->cacheTags);
            }

            $cached = $store->get($key);

            if ($cached !== null) {
                return $cached;
            }

            $data = $this->fetchRaw();
            (void) $store->put($key, $data, $this->cacheTtl);

            return $data;
        }

        return $this->fetchRaw();
    }

    public function any(callable $callback): bool
    {
        return array_any($this->getRaw(), $callback);
    }

    public function allMatch(callable $callback): bool
    {
        return array_all($this->getRaw(), $callback);
    }

    public function count(): int
    {
        $clone = clone($this, [
            "queryType" => 'select',
            "selects" => ['COUNT(*) AS count'],
            "limit" => null,
            "offset" => null,
            "orderBy" => []
        ]);

        [$sql, $bindings] = $clone->getSQL();
        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false) {
            return 0;
        }

        $stmt->execute($bindings);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['count'] ?? 0);
    }

    public function cache(int $ttl, ?string $key = null, string ...$tags): static
    {
        $this->cacheTtl = $ttl;
        $this->cacheKey = $key;
        $this->cacheTags = $tags;

        return $this;
    }

    public function avg(string $column): float
    {
        return $this->aggregate('AVG', $column);
    }

    public function sum(string $column): float
    {
        return $this->aggregate('SUM', $column);
    }

    public function min(string $column): float
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column): float
    {
        return $this->aggregate('MAX', $column);
    }

    protected function aggregate(string $function, string $column): float
    {
        $clone = clone($this, [
            "queryType" => 'select',
            "selects" => [$function . '(' . $this->quoteColumn($column) . ') AS aggregate'],
            "limit" => null,
            "offset" => null,
            "orderBy" => []
        ]);

        [$sql, $bindings] = $clone->getSQL();
        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false) {
            return 0.0;
        }

        $stmt->execute($bindings);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (float) ($row['aggregate'] ?? 0);
    }
}