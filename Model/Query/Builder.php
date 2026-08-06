<?php
declare(strict_types=1);
namespace CodeX\DataBase\Model\Query;

use CodeX\DataBase\Model;
use CodeX\DataBase\Model\Relations\Relation;
use PDO;

/**
 * Построитель запросов, специфичный для ORM (с гидратацией моделей).
 */
class Builder extends \CodeX\DataBase\Query\Builder
{
    private string $modelClass;
    private array $eagerLoads = [];
    public ?string $softDeleteColumn = null;

    public function setModel(string $modelClass): self { $this->modelClass = $modelClass; return $this; }
    public function setSoftDeleteColumn(string $column): self { $this->softDeleteColumn = $column; return $this; }

    public function applySoftDeleteScope(): self
    {
        if ($this->softDeleteColumn !== null) {
            $this->whereRaw($this->softDeleteColumn . ' IS NULL');
        }
        return $this;
    }

    public function withTrashed(): self
    {
        if ($this->softDeleteColumn) {
            $this->removeWhere($this->softDeleteColumn, 'null');
        }
        return $this;
    }

    public function with(string ...$relations): self
    {
        $this->eagerLoads = array_merge($this->eagerLoads, $relations);
        return $this;
    }

    public function first(): ?Model
    {
        $this->limit(1);
        $models = $this->get();
        // Использование PHP 8.5 array_first()
        return array_first($models);
    }

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

        if (!empty($this->eagerLoads)) {
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
            if (empty($models)) {
                continue;
            }
            $first = array_first($models);
            if (!method_exists($first, $relationName)) {
                continue;
            }
            $relation = $first->$relationName();
            if ($relation instanceof Relation) {
                $relation->eagerLoad($models, $relationName);
            }
        }
    }

    public function firstRaw(): ?array
    {
        $this->limit(1);

        [$sql, $bindings] = $this->getSQL();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    public function removeWhere(string $column, ?string $type = null): self
    {
        $this->wheres = array_values(array_filter(
            $this->wheres,
            static fn(array $w): bool => !(($w['column'] ?? null) === $column && ($type === null || ($w['type'] ?? null) === $type))
        ));
        return $this;
    }
}