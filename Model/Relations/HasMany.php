<?php

declare(strict_types=1);

namespace CodeX\DataBase\Model\Relations;

use CodeX\DataBase\Model\Query\Builder;

/**
 * Связь «один ко многим» со стороны владельца.
 */
readonly class HasMany extends Relation
{
    public function getResults(): array
    {
        return $this->fetchWithCache(
            fn() => $this->getRelationQuery()->get()
        );
    }

    public function getRelationQuery(): Builder
    {
        return $this->related::query()
            ->where($this->foreignKey, '=', $this->parent->getAttribute($this->localKey));
    }

    /**
     * Возвращает количество связанных записей.
     */
    public function count(): int
    {
        return $this->fetchWithCache(
            fn() => $this->getRelationQuery()->count()
        );
    }

    protected function matchResults(array $models, array $results, string $relationName): void
    {
        $dictionary = [];

        foreach ($results as $result) {
            $key = $result->getAttribute($this->foreignKey);

            if ($key === null) {
                continue;
            }

            $dictionary[(string) $key][] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);

            if ($key === null) {
                $model->setRelation($relationName, []);
                continue;
            }

            $model->setRelation($relationName, $dictionary[(string) $key] ?? []);
        }
    }

    protected function getDefaultValue(): array
    {
        return [];
    }
}