<?php

namespace App\Console\Commands;

use App\Support\DefaultCategories;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditCategoryPreflight extends Command
{
    protected $signature = 'categories:audit-preflight {--allow-legacy : Acknowledge pre-existing non-default categories as historical/read-only}';

    protected $description = 'Read-only preflight for category ownership, intended to run before the ownership migration';

    public function handle(): int
    {
        if (! Schema::hasTable('categories')) {
            $this->info('Categories table does not exist yet; there is nothing to classify.');

            return self::SUCCESS;
        }

        $hasUserId = Schema::hasColumn('categories', 'user_id');
        $hasIsDefault = Schema::hasColumn('categories', 'is_default');

        // A later production migration run must not be blocked once ownership
        // is already applied. The post-migration audit handles inspection then.
        if ($hasUserId && $hasIsDefault) {
            $this->info('Category ownership is already applied; skipping preflight. Use categories:audit-ownership for inspection.');

            return self::SUCCESS;
        }

        if ($hasUserId || $hasIsDefault) {
            $this->error('Category ownership columns are incomplete. Inspect and repair the schema before migrating. No changes were made.');

            return self::FAILURE;
        }

        if (! Schema::hasColumn('categories', 'name') || ! Schema::hasColumn('categories', 'type')) {
            $this->error('The categories table does not have the expected name/type columns.');

            return self::FAILURE;
        }

        $defaults = collect(DefaultCategories::all())->keyBy('name');
        $categories = DB::table('categories')
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        $conflicts = $categories->filter(
            fn (object $category): bool => $defaults->has($category->name)
                && $defaults->get($category->name)['type'] !== $category->type
        )->values();

        $legacy = $categories->filter(
            fn (object $category): bool => ! $defaults->has($category->name)
        )->values();

        $missingDefaults = $defaults->filter(
            fn (array $default): bool => ! $categories->contains(
                fn (object $category): bool => $category->name === $default['name']
                    && $category->type === $default['type']
            )
        )->count();

        $this->info("Existing category rows: {$categories->count()}");
        $this->info("Canonical default rows already present: ".($categories->count() - $conflicts->count() - $legacy->count()));
        $this->info("Missing default rows that will be added: {$missingDefaults}");
        $this->info("Conflicting default names: {$conflicts->count()}");
        $this->info("Legacy non-default rows: {$legacy->count()}");

        if ($conflicts->isNotEmpty()) {
            $this->table(
                ['ID', 'Name', 'Existing type', 'Expected default type'],
                $conflicts->map(fn (object $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'type' => $category->type,
                    'expected' => $defaults->get($category->name)['type'],
                ])->all()
            );

            $this->error(
                'A category name matches a shared default but has a different type. '
                .'Rename or explicitly map it before running the ownership migration. No changes were made.'
            );

            return self::FAILURE;
        }

        if ($legacy->isNotEmpty()) {
            $this->table(
                ['ID', 'Name', 'Type'],
                $legacy->map(fn (object $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'type' => $category->type,
                ])->all()
            );

            if (! $this->option('allow-legacy')) {
                $this->error(
                    'Legacy non-default categories were found. Review them and re-run with '
                    .'--allow-legacy only if they should be preserved as unowned, historical/read-only rows. '
                    .'No changes were made.'
                );

                return self::FAILURE;
            }

            $this->warn(
                'Legacy rows were explicitly acknowledged. They will be preserved with no guessed owner '
                .'and hidden from new category selection after the migration.'
            );
        }

        $this->info('Category ownership preflight passed. No changes were made.');

        return self::SUCCESS;
    }
}
