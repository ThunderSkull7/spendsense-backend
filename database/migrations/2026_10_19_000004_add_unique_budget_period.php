<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One budget per user, category and calendar month. The month is stored as
     * a date, so the uniqueness rule must compare whole months rather than the
     * exact day. A functional unique index enforces that rule at the database
     * level even if application code is bypassed.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        $monthExpression = $this->monthExpression($driver);

        if ($monthExpression === null) {
            throw new RuntimeException(
                'Cannot add the budget uniqueness constraint: unsupported database driver '.$driver.'.'
            );
        }

        // Refuse to guess at duplicate legacy rows rather than deleting or
        // rewriting financial planning data silently.
        $duplicates = DB::table('budgets')
            ->select('user_id', 'category_id')
            ->selectRaw($monthExpression.' as budget_month')
            ->groupBy('user_id', 'category_id')
            ->groupByRaw($monthExpression)
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'Cannot add the budget uniqueness constraint: duplicate budgets exist for at least one user/category/month. '
                .'Group budgets by user, category and calendar month, then merge or delete the duplicates manually and rerun the migration. '
                .'No rows were changed.'
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX budgets_user_category_month_unique '
            ."ON budgets (user_id, category_id, {$monthExpression})"
        );
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        // The index was created with a raw expression, not a Laravel unique
        // constraint, so PostgreSQL stores it as an index and rejects
        // ALTER TABLE ... DROP CONSTRAINT. Drop the index by name instead.
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS budgets_user_category_month_unique');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('DROP INDEX budgets_user_category_month_unique ON budgets');

            return;
        }

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique('budgets_user_category_month_unique');
        });
    }

    /**
     * An expression that maps a DATE to the first day of its month. Each
     * expression is immutable/stable enough for a unique index on the
     * supported local and production drivers.
     */
    private function monthExpression(string $driver): ?string
    {
        return match ($driver) {
            'pgsql' => '((month_year - (EXTRACT(DAY FROM month_year)::integer - 1)))',
            'sqlite' => "(strftime('%Y-%m', month_year))",
            'mysql', 'mariadb' => "(DATE_FORMAT(month_year, '%Y-%m'))",
        };
    }
};
