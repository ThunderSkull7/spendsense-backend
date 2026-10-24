<?php

namespace App\Support;

/**
 * Canonical shared categories. Keep this list in one place so the migration
 * and the idempotent seeder cannot drift apart.
 */
final class DefaultCategories
{
    /**
     * @return array<int, array{name: string, type: string}>
     */
    public static function all(): array
    {
        return [
            ['name' => 'Food', 'type' => 'expense'],
            ['name' => 'Transport', 'type' => 'expense'],
            ['name' => 'Shopping', 'type' => 'expense'],
            ['name' => 'Entertainment', 'type' => 'expense'],
            ['name' => 'Bills', 'type' => 'expense'],
            ['name' => 'Salary', 'type' => 'income'],
            ['name' => 'Freelance', 'type' => 'income'],
        ];
    }

    /**
     * Stable database key used to make default categories unique without
     * relying on database-specific partial indexes.
     */
    public static function key(string $type, string $name): string
    {
        return $type.':'.mb_strtolower(trim($name));
    }
}
