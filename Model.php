<?php
declare(strict_types=1);

namespace CodeX\DataBase;

use Closure;
use CodeX\DataBase\Dialect\Factory;
use CodeX\DataBase\Model\Query\Builder;
use CodeX\DataBase\Model\Relations\BelongsTo;
use CodeX\DataBase\Model\Relations\BelongsToMany;
use CodeX\DataBase\Model\Relations\HasMany;
use CodeX\DataBase\Model\Relations\HasOne;
use CodeX\DataBase\Model\Relations\Relation;
use CodeX\Exception\DataBase;
use CodeX\Validation\Validator;
use DateMalformedStringException;
use DateTime;
use DateTimeInterface;
use JsonException;
use JsonSerializable;
use NoDiscard;
use PDO;
use ReflectionClass;
use ReflectionException;

/**
 * Базовый класс Active Record модели.
 *
 * Примечание по архитектуре (PHP 8.4 Property Hooks):
 * В PHP 8.4 появились хуки свойств, позволяющие внедрять логику геттеров/сеттеров
 * прямо в объявление свойств. Однако для ORM, где атрибуты динамические (загружаются
 * из БД и хранятся в массиве $attributes), Property Hooks неприменимы напрямую.
 * Поэтому мы сохраняем использование магических методов __get, __set, __isset и __unset.
 *
 * @method mixed getOriginal(?string $key = null)
 */
abstract class Model implements JsonSerializable
{
    protected const string CREATED_AT = 'created_at';
    protected const string UPDATED_AT = 'updated_at';
    protected const string DELETED_AT = 'deleted_at';

    protected static ?Closure $connectionResolver = null;
    protected static bool $incrementing = true;
    protected static string $primaryKey = 'id';
    protected static string $keyType = 'int';
    protected static ?string $table = null;
    protected static bool $useSoftDeletes = false;
    protected static array $fillable = [];
    protected static array $guarded = ['id'];
    protected static ?string $connection = null;
    protected static array $eventListeners = [];

    public bool $exists = false;
    protected bool $fireEvents = true;
    protected array $casts = [];
    protected array $hidden = [];
    protected bool $timestamps = true;
    protected array $attributes = [];
    private array $original = [];
    private array $relations = [];

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
        $this->syncOriginal();
    }

    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    protected function isFillable(string $key): bool
    {
        if (in_array($key, static::$guarded, true)) {
            return false;
        }
        if (empty(static::$fillable)) {
            return true;
        }
        return in_array($key, static::$fillable, true);
    }

    public function setAttribute(string $key, mixed $value): self
    {
        $mutator = 'set' . str_replace('_', '', ucwords($key, '_')) . 'Attribute';

        if (method_exists($this, $mutator)) {
            $this->$mutator($value);
            return $this;
        }

        if (is_array($value)
            && isset($this->casts[$key])
            && !in_array($this->casts[$key], ['array', 'json'], true)
        ) {
            error_log(sprintf(
                'setAttribute: массив передан в скалярный атрибут %s::%s (cast: %s)',
                static::class, $key, $this->casts[$key]
            ));
        }

        $this->attributes[$key] = $value;
        return $this;
    }

    public function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    // ==========================================
    // СИСТЕМА СОБЫТИЙ
    // ==========================================

    public static function creating(Closure $callback): void { static::listen('creating', $callback); }
    public static function created(Closure $callback): void { static::listen('created', $callback); }
    public static function updating(Closure $callback): void { static::listen('updating', $callback); }
    public static function updated(Closure $callback): void { static::listen('updated', $callback); }
    public static function saving(Closure $callback): void { static::listen('saving', $callback); }
    public static function saved(Closure $callback): void { static::listen('saved', $callback); }
    public static function deleting(Closure $callback): void { static::listen('deleting', $callback); }
    public static function deleted(Closure $callback): void { static::listen('deleted', $callback); }
    public static function softDeleting(Closure $callback): void { static::listen('softDeleting', $callback); }
    public static function softDeleted(Closure $callback): void { static::listen('softDeleted', $callback); }

    public static function listen(string $event, Closure $listener): void
    {
        static::$eventListeners[static::class][$event][] = $listener;
    }

    /**
     * Очищает зарегистрированные слушатели событий для текущей модели.
     * Критически важно вызывать в long-running процессах (Swoole, RoadRunner, Octane),
     * чтобы избежать утечек памяти и дублирования срабатываний коллбэков.
     */
    public static function flushEventListeners(): void
    {
        unset(static::$eventListeners[static::class]);
    }

    // ==========================================
    // ЗАПРОСЫ И ПОДКЛЮЧЕНИЯ
    // ==========================================

    public static function find(int $id): ?static
    {
        /** @var static|null $result */
        $result = static::query()->where(static::$primaryKey, '=', $id)->first();
        return $result;
    }

    public static function query(): Builder
    {
        $pdo = self::getConnection();
        $dialect = Factory::createFromPdo($pdo);

        $builder = new Builder($dialect, $pdo);
        $builder->from(static::getTableName());
        $builder->setModel(static::class);

        if (static::$useSoftDeletes) {
            $builder->setSoftDeleteColumn(static::DELETED_AT);
            $builder->applySoftDeleteScope();
        }

        return $builder;
    }

    public static function setConnectionResolver(Closure $resolver): void
    {
        self::$connectionResolver = $resolver;
    }

    public static function getConnection(): PDO
    {
        if (self::$connectionResolver === null) {
            throw DataBase::resolverNotSet();
        }
        return (self::$connectionResolver)(static::$connection);
    }

    public static function getTableName(): string
    {
        if (static::$table !== null) {
            return static::$table;
        }
        $className = new ReflectionClass(static::class)->getShortName();
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className)) . 's';
    }

    // ==========================================
    // СОХРАНЕНИЕ И УДАЛЕНИЕ
    // ==========================================

    /**
     * @throws JsonException
     */
    #[NoDiscard]
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        if (static::$useSoftDeletes && !$this->trashed()) {
            if (!$this->fireEvent('softDeleting')) {
                return false;
            }

            $this->setAttribute(static::DELETED_AT, date('Y-m-d H:i:s'));
            $result = $this->save();

            if ($result) {
                $this->fireEvent('softDeleted');
            }
            return $result;
        }

        return $this->forceDelete();
    }

    public function trashed(): bool
    {
        return static::$useSoftDeletes && !empty($this->getAttribute(static::DELETED_AT));
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    protected function fireEvent(string $event): bool
    {
        if (!$this->fireEvents) {
            return true;
        }

        $method = 'on' . ucfirst($event);
        if (method_exists($this, $method) && $this->$method() === false) {
            return false;
        }

        $listeners = static::$eventListeners[static::class][$event] ?? [];

        // Использование PHP 8.4 array_all() для проверки, что ни один слушатель не вернул false
        return array_all($listeners, fn(Closure $listener) => $listener($this) !== false);
    }

    /**
     * @throws JsonException
     */
    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }

        if (!$this->fireEvent('saving')) {
            return false;
        }

        if ($this->exists) {
            if (!$this->fireEvent('updating')) {
                return false;
            }
        } elseif (!$this->fireEvent('creating')) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        if ($this->timestamps) {
            if (!$this->exists && static::CREATED_AT) {
                $this->setAttribute(static::CREATED_AT, $now);
            }
            if (static::UPDATED_AT) {
                $this->setAttribute(static::UPDATED_AT, $now);
            }
        }

        $result = $this->exists ? $this->performUpdate() : $this->performInsert();

        if ($result) {
            $this->fireEvent('saved');
            $this->exists ? $this->fireEvent('updated') : $this->fireEvent('created');
            $this->syncOriginal();
        }

        return $result;
    }

    public function validate(): bool
    {
        try {
            return new Validator()->validate($this);
        } catch (\CodeX\Exception\Validation) {
            return false;
        }
    }

    /**
     * @throws JsonException
     */
    protected function performUpdate(): bool
    {
        $dirty = $this->getDirty();
        if (empty($dirty)) {
            return true;
        }

        $serialized = [];
        foreach ($dirty as $key => $value) {
            $serialized[$key] = $this->serializeAttribute($key, $value);
        }

        $builder = static::query();
        $builder->where(static::$primaryKey, '=', $this->getKey());
        $builder->prepareUpdate(static::getTableName(), $serialized);

        [$sql, $bindings] = $builder->getSQL();
        $stmt = static::getConnection()->prepare($sql);
        if ($stmt === false) {
            return false;
        }
        return $stmt->execute($bindings);
    }

    protected function getDirty(): array
    {
        return array_filter($this->attributes, function ($value, $key) {
            return !array_key_exists($key, $this->original) || $value !== $this->original[$key];
        }, ARRAY_FILTER_USE_BOTH);
    }

    public function getKey(): mixed
    {
        return $this->attributes[static::$primaryKey] ?? null;
    }

    /**
     * @throws JsonException
     */
    protected function performInsert(): bool
    {
        $builder = static::query();
        $builder->prepareInsert(static::getTableName(), $this->getAttributesForSave());

        [$sql, $bindings] = $builder->getSQL();
        $pdo = static::getConnection();
        $stmt = $pdo->prepare($sql);

        if ($stmt->execute($bindings)) {
            if (static::$incrementing && empty($this->attributes[static::$primaryKey])) {
                $id = $pdo->lastInsertId();
                if ($id !== false) {
                    $this->attributes[static::$primaryKey] = static::$keyType === 'int' ? (int) $id : $id;
                }
            }
            $this->exists = true;
            return true;
        }

        return false;
    }

    #[NoDiscard]
    public function forceDelete(): bool
    {
        if (!$this->fireEvent('deleting')) {
            return false;
        }

        $builder = static::query();
        $builder->withTrashed()->where(static::$primaryKey, '=', $this->getKey());
        $builder->prepareDelete(static::getTableName());

        [$sql, $bindings] = $builder->getSQL();
        $result = static::getConnection()->prepare($sql)->execute($bindings);

        if ($result) {
            $this->exists = false;
            $this->fireEvent('deleted');
        }

        return $result;
    }

    // ==========================================
    // СЕРИАЛИЗАЦИЯ И КАСТИНГ
    // ==========================================

    public function setRawAttributes(array $attributes): self
    {
        $this->attributes = $attributes;
        return $this;
    }

    public function getForeignKey(): string
    {
        $shortName = new ReflectionClass(static::class)->getShortName();
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
        return $snake . '_id';
    }

    /**
     * @throws DateMalformedStringException
     * @throws JsonException
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @throws DateMalformedStringException
     * @throws JsonException
     */
    public function toArray(?array $only = null): array
    {
        $attributes = $only === null ? $this->attributes : array_intersect_key($this->attributes, array_flip($only));

        if (!empty($this->hidden)) {
            $attributes = array_diff_key($attributes, array_flip($this->hidden));
        }

        foreach ($attributes as $key => $value) {
            $attributes[$key] = $this->castAttribute($key, $value);
        }

        foreach ($this->relations as $key => $value) {
            if ($value instanceof self) {
                $attributes[$key] = $value->toArray();
            } elseif (is_array($value)) {
                $attributes[$key] = array_map(static fn($m) => $m instanceof self ? $m->toArray() : $m, $value);
            } else {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    /**
     * @throws DateMalformedStringException
     * @throws JsonException
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key]) || $value === null) {
            return $value;
        }

        $castType = $this->casts[$key];

        if (is_array($value) && !in_array($castType, ['array', 'json'], true)) {
            return $value;
        }

        return match ($castType) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string' => is_array($value)
                ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                : (string) $value,
            'array', 'json' => is_string($value)
                ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
                : (array) $value,
            'datetime' => $value instanceof DateTimeInterface
                ? $value
                : new DateTime((string) $value),
            default => $value,
        };
    }

    // ==========================================
    // МАГИЧЕСКИЕ МЕТОДЫ
    // ==========================================

    /**
     * @throws DateMalformedStringException
     * @throws JsonException
     */
    public function __get(string $key): mixed
    {
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        if (array_key_exists($key, $this->attributes)) {
            return $this->castAttribute($key, $this->attributes[$key]);
        }

        if (method_exists($this, $key)) {
            return $this->getRelationValue($key);
        }

        return null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
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

    public function setRelation(string $name, mixed $value): self
    {
        $this->relations[$name] = $value;
        return $this;
    }

    public function __isset(string $key): bool
    {
        return array_key_exists($key, $this->attributes) || array_key_exists($key, $this->relations) || method_exists($this, $key);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }

    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    // ==========================================
    // ОПРЕДЕЛЕНИЕ СВЯЗЕЙ
    // ==========================================

    /**
     * @throws ReflectionException
     */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $foreignKey = $foreignKey ?: $this->getForeignKeyForRelated($related);
        $localKey = $localKey ?: $this->getKeyName();
        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /**
     * @throws ReflectionException
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $foreignKey = $foreignKey ?: $this->getForeignKeyForRelated($related);
        $localKey = $localKey ?: $this->getKeyName();
        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * @throws ReflectionException
     */
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $foreignKey = $foreignKey ?: $this->getForeignKeyForRelated($related);
        $ownerKey = $ownerKey ?: (new $related)->getKeyName();
        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    public function belongsToMany(
        string $related,
        ?string $pivotTable = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ): BelongsToMany {
        $relatedInstance = new $related;
        $pivotTable = $pivotTable ?: $this->getPivotTableName($relatedInstance);
        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();
        $relatedPivotKey = $relatedPivotKey ?: $relatedInstance->getForeignKey();
        return new BelongsToMany($this, $related, $pivotTable, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey);
    }

    /**
     * @throws ReflectionException
     */
    protected function getForeignKeyForRelated(string $related): string
    {
        $className = new ReflectionClass($related)->getShortName();
        return $this->snakeCase($className) . '_id';
    }

    protected function snakeCase(string $input): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }

    public function getKeyName(): string
    {
        return static::$primaryKey;
    }

    protected function getPivotTableName(self $related): string    {
        $tables = [static::getTableName(), $related->getTableName()];
        sort($tables);
        return strtolower(implode('_', $tables));
    }

    // ==========================================
    // ЛЕНИВАЯ ЗАГРУЗКА СВЯЗЕЙ
    // ==========================================

    /**
     * Ленивая загрузка связей (поддерживает вложенные: 'roles.permissions').
     */
    public function load(string|array $relations): static
    {
        $relations = is_string($relations) ? [$relations] : $relations;

        foreach ($relations as $relation) {
            $this->loadRelation(trim($relation));
        }

        return $this;
    }

    private function loadRelation(string $relation): void
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

            if ($related instanceof self) {
                $related->loadRelation($nested);
            } elseif (is_array($related)) {
                foreach ($related as $model) {
                    if ($model instanceof self) {
                        $model->loadRelation($nested);
                    }
                }
            }
        }
    }

    /**
     * Подготавливает атрибуты для записи в БД (применяет обратные casts).
     *
     * @throws JsonException
     */
    protected function getAttributesForSave(): array
    {
        $attributes = $this->attributes;
        foreach ($attributes as $key => $value) {
            $attributes[$key] = $this->serializeAttribute($key, $value);
        }
        return $attributes;
    }

    /**
     * Сериализует значение атрибута для записи в БД.
     *
     * @throws JsonException
     */
    protected function serializeAttribute(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key]) || $value === null) {
            return $value;
        }

        return match ($this->casts[$key]) {
            'array', 'json' => is_string($value)
                ? $value
                : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'datetime' => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : $value,
            'bool', 'boolean' => $value ? 1 : 0,
            default => $value,
        };
    }
}