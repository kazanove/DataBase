<?php

declare(strict_types=1);

namespace CodeX\DataBase\Model\Relations;

use CodeX\DataBase\Dialect\Factory as DialectFactory;
use CodeX\DataBase\Model;
use CodeX\DataBase\Model\Query\Builder;
use CodeX\DataBase\Query\Builder as QueryBuilder;
use CodeX\Exception\DataBase;

readonly class BelongsToMany extends Relation
{
    protected string $pivotTable;
    protected string $foreignPivotKey;
    protected string $relatedPivotKey;
    protected ?string $parentKey;
    protected ?string $relatedKey;

    public function __construct(
        Model $parent,
        string $related,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ) {
        parent::__construct($parent, $related, $foreignPivotKey, $parentKey ?? $parent->getKeyName());
        $this->pivotTable = $pivotTable;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;
    }

    public function getResults(): array
    {
        return $this->getRelationQuery()->get();
    }

    public function getRelationQuery(): Builder
    {
        $relatedInstance = new $this->related;
        $query = $relatedInstance::query();
        $relatedKey = $this->relatedKey ?? $relatedInstance->getKeyName();

        $query->selectRaw($relatedInstance->getTableName() . '.*')
            ->join(
                $this->pivotTable,
                $this->pivotTable . '.' . $this->relatedPivotKey,
                '=',
                $relatedInstance->getTableName() . '.' . $relatedKey
            )
            ->where(
                $this->pivotTable . '.' . $this->foreignPivotKey,
                '=',
                $this->parent->getAttribute($this->localKey)
            );

        return $query;
    }

    /**
     * Eager loading с уникальным алиасом `_codex_pivot_key` для предотвращения коллизий.
     */
    public function eagerLoad(array $models, string $relationName): void
    {
        $keys = array_map(fn($m) => $m->getAttribute($this->localKey), $models)
                |> array_filter(...)
                |> array_unique(...);

        if ($keys === []) {
            foreach ($models as $model) {
                $model->setRelation($relationName, []);
            }
            return;
        }

        $relatedInstance = new $this->related;
        $relatedKey = $this->relatedKey ?? $relatedInstance->getKeyName();
        $relatedTable = $relatedInstance->getTableName();
        $pivotAlias = '_codex_pivot_key';

        $query = $relatedInstance::query();

        $query->selectRaw($relatedTable . '.*, ' . $this->pivotTable . '.' . $this->foreignPivotKey . ' AS ' . $pivotAlias)
            ->join(
                $this->pivotTable,
                $this->pivotTable . '.' . $this->relatedPivotKey,
                '=',
                $relatedTable . '.' . $relatedKey
            )
            ->whereIn($this->pivotTable . '.' . $this->foreignPivotKey, $keys);

        $results = $query->get();

        $dictionary = [];

        foreach ($results as $result) {
            $pivotKey = $result->getAttribute($pivotAlias);

            if ($pivotKey === null) {
                continue;
            }

            $dictionary[(string) $pivotKey][] = $result;
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

    public function sync(array $ids): array
    {
        $parentId = $this->parent->getAttribute($this->localKey);

        if ($parentId === null) {
            throw DataBase::parentModelNotSaved();
        }

        $currentIds = $this->getRelatedIds();
        $newIds = array_map('intval', $ids);

        $toDetach = array_values(array_diff($currentIds, $newIds));
        $toAttach = array_values(array_diff($newIds, $currentIds));

        if ($toDetach !== []) {
            $this->detach($toDetach);
        }

        if ($toAttach !== []) {
            $this->attach($toAttach);
        }

        return ['attached' => $toAttach, 'detached' => $toDetach, 'updated' => []];
    }

    public function getRelatedIds(): array
    {
        $parentId = $this->parent->getAttribute($this->localKey);

        if ($parentId === null) {
            return [];
        }

        $rows = $this->newPivotQuery()
            ->select($this->relatedPivotKey)
            ->where($this->foreignPivotKey, '=', $parentId)
            ->getRaw();

        return array_map('intval', array_column($rows, $this->relatedPivotKey));
    }

    public function detach(int|string|array|null $ids = null): int
    {
        $parentId = $this->parent->getAttribute($this->localKey);

        if ($parentId === null) {
            return 0;
        }

        $query = $this->newPivotQuery()->where($this->foreignPivotKey, '=', $parentId);

        if ($ids !== null) {
            if (!is_array($ids)) {
                $ids = [$ids];
            }

            if ($ids === []) {
                return 0;
            }

            $query->whereIn($this->relatedPivotKey, $ids);
        }

        $result = $query->delete();

        return is_int($result) ? $result : 0;
    }

    public function attach(int|string|array $ids, array $attributes = []): int
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        if ($ids === []) {
            return 0;
        }

        $parentId = $this->getParentId();
        $count = 0;

        foreach ($ids as $relatedId) {
            $exists = $this->newPivotQuery()
                ->where($this->foreignPivotKey, '=', $parentId)
                ->where($this->relatedPivotKey, '=', $relatedId)
                ->count();

            if ($exists === 0) {
                $data = array_merge(
                    [
                        $this->foreignPivotKey => $parentId,
                        $this->relatedPivotKey => $relatedId,
                    ],
                    $attributes
                );

                $this->newPivotQuery()->insert($data);
                $count++;
            }
        }

        return $count;
    }

    public function count(): int
    {
        $parentId = $this->parent->getAttribute($this->localKey);

        if ($parentId === null) {
            return 0;
        }

        return $this->newPivotQuery()->where($this->foreignPivotKey, '=', $parentId)->count();
    }

    public function has(int|string|array $ids): bool
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        if ($ids === []) {
            return false;
        }

        $parentId = $this->parent->getAttribute($this->localKey);

        if ($parentId === null) {
            return false;
        }

        $count = $this->newPivotQuery()
            ->where($this->foreignPivotKey, '=', $parentId)
            ->whereIn($this->relatedPivotKey, $ids)
            ->count();

        return $count === count($ids);
    }

    public function getPivotRows(): array
    {
        $parentId = $this->parent->getAttribute($this->localKey);

        if ($parentId === null) {
            return [];
        }

        return $this->newPivotQuery()->where($this->foreignPivotKey, '=', $parentId)->getRaw();
    }

    public function updatePivot(int|string $relatedId, array $attributes): bool
    {
        if ($attributes === []) {
            return false;
        }

        $parentId = $this->getParentId();
        $builder = $this->newPivotQuery()
            ->where($this->foreignPivotKey, '=', $parentId)
            ->where($this->relatedPivotKey, '=', $relatedId);

        $builder->update($this->pivotTable, $attributes);
        $result = $builder->execute();

        return is_int($result) && $result > 0;
    }

    private function newPivotQuery(): QueryBuilder
    {
        $pdo = Model::getConnection();
        $dialect = DialectFactory::createFromPdo($pdo);
        $builder = new QueryBuilder($dialect, $pdo);
        $builder->from($this->pivotTable);

        return $builder;
    }

    private function getParentId(): int|string
    {
        $parentId = $this->parent->getAttribute($this->localKey);

        if ($parentId === null) {
            throw DataBase::parentModelNotSaved();
        }

        return $parentId;
    }

    protected function matchResults(array $models, array $results, string $relationName): void {}

    protected function getDefaultValue(): array
    {
        return [];
    }
}