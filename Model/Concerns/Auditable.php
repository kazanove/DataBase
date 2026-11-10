<?php

declare(strict_types=1);

namespace CodeX\DataBase\Model\Concerns;

use CodeX\DataBase\Model;

/**
 * Трейт для моделей, поддерживающих аудит изменений.
 *
 * Предоставляет методы для получения разницы между
 * старыми и новыми значениями атрибутов.
 *
 * Трейт рассчитан на использование только совместно с {@see Model},
 * который реализует методы getOriginal() и getDirty().
 *
 * @phpstan-require-extends Model
 * @psalm-require-extends Model
 */
trait Auditable
{
    /**
     * Поля, которые не нужно отслеживать в аудите.
     *
     * @var array<int, string>
     */
    protected array $auditExcept = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Возвращает разницу изменений для записи в аудит.
     *
     * Методы getOriginal() и getDirty() предоставляются базовым классом {@see Model}.
     *
     * @return array{
     *     old: array<string, mixed>,
     *     new: array<string, mixed>
     * }
     */
    public function getAuditDiff(): array
    {
        /** @var array<string, mixed> $oldValues */
        $oldValues = $this->getOriginal();
        /** @var array<string, mixed> $dirty */
        $dirty = $this->getDirty();

        $exceptFlipped = array_flip($this->auditExcept);

        $filteredOld = array_diff_key($oldValues, $exceptFlipped);
        $filteredNew = array_diff_key($dirty, $exceptFlipped);

        // Берём только те старые значения, которые реально изменились
        $changedOld = array_intersect_key($filteredOld, $filteredNew);

        return [
            'old' => $changedOld,
            'new' => $filteredNew,
        ];
    }

    /**
     * Возвращает только изменённые старые значения без фильтрации по auditExcept.
     *
     * @return array<string, mixed>
     */
    public function getChangedOriginal(): array
    {
        /** @var array<string, mixed> $original */
        $original = $this->getOriginal();
        /** @var array<string, mixed> $dirty */
        $dirty = $this->getDirty();

        return array_intersect_key($original, $dirty);
    }

    /**
     * Устанавливает список полей, исключаемых из аудита.
     *
     * @param array<int, string> $fields
     */
    public function setAuditExcept(array $fields): static
    {
        $this->auditExcept = $fields;

        return $this;
    }

    /**
     * Добавляет поле в список исключений аудита.
     */
    public function addAuditExcept(string $field): static
    {
        if (!in_array($field, $this->auditExcept, true)) {
            $this->auditExcept[] = $field;
        }

        return $this;
    }

    /**
     * Удаляет поле из списка исключений аудита.
     */
    public function removeAuditExcept(string $field): static
    {
        $this->auditExcept = array_values(
            array_filter(
                $this->auditExcept,
                static fn(string $f): bool => $f !== $field
            )
        );

        return $this;
    }
}