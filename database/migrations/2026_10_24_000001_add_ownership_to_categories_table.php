<?php

use App\Support\DefaultCategories;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move categories from a single global namespace to:
     *   - shared defaults: user_id NULL, is_default true
     *   - private categories: user_id = owner, is_default false
     *
     * Existing categories are never deleted or reassigned. Rows whose
     * name/type match the canonical defaults are promoted to shared defaults;
     * every other pre-existing row is preserved as an unowned legacy row
     * (user_id NULL, is_default false). Legacy rows remain referenced by
     * history but are not selectable or manageable until an operator reviews
     * them with `php artisan categories:audit-ownership`.
     */
    public function up(): void
    {
        $defaultsByName = collect(DefaultCategories::all())->keyBy('name');
        $conflict = DB::table('categories')
            ->get(['name', 'type'])
            ->first(function (object $category) use ($defaultsByName): bool {
                $default = $defaultsByName->get($category->name);

                return $default !== null && $default['type'] !== $category->type;
            });

        if ($conflict !== null) {
            throw new RuntimeException(
                'Cannot add category ownership: the existing category "'.$conflict->name
                .'" is type "'.$conflict->type.'", but the shared default with that name '
                .'is type "'.$defaultsByName->get($conflict->name)['type'].'". '
                .'Rename or explicitly map that category before running this migration. '
                .'No schema or row changes were made.'
            );
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_default')
                ->default(false)
                ->after('type');

            // A nullable unique key gives every shared default a stable
            // identity on SQLite, PostgreSQL and MySQL without relying on a
            // database-specific partial index. Private categories leave it
            // NULL, and multiple NULLs are allowed.
            $table->string('default_key')
                ->nullable()
                ->unique()
                ->after('is_default');
        });

        foreach (DefaultCategories::all() as $category) {
            DB::table('categories')
                ->whereNull('user_id')
                ->where('name', $category['name'])
                ->where('type', $category['type'])
                ->update([
                    'is_default' => true,
                    'default_key' => DefaultCategories::key($category['type'], $category['name']),
                ]);

            $exists = DB::table('categories')
                ->whereNull('user_id')
                ->where('name', $category['name'])
                ->where('type', $category['type'])
                ->exists();

            if (! $exists) {
                DB::table('categories')->insert([
                    'name' => $category['name'],
                    'type' => $category['type'],
                    'user_id' => null,
                    'is_default' => true,
                    'default_key' => DefaultCategories::key($category['type'], $category['name']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(
                ['user_id', 'name', 'type'],
                'categories_user_name_type_unique'
            );
        });
    }

    /**
     * Rolling back collapses private categories back into the legacy global
     * namespace. That is only possible while all category names are unique,
     * so fail closed instead of silently renaming user data.
     */
    public function down(): void
    {
        // Dropping ownership while private rows exist would expose those
        // categories to every account under the old global schema.
        if (DB::table('categories')->whereNotNull('user_id')->exists()) {
            throw new RuntimeException(
                'Refusing to roll back category ownership: private categories still exist. '
                .'The old schema has no owner column, so rolling back would expose them to '
                .'every account. Explicitly resolve them first. No rows were changed.'
            );
        }

        $duplicateName = DB::table('categories')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicateName !== null) {
            throw new RuntimeException(
                'Refusing to roll back category ownership: more than one category '
                .'uses the name "'.$duplicateName->name.'". The old schema required '
                .'globally unique names, so rolling back would lose private-category '
                .'ownership or force a rename. No rows were changed.'
            );
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_user_name_type_unique');
            $table->dropUnique(['default_key']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'is_default', 'default_key']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->unique('name');
        });
    }
};
