<?php

declare(strict_types=1);

namespace CodeX\Contract\DataBase;

/**
 * Контракт SQL-диалекта.
 *
 * Позволяет абстрагировать различия между СУБД (MySQL, PostgreSQL, SQLite и др.)
 * при построении SQL-запросов.
 */
interface Sql
{
    public function buildLimitOffset(?int $limit, ?int $offset): string;

    public function buildLock(bool $forUpdate, bool $forShare): string;

    public function quoteIdentifier(string $identifier): string;

    public function getPlaceholderName(string $baseName, int $counter): string;

    public function getPlaceholderPrefix(): string;

    public function supportsReturning(): bool;

    public function buildReturning(string $table, array $columns): string;

    public function quoteString(string $value): string;

    /**
     * Возвращает тип для автоинкрементной колонки.
     *
     * @param string $baseType Исходный тип колонки (INT, BIGINT).
     */
    public function getAutoIncrementType(string $baseType): string;

    /**
     * Поддерживает ли СУБД модификатор UNSIGNED.
     */
    public function supportsUnsigned(): bool;

    /**
     * Возвращает SQL для удаления внешнего ключа по имени.
     */
    public function dropForeignKey(string $name): string;
    public function buildDecimalType(int $precision, int $scale): string;
    public function getAutoIncrementKeyword(): string;
}