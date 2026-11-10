<?php

declare(strict_types=1);

namespace CodeX\DataBase\Model\Concerns;

use CodeX\DataBase\Model;
use CodeX\DataBase\Model\Relations\BelongsTo;
use CodeX\DataBase\Model\Relations\BelongsToMany;
use CodeX\DataBase\Model\Relations\HasMany;
use CodeX\DataBase\Model\Relations\HasOne;
use CodeX\DataBase\Model\Relations\Relation;
use ReflectionClass;
use ReflectionException;

/**
 * @property array<string, mixed> $relations
 */
trait HasRelations
{
    /**
     * Связь «один к одному» со стороны владельца.
     *
     * foreignKey — это колонка в СВЯЗАННОЙ таблице,
     * ссылающаяся на текущую (родительскую) модель.
     * Поэтому используется getForeignKey() (имя текущей модели),
     * а не getForeignKeyForRelated().
     */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->getKeyName();
        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    public function getForeignKey(): string
    {
        $shortName = new ReflectionClass(static::class)->getShortName();
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));

        return $snake . '_id';
    }

    /**
     * Связь «один ко многим» со стороны владельца.
     *
     * foreignKey — это колонка в СВЯЗАННОЙ таблице,
     * ссылающаяся на текущую (родительскую) модель.
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->getKeyName();
        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * Связь «принадлежит к» (обратная сторона HasOne/HasMany).
     *
     * Здесь $related — это РОДИТЕЛЬСКАЯ модель,
     * поэтому getForeignKeyForRelated() работает корректно:
     * foreignKey = snake_case(имя_родителя) + '_id' — колонка в ТЕКУЩЕЙ таблице.
     *
     * @throws ReflectionException
     */
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $foreignKey = $foreignKey ?: $this->getForeignKeyForRelated($related);
        $ownerKey = $ownerKey ?: (new $related)->getKeyName();
        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }


    /**
     * @throws ReflectionException
     */
    protected function getForeignKeyForRelated(string $related): string
    {
        $shortName = new ReflectionClass($related)->getShortName();
        return $this->snakeCase($shortName) . '_id';
    }

    protected function snakeCase(string $input): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }

    public function belongsToMany(string $related, ?string $pivotTable = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null, ?string $parentKey = null, ?string $relatedKey = null): BelongsToMany
    {
        $relatedInstance = new $related;
        $pivotTable = $pivotTable ?: $this->getPivotTableName($relatedInstance);
        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();
        $relatedPivotKey = $relatedPivotKey ?: $relatedInstance->getForeignKey();
        return new BelongsToMany($this, $related, $pivotTable, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey);
    }

    protected function getPivotTableName(Model $related): string
    {
        $tables = [static::getTableName(), $related->getTableName(),];

        sort($tables);

        return strtolower(implode('_', $tables));
    }

    /**
     * @throws ReflectionException
     */
    public function load(string|array $relations): static
    {
        $relations = is_string($relations) ? [$relations] : $relations;

        foreach ($relations as $relation) {
            $this->loadRelation(trim($relation));
        }

        return $this;
    }

    /**
     * @throws ReflectionException
     */
    protected function loadRelation(string $relation): void
    {
        if ($relation === '') {
            return;
        }

        $parts = explode('.', $relation, 2);

        $name = $parts[0];
        $nested = $parts[1] ?? null;

        if ($this->getRelation($name) === null && method_exists($this, $name)) {
            $this->getRelationValue($name);
        }

        if ($nested !== null) {
            $related = $this->getRelation($name);

            if ($related instanceof Model) {
                $related->loadRelation($nested);
            } elseif (is_array($related)) {
                foreach ($related as $model) {
                    if ($model instanceof Model) {
                        $model->loadRelation($nested);
                    }
                }
            }
        }
    }

    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    protected function getRelationValue(string $key): mixed
    {
        $relation = $this->$key();

        if ($relation instanceof Relation) {
            $results = $relation->getResults();

            $this->setRelation($key, $results);

            return $results;
        }

        return null;
    }

    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;

        return $this;
    }
}