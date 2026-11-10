<?php

declare(strict_types=1);

namespace CodeX\DataBase\Model\Query;

use CodeX\DataBase\Model;
use CodeX\DataBase\Model\Relations\Relation;
use CodeX\Exception\DataBase;
use Override;
use PDO;

/**
 * Построитель запросов, специфичный для ORM (с гидратацией моделей).
 */
class Builder extends \CodeX\DataBase\Query\Builder
{
    public ?string $softDeleteColumn = null;
    private string $modelClass;
    private array $eagerLoads = [];

    public function setModel(string $modelClass): static
    {
        $this->modelClass = $modelClass;

        return $this;
    }

    public function setSoftDeleteColumn(string $column): static
    {
        $this->softDeleteColumn = $column;

        return $this;
    }

    public function applySoftDeleteScope(): static
    {
        if ($this->softDeleteColumn !== null) {
            $this->whereNull($this->softDeleteColumn);
        }
        return $this;
    }

    public function withTrashed(): static
    {
        if ($this->softDeleteColumn) {
            $this->removeWhere($this->softDeleteColumn, 'null');
        }

        return $this;
    }

    public function removeWhere(string $column, ?string $type = null): static
    {
        $this->wheres = array_values(array_filter($this->wheres, static fn(array $w): bool => !(($w['column'] ?? null) === $column && ($type === null || ($w['type'] ?? null) === $type))));

        return $this;
    }

    public function with(string ...$relations): static
    {
        $this->eagerLoads = array_merge($this->eagerLoads, $relations);

        return $this;
    }

    /**
     * Находит модель по первичному ключу.
     */
    public function find(int|string $id): ?Model
    {
        $modelClass = $this->modelClass;
        $keyName = (new $modelClass)->getKeyName();
        $clone = clone $this;
        return $clone->where($keyName, '=', $id)->first();
    }

    /**
     * Переопределяет родительский first() для возврата модели.
     */
    #[Override]
    public function first(): ?Model
    {
        $clone = clone $this;
        $clone->limit(1);
        $models = $clone->get();
        return array_first($models);
    }

    /**
     * Переопределяет родительский get() для гидратации моделей.
     */
    #[Override]
    public function get(): array
    {
        [$sql, $bindings] = $this->getSQL();
        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false) {
            return [];
        }

        $stmt->execute($bindings);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $models = [];

        foreach ($rows as $row) {
            $models[] = $this->modelFromRow($row);
        }

        if ($this->eagerLoads !== []) {
            $this->loadRelations($models);
        }

        return $models;
    }

    protected function modelFromRow(array $row): Model
    {
        $class = $this->modelClass;
        $model = new $class;
        $model->setRawAttributes($row);
        $model->syncOriginal();
        $model->exists = true;

        return $model;
    }

    protected function loadRelations(array $models): void
    {
        foreach ($this->eagerLoads as $relationName) {
            $this->loadNestedRelations($models, $relationName);
        }
    }

    /**
     * Рекурсивно грузит связь по dot-нотации без N+1 на каждом уровне.
     */
    protected function loadNestedRelations(array $models, string $path): void
    {
        if ($path === '' || $models === []) {
            return;
        }

        $parts = explode('.', $path, 2);
        $name = $parts[0];
        $rest = $parts[1] ?? null;

        $first = array_first($models);
        if (!($first instanceof Model) || !method_exists($first, $name)) {
            return;
        }

        $relation = $first->$name();
        if (!$relation instanceof Relation) {
            return;
        }

        $relation->eagerLoad($models, $name);

        if ($rest === null) {
            return;
        }

        $relatedModels = [];
        foreach ($models as $model) {
            $value = $model->getRelation($name);
            if ($value instanceof Model) {
                $relatedModels[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Model) {
                        $relatedModels[] = $item;
                    }
                }
            }
        }

        if ($relatedModels !== []) {
            $this->loadNestedRelations($relatedModels, $rest);
        }
    }

    public function firstRaw(): ?array
    {
        $clone = clone $this;
        $clone->limit(1);

        [$sql, $bindings] = $clone->getSQL();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    /**
     * Сравнивает две колонки между собой (без параметризации значений).
     */
    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'AND'): static
    {
        $this->wheres[] = ['type' => 'raw', 'expression' => $this->quoteColumn($first) . ' ' . $this->normalizeOperator($operator) . ' ' . $this->quoteColumn($second), 'boolean' => $this->normalizeBoolean($boolean), 'bindings' => [],];

        return $this;
    }


    /**
     * Добавляет условие EXISTS / NOT EXISTS с поддержкой именованных плейсхолдеров.
     *
     * Пример:
     *   $builder->whereExists(
     *       'SELECT 1 FROM permissions WHERE user_id = :userId AND slug = :slug',
     *       ['userId' => 5, 'slug' => 'admin.edit']
     *   );
     */
    public function whereExists(
        string $sql,
        array $bindings = [],
        string $boolean = 'AND',
        bool $not = false
    ): static {
        if ($bindings === []) {
            $this->wheres[] = [
                'type' => 'raw',
                'expression' => ($not ? 'NOT EXISTS (' : 'EXISTS (') . $sql . ')',
                'boolean' => $this->normalizeBoolean($boolean),
                'bindings' => [],
            ];

            return $this;
        }

        // 1. Собираем порядок появления именованных плейсхолдеров в SQL.
        //    Используем preg_match_all, чтобы сохранить порядок и дубликаты.
        $orderedNames = [];
        preg_match_all('/:([a-zA-Z_]\w*)/', $sql, $matches);

        foreach ($matches[1] as $name) {
            $orderedNames[] = $name;
        }

        // 2. Валидация: все ли имена из SQL присутствуют в $bindings.
        $missing = array_diff($orderedNames, array_keys($bindings));

        if ($missing !== []) {
            throw DataBase::invalidConfig(
                'В whereExists() указаны плейсхолдеры без значений: :' . implode(', :', $missing)
            );
        }

        // 3. Предупреждаем о лишних параметрах (не блокируем, но логируем).
        $extra = array_diff(array_keys($bindings), $orderedNames);

        if ($extra !== []) {
            implode(', ', $extra)
                |> (static fn($x) => sprintf('whereExists: переданы неиспользуемые параметры: %s', $x))
                |> error_log(...);
        }

        // 4. Заменяем именованные плейсхолдеры на позиционные '?'
        //    ВАЖНО: делаем это через callback, который также формирует
        //    список bindings в правильном порядке.
        $orderedBindings = [];
        $placeholderIndex = 0;

        $processedSql = preg_replace_callback(
            '/:([a-zA-Z_]\w*)/',
            static function (array $m) use (&$orderedBindings, &$placeholderIndex, $bindings): string {
                $name = $m[1];
                $orderedBindings[] = $bindings[$name];
                $placeholderIndex++;

                return '?';
            },
            $sql
        );

        // 5. Формируем условие.
        $this->wheres[] = [
            'type' => 'raw',
            'expression' => ($not ? 'NOT EXISTS (' : 'EXISTS (') . $processedSql . ')',
            'boolean' => $this->normalizeBoolean($boolean),
            'bindings' => $orderedBindings, // ✅ Порядок гарантирован
        ];

        return $this;
    }

    /**
     * Добавляет условие NOT EXISTS.
     *
     * @param string $sql
     * @param array $bindings
     * @param string $boolean
     * @return Builder
     */
    public function whereNotExists(string $sql, array $bindings = [], string $boolean = 'AND'): static
    {
        return $this->whereExists($sql, $bindings, $boolean, true);
    }

}