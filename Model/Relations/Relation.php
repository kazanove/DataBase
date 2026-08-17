<?php

declare(strict_types=1);

namespace CodeX\DataBase\Model\Relations;

use CodeX\DataBase\Model;
use CodeX\DataBase\Model\Query\Builder;

/**
 * Базовый класс связи между моделями.
 *
 * Содержит общую логику eager loading, чтобы избежать дублирования
 * в HasOne, HasMany, BelongsTo, BelongsToMany.
 */
abstract readonly class Relation
{
    /**
     * @param class-string<Model> $related
     */
    public function __construct(
        protected Model $parent,
        protected string $related,
        protected string $foreignKey,
        protected string $localKey
    ) {
    }

    public function eagerLoad(array $models, string $relationName): void
    {
        $keys = $this->extractKeys($models)
                |> array_filter(...)
                |> array_unique(...);

        if ($keys === []) {
            foreach ($models as $model) {
                $model->setRelation($relationName, $this->getDefaultValue());
            }
            return;
        }

        $query = $this->getRelationQuery();
        $this->applyEagerConstraints($query, $keys);
        $results = $query->get();
        $this->matchResults($models, $results, $relationName);
    }

    /**
     * Извлекает ключи для eager loading из массива моделей.
     * Может быть переопределено в наследниках (например, BelongsTo).
     */
    protected function extractKeys(array $models): array
    {
        return array_map(fn($m) => $m->getAttribute($this->localKey), $models);
    }

    protected function getDefaultValue(): mixed
    {
        return null;
    }

    abstract public function getRelationQuery(): Builder;

    protected function applyEagerConstraints(Builder $query, array $keys): void
    {
        $query->whereIn($this->foreignKey, $keys);
    }

    abstract protected function matchResults(array $models, array $results, string $relationName): void;

    abstract public function getResults(): mixed;
}