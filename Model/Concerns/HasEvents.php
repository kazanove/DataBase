<?php

declare(strict_types=1);

namespace CodeX\DataBase\Model\Concerns;

use Closure;

trait HasEvents
{
    /**
     * @var array<class-string, array<string, array<int, Closure>>>
     */
    protected static array $eventListeners = [];

    public static function creating(Closure $callback): void
    {
        static::listen('creating', $callback);
    }

    public static function created(Closure $callback): void
    {
        static::listen('created', $callback);
    }

    public static function updating(Closure $callback): void
    {
        static::listen('updating', $callback);
    }

    public static function updated(Closure $callback): void
    {
        static::listen('updated', $callback);
    }

    public static function saving(Closure $callback): void
    {
        static::listen('saving', $callback);
    }

    public static function saved(Closure $callback): void
    {
        static::listen('saved', $callback);
    }

    public static function deleting(Closure $callback): void
    {
        static::listen('deleting', $callback);
    }

    public static function deleted(Closure $callback): void
    {
        static::listen('deleted', $callback);
    }

    public static function softDeleting(Closure $callback): void
    {
        static::listen('softDeleting', $callback);
    }

    public static function softDeleted(Closure $callback): void
    {
        static::listen('softDeleted', $callback);
    }

    public static function listen(string $event, Closure $listener): void
    {
        static::$eventListeners[static::class][$event][] = $listener;
    }

    public static function flushEventListeners(): void
    {
        unset(static::$eventListeners[static::class]);
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

        return array_all(
            $listeners,
            fn(Closure $listener): bool => $listener($this) !== false
        );
    }
}