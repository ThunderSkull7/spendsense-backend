<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('description');
            $table->decimal('amount', 10, 2);

            // A category is restricted while a schedule references it. Paid
            // expenses keep their own historical category_id and are not
            // changed when a schedule is edited.
            $table->foreignId('category_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('frequency', 20);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedTinyInteger('reminder_days_before')->default(3);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'start_date']);
            $table->index('category_id');
        });

        Schema::create('recurring_expense_occurrences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('recurring_expense_id')
                ->constrained('recurring_expenses')
                ->cascadeOnDelete();

            $table->date('occurrence_date');
            $table->decimal('expected_amount', 10, 2);
            $table->string('status', 20)->default('planned');

            // One actual expense can back at most one occurrence. The FK is
            // restrictive so a hard delete cannot remove an actual expense
            // that is still part of payment history. Soft deletes remain the
            // normal user-facing deletion path and preserve this link.
            $table->foreignId('expense_id')
                ->nullable()
                ->unique()
                ->constrained('expenses')
                ->restrictOnDelete();

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['recurring_expense_id', 'occurrence_date'],
                'recurring_expense_occurrence_unique'
            );
            $table->index(['status', 'occurrence_date'], 'recurring_expense_occurrence_status_date_index');
            $table->index(
                ['recurring_expense_id', 'status'],
                'recurring_expense_occurrence_schedule_status_index'
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_amount_positive CHECK (amount > 0)'
            );
            DB::statement(
                'ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_reminder_days_range CHECK (reminder_days_before BETWEEN 0 AND 30)'
            );
            DB::statement(
                "ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_status_valid CHECK (status IN ('active', 'paused', 'stopped'))"
            );
            DB::statement(
                "ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_frequency_valid CHECK (frequency IN ('daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'half-yearly', 'yearly'))"
            );

            DB::statement(
                'ALTER TABLE recurring_expense_occurrences ADD CONSTRAINT recurring_expense_occurrence_amount_positive CHECK (expected_amount > 0)'
            );
            DB::statement(
                "ALTER TABLE recurring_expense_occurrences ADD CONSTRAINT recurring_expense_occurrence_status_valid CHECK (status IN ('planned', 'paid', 'skipped', 'cancelled'))"
            );
            DB::statement(
                "ALTER TABLE recurring_expense_occurrences ADD CONSTRAINT recurring_expense_occurrence_payment_link CHECK ((status = 'paid') = (expense_id IS NOT NULL))"
            );
            DB::statement(
                "ALTER TABLE recurring_expense_occurrences ADD CONSTRAINT recurring_expense_occurrence_paid_at CHECK (status <> 'paid' OR paid_at IS NOT NULL)"
            );
        }
    }

    public function down(): void
    {
        // Schedule history is user data. Refuse to drop it automatically once
        // any schedule exists; an operator must review/archive it explicitly.
        if (Schema::hasTable('recurring_expenses') && DB::table('recurring_expenses')->exists()) {
            throw new RuntimeException(
                'Refusing to drop recurring expense schedules while schedule data exists. Archive and remove schedule data explicitly first.'
            );
        }

        Schema::dropIfExists('recurring_expense_occurrences');
        Schema::dropIfExists('recurring_expenses');
    }
};
