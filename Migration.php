<?php

declare(strict_types=1);

namespace CodeX\DataBase;

use CodeX\DataBase\Connection\Manager;
use LogicException;

/**
 * Базовый абстрактный класс для миграций базы данных.
 *
 * Менеджер подключений внедряется через setManager()
 * автоматически вызывающим кодом (Migrator).
 */
abstract class Migration
{
    /**
     * Менеджер подключений к БД.
     * Устанавливается вызывающим кодом перед вызовом up()/down().
     */
    protected ?Manager $manager = null;

    abstract public function up(): void;

    abstract public function down(): void;

    final public function getManager(): Manager
    {
        return $this->manager ?? throw new LogicException('Manager не установлен. Вызывайте через Migrator.');
    }

    /**
     * Внедряет менеджер подключений.
     * Вызывается автоматически в Migrator::run() и Migrator::rollback().
     */
    final public function setManager(Manager $manager): void
    {
        $this->manager = $manager;
    }
}