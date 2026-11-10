<?php
declare(strict_types=1);

namespace CodeX\DataBase\Seeding;

use CodeX\DataBase\Dialect\Factory;
use CodeX\DataBase\Model;
use CodeX\DataBase\Query\Builder;
use RuntimeException;

/**
 * Базовый абстрактный класс для сидеров базы данных.
 */
abstract class Seeder
{
    /**
     * Вызывает другой сидер.
     *
     * @param class-string<Seeder> $seederClass
     */
    protected function call(string $seederClass): void
    {
        if ($seederClass === '') {
            throw new RuntimeException('Не удалось вызвать сидер: имя класса пусто.');
        }

        if (!class_exists($seederClass)) {
            throw new RuntimeException('Класс сидера не найден: ' . $seederClass);
        }

        $seeder = new $seederClass();

        if (!$seeder instanceof self) {
            throw new RuntimeException('Класс ' . $seederClass . ' не является наследником Seeder.');
        }

        $seeder->run();
    }

    /**
     * Заполнение базы данных.
     */
    abstract public function run(): void;

    /**
     * Возвращает построитель запросов для указанной таблицы.
     * Используйте для where/update/delete.
     */
    protected function table(string $table): Builder
    {
        $pdo = Model::getConnection();
        $dialect = Factory::createFromPdo($pdo);

        return new Builder($dialect, $pdo)->from($table);
    }

    protected function insert(string $table, array $records): void
    {
        if ($records === []) {
            return;
        }

        // Валидация консистентности ключей
        $columns = array_keys($records[0]);
        foreach ($records as $i => $row) {
            if (array_keys($row) !== $columns) {
                throw new RuntimeException('Ключи элемента ' . $i . ' не совпадают с первым элементом.');
            }
        }

        $pdo = Model::getConnection();
        $dialect = Factory::createFromPdo($pdo);
        $prefix = $dialect->getPlaceholderPrefix();

        $placeholders = [];
        $bindings = [];
        $counter = 0;

        foreach ($records as $row) {
            $rowPlaceholders = [];
            foreach ($columns as $col) {
                $ph = $prefix . 'ins_' . $counter++;
                $rowPlaceholders[] = $ph;
                $value = $row[$col] ?? null;
                if (is_bool($value)) {
                    $value = $value ? 1 : 0;
                }
                $bindings[$ph] = $value;
            }
            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $sql = 'INSERT INTO ' . $dialect->quoteIdentifier($table) . ' (' . implode(', ', array_map([$dialect, 'quoteIdentifier'], $columns)) . ')' . ' VALUES ' . implode(', ', $placeholders);

        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            $error = $pdo->errorInfo();
            throw new RuntimeException('Ошибка подготовки запроса: ' . ($error[2] ?? 'неизвестная ошибка') . PHP_EOL . 'SQL: ' . $sql);
        }

        $success = $stmt->execute($bindings);
        if (!$success) {
            $error = $stmt->errorInfo();
            throw new RuntimeException('Ошибка выполнения запроса: ' . ($error[2] ?? 'неизвестная ошибка') . PHP_EOL . 'SQL: ' . $sql);
        }

        $stmt->closeCursor();
        unset($stmt);
    }
}