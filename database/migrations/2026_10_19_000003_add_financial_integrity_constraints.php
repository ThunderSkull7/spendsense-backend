<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PostgreSQL-only checks. SQLite is used by the automated suite and does
     * not support ALTER TABLE ... ADD CONSTRAINT; its equivalent guarantees
     * are covered by Form Request validation and feature tests.
     *
     * The existing income/expense checks use NOT VALID so a legacy row with a
     * zero or negative amount cannot make the migration fail. PostgreSQL still
     * enforces the checks for every new insert and update.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE incomes ADD CONSTRAINT incomes_amount_positive CHECK (amount > 0) NOT VALID'
        );
        DB::statement(
            'ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive CHECK (amount > 0) NOT VALID'
        );

        // New tables are empty, so these checks can validate immediately.
        DB::statement(
            'ALTER TABLE recurring_incomes ADD CONSTRAINT recurring_incomes_amount_positive CHECK (amount > 0)'
        );
        DB::statement(
            'ALTER TABLE recurring_incomes ADD CONSTRAINT recurring_incomes_date_order CHECK (end_date >= start_date)'
        );
        DB::statement(
            "ALTER TABLE recurring_incomes ADD CONSTRAINT recurring_incomes_frequency_check CHECK (frequency IN ('daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'half-yearly', 'yearly'))"
        );
        DB::statement(
            "ALTER TABLE recurring_incomes ADD CONSTRAINT recurring_incomes_status_check CHECK (status IN ('active', 'paused', 'stopped'))"
        );
        DB::statement(
            'ALTER TABLE recurring_income_occurrences ADD CONSTRAINT recurring_occurrences_amount_positive CHECK (expected_amount > 0)'
        );
        DB::statement(
            "ALTER TABLE recurring_income_occurrences ADD CONSTRAINT recurring_occurrences_status_check CHECK (status IN ('planned', 'received', 'cancelled'))"
        );
        DB::statement(
            "ALTER TABLE recurring_income_occurrences ADD CONSTRAINT recurring_occurrences_receipt_link CHECK ((status = 'received') = (income_id IS NOT NULL))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE incomes DROP CONSTRAINT IF EXISTS incomes_amount_positive');
        DB::statement('ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_amount_positive');
        DB::statement('ALTER TABLE recurring_incomes DROP CONSTRAINT IF EXISTS recurring_incomes_amount_positive');
        DB::statement('ALTER TABLE recurring_incomes DROP CONSTRAINT IF EXISTS recurring_incomes_date_order');
        DB::statement('ALTER TABLE recurring_incomes DROP CONSTRAINT IF EXISTS recurring_incomes_frequency_check');
        DB::statement('ALTER TABLE recurring_incomes DROP CONSTRAINT IF EXISTS recurring_incomes_status_check');
        DB::statement('ALTER TABLE recurring_income_occurrences DROP CONSTRAINT IF EXISTS recurring_occurrences_amount_positive');
        DB::statement('ALTER TABLE recurring_income_occurrences DROP CONSTRAINT IF EXISTS recurring_occurrences_status_check');
        DB::statement('ALTER TABLE recurring_income_occurrences DROP CONSTRAINT IF EXISTS recurring_occurrences_receipt_link');
    }
};
