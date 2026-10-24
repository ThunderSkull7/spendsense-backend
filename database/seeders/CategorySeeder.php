<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Support\DefaultCategories;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Seed the shared default categories. This is safe to run repeatedly:
     * name/type/user_id identifies the existing shared row, while default_key
     * provides database-level protection against duplicate defaults.
     */
    public function run(): void
    {
        foreach (DefaultCategories::all() as $category) {
            Category::query()->updateOrCreate(
                [
                    'name' => $category['name'],
                    'type' => $category['type'],
                    'user_id' => null,
                ],
                [
                    'is_default' => true,
                    'default_key' => DefaultCategories::key($category['type'], $category['name']),
                ]
            );
        }
    }
}
