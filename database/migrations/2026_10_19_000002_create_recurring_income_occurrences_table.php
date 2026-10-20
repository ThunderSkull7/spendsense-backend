<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_income_occurrences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('recurring_income_id')
                ->constrained('recurring_incomes')
                ->cascadeOnDelete();

            $table->date('occurrence_date');
            $table->decimal('expected_amount', 10, 2);
            $table->string('status', 20)->default('planned');

            // One actual income row can back at most one occurrence. The FK is
            // restrictive so an actual income cannot be deleted out from under
            // the receipt history; the Income controller returns a clear 422.
            $table->foreignId('income_id')
                ->nullable()
                ->unique()
                ->constrained('incomes')
                ->restrictOnDelete();

            $table->timestamps();

            // Database-level duplicate prevention for concurrent generators.
            $table->unique(
                ['recurring_income_id', 'occurrence_date'],
                'recurring_occurrence_schedule_date_unique'
            );
            $table->index(['status', 'occurrence_date'], 'recurring_occurrence_status_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_income_occurrences');
    }
};
