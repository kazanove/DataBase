<?php

declare(strict_types=1);

namespace CodeX\DataBase\Model\Relations;

use CodeX\DataBase\Model;
use CodeX\DataBase\Model\Query\Builder;

readonly class BelongsTo extends Relation
{
    public function __construct(Model $parent, string $related, string $foreignKey, string $ownerKey = 'id')
    {
        parent::__construct($parent, $related, $foreignKey, $ownerKey);
    }

    public function getResults(): ?Model
    {
        return $this->getRelationQuery()->first();
    }

    public function getRelationQuery(): Builder
    {
        return $this->related::query()->where($this->localKey, '=', $this->parent->getAttribute($this->foreignKey));
    }

    protected function applyEagerConstraints(Builder $query, array $keys): void
    {
        $query->whereIn($this->localKey, $keys);
    }

    /**
     * Для BelongsTo ключами являются значения foreignKey, а не localKey.
     */
    protected function extractKeys(array $models): array
    {
        return array_map(fn($m) => $m->getAttribute($this->foreignKey), $models);
    }

    protected function matchResults(array $models, array $results, string $relationName): void
    {
        $dictionary = [];

        foreach ($results as $result) {
            $key = $result->getAttribute($this->localKey);

            if ($key === null) {
                continue;
            }

            $dictionary[(string) $key] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->foreignKey);

            if ($key === null) {
                $model->setRelation($relationName, null);
                continue;
            }

            $model->setRelation($relationName, $dictionary[(string) $key] ?? null);
        }
    }
}