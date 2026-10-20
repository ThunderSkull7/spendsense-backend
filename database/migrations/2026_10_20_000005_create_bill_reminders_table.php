<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_reminders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('recurring_expense_occurrence_id')
                ->constrained('recurring_expense_occurrences')
                ->cascadeOnDelete();

            $table->string('type', 20);
            $table->unsignedTinyInteger('window_days')->default(0);
            $table->date('due_date');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The unique key is the duplicate-suppression boundary. Including
            // the due date means a deliberately changed occurrence date can
            // produce a fresh reminder instead of being blocked by an old one.
            $table->unique(
                ['recurring_expense_occurrence_id', 'type', 'window_days', 'due_date'],
                'bill_reminder_occurrence_window_unique'
            );
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'due_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE bill_reminders ADD CONSTRAINT bill_reminders_type_valid CHECK (type IN ('upcoming', 'due', 'overdue'))"
            );
            DB::statement(
                'ALTER TABLE bill_reminders ADD CONSTRAINT bill_reminders_window_days_range CHECK (window_days BETWEEN 0 AND 30)'
            );
        }
    }

    public function down(): void
    {
        // Rollback runs this migration before the schedule table migration.
        // Refuse before dropping anything when schedule history exists, rather
        // than leaving a partially rolled-back schema behind.
        if (Schema::hasTable('recurring_expenses') && DB::table('recurring_expenses')->exists()) {
            throw new RuntimeException(
                'Refusing to drop bill reminders while recurring expense schedules exist. Archive and remove schedule data explicitly first.'
            );
        }

        if (Schema::hasTable('bill_reminders') && DB::table('bill_reminders')->exists()) {
            throw new RuntimeException(
                'Refusing to drop bill reminders while reminder history exists. Archive and remove reminder data explicitly first.'
            );
        }

        Schema::dropIfExists('bill_reminders');
    }
};
