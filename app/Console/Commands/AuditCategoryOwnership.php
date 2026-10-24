<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditCategoryOwnership extends Command
{
    protected $signature = 'categories:audit-ownership {--strict : Fail when legacy unowned categories need review}';

    protected $description = 'Report shared defaults, private categories, and legacy categories that need ownership review';

    public function handle(): int
    {
        if (! Schema::hasColumn('categories', 'is_default')) {
            $this->error('Category ownership columns are missing. Run the pending migrations first.');

            return self::FAILURE;
        }

        $defaults = DB::table('categories')
            ->whereNull('user_id')
            ->where('is_default', true)
            ->count();

        $private = DB::table('categories')
            ->whereNotNull('user_id')
            ->where('is_default', false)
            ->count();

        $legacy = DB::table('categories')
            ->whereNull('user_id')
            ->where('is_default', false)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        $this->info("Shared defaults: {$defaults}");
        $this->info("Private categories: {$private}");
        $this->info("Legacy unowned categories: {$legacy->count()}");

        if ($legacy->isEmpty()) {
            $this->info('No legacy categories need ownership review.');

            return self::SUCCESS;
        }

        $rows = $legacy->map(function (object $category): array {
            return [
                'id' => $category->id,
                'name' => $category->name,
                'type' => $category->type,
                'expenses' => DB::table('expenses')->where('category_id', $category->id)->count(),
                'budgets' => DB::table('budgets')->where('category_id', $category->id)->count(),
                'recurring_expenses' => DB::table('recurring_expenses')->where('category_id', $category->id)->count(),
                'subscriptions' => DB::table('subscriptions')->where('category_id', $category->id)->count(),
            ];
        })->all();

        $this->table(
            ['ID', 'Name', 'Type', 'Expenses', 'Budgets', 'Recurring', 'Subscriptions'],
            $rows
        );

        $this->warn(
            'Legacy rows are preserved and still resolve on historical records, but they are not '
            .'selectable or manageable. No owner was guessed. Review the rows above and map them '
            .'explicitly before relying on them for new transactions.'
        );

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
