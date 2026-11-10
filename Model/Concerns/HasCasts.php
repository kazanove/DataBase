<?php

declare(strict_types=1);

namespace CodeX\DataBase\Model\Concerns;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;

trait HasCasts
{
    /**
     * @throws DateMalformedStringException
     * @throws JsonException
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key]) || $value === null) {
            return $value;
        }

        return match ($this->casts[$key]) {
            'int', 'integer' => (int)$value,
            'float', 'double' => (float)$value,
            'bool', 'boolean' => (bool)$value,
            'string' => is_array($value) ? throw new InvalidArgumentException('Атрибут ['.$key.'] имеет каст \'string\', но содержит массив. Используйте каст \'array\' или \'json\'.') : (string)$value,
            'array', 'json' => is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : (array)$value,
            'datetime' => $value instanceof DateTimeInterface ? $value : new DateTimeImmutable((string)$value),
            default => $value,
        };
    }

    /**
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
     * @throws JsonException
     */
    protected function serializeAttribute(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key]) || $value === null) {
            return $value;
        }

        return match ($this->casts[$key]) {
            'array', 'json' => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'datetime' => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
            'bool', 'boolean' => $value ? 1 : 0,
            default => $value,
        };
    }
}