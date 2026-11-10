<?php

declare(strict_types=1);

namespace CodeX\DataBase;

use Closure;
use CodeX\DataBase\Dialect\Factory;
use CodeX\DataBase\Model\Concerns\Auditable;
use CodeX\DataBase\Model\Concerns\HasCasts;
use CodeX\DataBase\Model\Concerns\HasEvents;
use CodeX\DataBase\Model\Concerns\HasRelations;
use CodeX\DataBase\Model\Query\Builder;
use CodeX\DataBase\Model\Relations\Relation;
use CodeX\Exception\DataBase;
use CodeX\Exception\Validation;
use CodeX\Validation\Validator;
use DateInvalidTimeZoneException;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use JsonSerializable;
use NoDiscard;
use PDO;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Базовый класс Active Record модели.
 */
abstract class Model implements JsonSerializable
{
    use Auditable;
    use HasCasts;
    use HasEvents;
    use HasRelations;

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
    protected static string $timezone = 'UTC';
    private static array $tableNameCache = [];
    private static array $bootedTraits = [];
    private static array $traitsCache = [];
    public bool $exists = false;
    protected bool $fireEvents = true;
    protected array $casts = [];
    protected array $hidden = [];
    protected bool $timestamps = true;
    protected array $attributes = [];
    /**
     * Загруженные связи модели.
     * Требуется трейтом HasRelations.
     *
     * @var array<string, mixed>
     */
    protected array $relations = [];
    protected array $validationErrors = [];
    private array $original = [];


    // ==========================================
    // СБРОС СОСТОЯНИЯ (long-running процессы)
    // ==========================================

    /**
     * Вызывает boot-методы трейтов один раз для каждого класса.
     * @throws ReflectionException
     */

    /**
     * @throws ReflectionException
     */
    public function __construct(array $attributes = [])
    {
        $this->bootIfNotBooted();
        $this->fill($attributes);
        $this->syncOriginal();
    }

    /**
     * @throws ReflectionException
     */
    protected function bootIfNotBooted(): void
    {
        $class = static::class;
        if (isset(self::$bootedTraits[$class])) {
            return;
        }
        self::$bootedTraits[$class] = true;

        $traits = self::$traitsCache[$class] ?? (self::$traitsCache[$class] = class_uses_recursive($class));

        foreach ($traits as $trait) {
            $shortName = new ReflectionClass($trait)->getShortName();
            $bootMethod = 'boot' . $shortName;
            if (method_exists($class, $bootMethod)) {
                $class::$bootMethod();
            }
        }
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

    // ==========================================
    // ЗАПОЛНЕНИЕ И ДОСТУП К АТРИБУТАМ
    // ==========================================

    protected function isFillable(string $key): bool
    {
        if (in_array($key, static::$guarded, true)) {
            return false;
        }

        if (static::$fillable === []) {
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

        $this->attributes[$key] = $value;

        return $this;
    }

    public function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    public static function flushState(): void
    {
        self::$tableNameCache = [];
        self::$connectionResolver = null;
        self::$bootedTraits = [];
        self::$traitsCache = [];
        static::$eventListeners = [];
    }

    public static function find(int|string $id): ?static
    {
        return static::query()->where(static::$primaryKey, '=', $id)->first();
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

        $class = static::class;

        if (isset(self::$tableNameCache[$class])) {
            return self::$tableNameCache[$class];
        }

        $shortName = new ReflectionClass(static::class)->getShortName();
        $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName)) . 's';

        return self::$tableNameCache[$class] = $tableName;
    }

    public static function setConnectionResolver(Closure $resolver): void
    {
        self::$connectionResolver = $resolver;
    }

    // ==========================================
    // СОХРАНЕНИЕ И УДАЛЕНИЕ
    // ==========================================

    /**
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
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

            $this->setAttribute(static::DELETED_AT, $this->getCurrentTimestamp());
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

    /**
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     */
    protected function getCurrentTimestamp(): string
    {
        $timezone = new DateTimeZone(static::$timezone);

        return new DateTimeImmutable('now', $timezone)->format('Y-m-d H:i:s');
    }

    /**
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
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

        $now = $this->getCurrentTimestamp();

        if ($this->timestamps) {
            if (!$this->exists && static::CREATED_AT !== '') {
                $this->setAttribute(static::CREATED_AT, $now);
            }

            if (static::UPDATED_AT !== '') {
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
        $this->validationErrors = [];

        $rules = $this->getValidationRules();
        if ($rules === []) {
            return true;
        }

        try {
            return new Validator()->validate($this);
        } catch (Validation $e) {
            $this->validationErrors = $e->getErrors();
            return false;
        }
    }

    public function getValidationRules(): array
    {
        return [];
    }

    /**
     * @throws JsonException
     */
    protected function performUpdate(): bool
    {
        $dirty = $this->getDirty();

        if ($dirty === []) {
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

    public function getDirty(): array
    {
        return array_filter($this->attributes, function ($value, $key): bool {
            if (!array_key_exists($key, $this->original)) {
                return true;
            }

            $original = $this->original[$key];

            // null и '' считаем эквивалентными для dirty-проверки
            if (($original === null && $value === '') || ($original === '' && $value === null)) {
                return false;
            }

            // Для скалярных значений — сравниваем нормализованно
            if (is_scalar($original) && is_scalar($value)) {
                return (string)$original !== (string)$value;
            }

            return $original !== $value;
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
            if (static::$incrementing && (!array_key_exists(static::$primaryKey, $this->attributes) || $this->attributes[static::$primaryKey] === null)) {
                $id = $pdo->lastInsertId();

                if ($id !== false) {
                    $this->attributes[static::$primaryKey] = static::$keyType === 'int' ? (int)$id : $id;
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
        $stmt = static::getConnection()->prepare($sql);
        $stmt->execute($bindings);
        $result = $stmt->rowCount() > 0;

        if ($result) {
            $this->exists = false;
            $this->fireEvent('deleted');
        }

        return $result;
    }

    /**
     * Возвращает ошибки валидации последней попытки save().
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    public function setRawAttributes(array $attributes): self
    {
        $this->attributes = $attributes;

        return $this;
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

        if ($this->hidden !== []) {
            $attributes = array_diff_key($attributes, array_flip($this->hidden));
        }

        foreach ($attributes as $key => $value) {
            $attributes[$key] = $this->castAttribute($key, $value);
        }

        foreach ($this->relations as $key => $value) {
            if (in_array($key, $this->hidden, true)) {
                continue;
            }
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

    public function __get(string $key): mixed
    {
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        if (array_key_exists($key, $this->attributes)) {
            return $this->castAttribute($key, $this->attributes[$key]);
        }

        $getter = 'get' . ucfirst($key);
        if (method_exists($this, $getter)) {
            return $this->$getter();
        }

        $getterAttribute = 'get' . ucfirst($key) . 'Attribute';
        if (method_exists($this, $getterAttribute)) {
            return $this->$getterAttribute();
        }

        if (method_exists($this, $key)) {
            $method = new ReflectionMethod($this, $key);
            $returnType = $method->getReturnType();

            if ($returnType instanceof ReflectionNamedType && !$returnType->isBuiltin() && is_a($returnType->getName(), Relation::class, true)) {
                return $this->getRelationValue($key);
            }
        }

        return null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return array_key_exists($key, $this->attributes) || array_key_exists($key, $this->relations);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }

    public function getKeyName(): string
    {
        return static::$primaryKey;
    }

    public function getOriginal(?string $key = null): mixed
    {
        if ($key !== null) {
            return $this->original[$key] ?? null;
        }

        return $this->original;
    }

    /**
     * Возвращает все атрибуты модели.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }
}