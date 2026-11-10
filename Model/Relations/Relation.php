<?php

declare(strict_types=1);

namespace CodeX\DataBase\Model\Relations;

use CodeX\Cache\Manager;
use CodeX\DataBase\Model;
use CodeX\DataBase\Model\Query\Builder;

/**
 * Базовый класс связи между моделями.
 *
 * Поддерживает кэширование результатов запросов связей.
 * Кэш автоматически инвалидируется при изменении связанных моделей.
 */
abstract readonly class Relation
{
    /** @var int|null TTL кэша в секундах */
    protected ?int $cacheTtl;

    /** @var string|null Пользовательский ключ кэша */
    protected ?string $cacheKey;

    /** @var array<string> Теги кэша для групповой инвалидации */
    protected array $cacheTags;

    /** @param class-string<Model> $related */
    public function __construct(
        protected Model $parent,
        protected string $related,
        protected string $foreignKey,
        protected string $localKey
    ) {
        $this->cacheTags = [];
        $this->cacheKey = null;
        $this->cacheTtl = null;
    }

    /**
     * Включает кэширование результатов запроса связи.
     *
     * Кэш автоматически инвалидируется при изменении связанных моделей
     * (если используются теги кэша).
     *
     * @param int $ttl Время жизни кэша в секундах
     * @param string|null $key Пользовательский ключ кэша (если null — генерируется автоматически)
     * @param string ...$tags Теги для групповой инвалидации
     *
     * Пример:
     * ```php
     * $user->posts()->cache(3600, 'user_posts', 'user_1')->get();
     * ```
     */
    public function cache(int $ttl, ?string $key = null, string ...$tags): static
    {
        // readonly-класс, поэтому создаём клон с изменёнными свойствами
        return clone($this, [
            'cacheTtl' => $ttl,
            'cacheKey' => $key,
            'cacheTags' => $tags,
        ]);
    }

    /**
     * Общая логика жадной загрузки (Eager Loading).
     */
    public function eagerLoad(array $models, string $relationName): void
    {
        $keys = $this->extractKeys($models)
                |> array_filter(...)
                |> array_values(...)
                |> array_unique(...);

        if ($keys === []) {
            foreach ($models as $model) {
                $model->setRelation($relationName, $this->getDefaultValue());
            }

            return;
        }

        $relatedInstance = new $this->related;
        $query = $relatedInstance::query();

        $this->applyEagerConstraints($query, $keys);

        $results = $query->get();
        $this->matchResults($models, $results, $relationName);
    }

    /**
     * Извлекает ключи из коллекции моделей.
     */
    protected function extractKeys(array $models): array
    {
        return array_map(
            fn(Model $m): mixed => $m->getAttribute($this->localKey),
            $models
        );
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

    // ==========================================
    // КЭШИРОВАНИЕ РЕЗУЛЬТАТОВ
    // ==========================================

    /**
     * Выполняет запрос с учётом кэша.
     *
     * Если кэш включён и данные есть в хранилище — возвращает их.
     * Иначе выполняет запрос и сохраняет результат в кэш.
     *
     * @template T
     * @param callable(): T $fetcher Функция, выполняющая реальный запрос
     * @return T
     */
    protected function fetchWithCache(callable $fetcher): mixed
    {
        // Если кэш не настроен или хранилище недоступно — выполняем запрос напрямую
        if ($this->cacheTtl === null || !Manager::hasStore()) {
            return $fetcher();
        }

        $store = Manager::store();

        if ($this->cacheTags !== []) {
            $store = $store->tags(...$this->cacheTags);
        }

        $key = $this->cacheKey ?? $this->generateCacheKey();

        $cached = $store->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $result = $fetcher();

        (void) $store->put($key, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Генерирует уникальный ключ кэша на основе параметров связи.
     *
     * Ключ включает:
     * - Класс связи (HasOne, HasMany, BelongsTo, BelongsToMany)
     * - Класс родительской модели
     * - Значение локального ключа родителя
     * - Класс связанной модели
     * - Имя внешнего ключа
     */
    protected function generateCacheKey(): string
    {
        $parentKey = $this->parent->getAttribute($this->localKey);

        return sprintf(
            'relation:%s:%s:%s:%s:%s',
            static::class,
            $this->parent::class,
            (string) $parentKey,
            $this->related,
            $this->foreignKey
        );
    }

    /**
     * Инвалидирует кэш связи.
     *
     * Если указаны теги — инвалидирует весь тег.
     * Иначе удаляет конкретный ключ кэша.
     *
     * @param string|null $key Ключ кэша (если null — генерируется автоматически)
     * @param string ...$tags Теги для инвалидации
     */
    public function invalidateCache(?string $key = null, string ...$tags): void
    {
        if (!Manager::hasStore()) {
            return;
        }

        $store = Manager::store();

        if ($tags !== []) {
            $store->tags(...$tags)->flush();
            return;
        }

        $cacheKey = $key ?? $this->generateCacheKey();
        $store->delete($cacheKey);
    }
}