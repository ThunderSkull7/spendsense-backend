<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('categories')->insert([
            
            // Expense Categories
            [
                'name' => 'Food',
                'type' => 'expense',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Transport',
                'type' => 'expense',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Shopping',
                'type' => 'expense',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Entertainment',
                'type' => 'expense',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Bills',
                'type' => 'expense',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Income Categories
            [
                'name' => 'Salary',
                'type' => 'income',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Freelance',
                'type' => 'income',
                'created_at' => now(),
                'updated_at' => now(),
            ],

        ]);
    }
}