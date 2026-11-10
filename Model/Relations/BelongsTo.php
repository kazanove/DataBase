<?php

declare(strict_types=1);

namespace CodeX\DataBase\Model\Relations;

use CodeX\DataBase\Model;
use CodeX\DataBase\Model\Query\Builder;
use Override;

/**
 * Связь «принадлежит к» (обратная сторона HasOne/HasMany).
 */
readonly class BelongsTo extends Relation
{
    public function __construct(
        Model $parent,
        string $related,
        string $foreignKey,
        string $ownerKey = 'id'
    ) {
        parent::__construct($parent, $related, $foreignKey, $ownerKey);
    }

    public function getResults(): ?Model
    {
        return $this->fetchWithCache(
            fn() => $this->getRelationQuery()->first()
        );
    }

    public function getRelationQuery(): Builder
    {
        return $this->related::query()
            ->where($this->localKey, '=', $this->parent->getAttribute($this->foreignKey));
    }

    #[Override]
    protected function extractKeys(array $models): array
    {
        return array_map(
            fn(Model $m): mixed => $m->getAttribute($this->foreignKey),
            $models
        );
    }

    #[Override]
    protected function applyEagerConstraints(Builder $query, array $keys): void
    {
        $query->whereIn($this->localKey, $keys);
    }

    /**
     * Переопределяем генерацию ключа кэша для BelongsTo,
     * так как здесь ключом является foreignKey, а не localKey.
     */
    #[Override]
    protected function generateCacheKey(): string
    {
        $foreignKeyValue = $this->parent->getAttribute($this->foreignKey);

        return sprintf(
            'relation:%s:%s:%s:%s:%s',
            static::class,
            $this->parent::class,
            (string) $foreignKeyValue,
            $this->related,
            $this->localKey
        );
    }

    protected function matchResults(array $models, array $results, string $relationName): void
    {
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