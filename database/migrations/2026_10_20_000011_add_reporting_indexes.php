<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite indexes support Stage 4 date-range aggregates while the
     * existing deleted_at column keeps soft-deleted rows out of active reports.
     * Adding indexes does not rewrite financial records.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->index(
                ['user_id', 'expense_date', 'deleted_at'],
                'expenses_user_date_active_index'
            );
            $table->index(
                ['user_id', 'category_id', 'expense_date', 'deleted_at'],
                'expenses_user_category_date_active_index'
            );
        });

        Schema::table('incomes', function (Blueprint $table) {
            $table->index(
                ['user_id', 'income_date', 'deleted_at'],
                'incomes_user_date_active_index'
            );
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->index(['user_id', 'month_year'], 'budgets_user_month_index');
        });
    }

    public function down(): void
    {
        // Roll back this migration before the Stage 4 tables when they still
        // hold data. Without this early guard the reporting indexes would be
        // dropped and a later migration would then refuse, leaving a partial
        // rollback on databases that do not run DDL in a transaction.
        $hasStageFourData = (Schema::hasTable('net_worth_entries') && DB::table('net_worth_entries')->exists())
            || (Schema::hasTable('subscriptions') && DB::table('subscriptions')->exists());

        if ($hasStageFourData) {
            throw new RuntimeException(
                'Refusing to roll back Stage 4 reporting indexes while net worth or subscription data exists. '
                .'Export or explicitly remove that data first.'
            );
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_user_date_active_index');
            $table->dropIndex('expenses_user_category_date_active_index');
        });

        Schema::table('incomes', function (Blueprint $table) {
            $table->dropIndex('incomes_user_date_active_index');
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropIndex('budgets_user_month_index');
        });
    }
};
