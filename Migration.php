<?php

declare(strict_types=1);

namespace CodeX\DataBase;

/**
 * Базовый абстрактный класс для миграций базы данных.
 */
abstract class Migration
{
    abstract public function up(): void;

    abstract public function down(): void;
}