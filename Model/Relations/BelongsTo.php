<?php
declare(strict_types=1);
namespace CodeX\DataBase\Model\Relations;

use CodeX\DataBase\Model;
use CodeX\DataBase\Model\Query\Builder;
use DateMalformedStringException;
use JsonException;

readonly class BelongsTo extends Relation
{
    public function __construct(Model $parent, string $related, string $foreignKey, string $ownerKey = 'id')
    {
        parent::__construct($parent, $related, $foreignKey, $ownerKey);
    }

    /**
     * @throws DateMalformedStringException
     * @throws JsonException
     */
    public function getResults(): ?Model
    {
        return $this->getRelationQuery()->first();
    }

    /**
     * @throws DateMalformedStringException
     * @throws JsonException
     */
    public function getRelationQuery(): Builder
    {
        return $this->related::query()->where($this->localKey, '=', $this->parent->getAttribute($this->foreignKey));
    }

    protected function applyEagerConstraints(Builder $query, array $keys): void
    {
        $query->whereIn($this->localKey, $keys);
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

    public function eagerLoad(array $models, string $relationName): void
    {
        $keys = array_map(fn($m) => $m->getAttribute($this->foreignKey), $models)
                |> array_filter(...)
                |> array_unique(...);

        if (empty($keys)) {
            foreach ($models as $model) {
                $model->setRelation($relationName, null);
            }
            return;
        }

        $relatedInstance = new $this->related;
        $query = $relatedInstance::query();
        $query->whereIn($this->localKey, $keys);
        $results = $query->get();

        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute($this->localKey);
            if ($key !== null) {
                $dictionary[(string) $key] = $result;
            }
        }

        foreach ($models as $model) {
            $foreignKeyValue = $model->getAttribute($this->foreignKey);
            $model->setRelation(
                $relationName,
                $foreignKeyValue !== null
                    ? ($dictionary[(string) $foreignKeyValue] ?? null)
                    : null
            );
        }
    }
}