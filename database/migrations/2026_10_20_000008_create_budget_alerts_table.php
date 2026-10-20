<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_alerts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('budget_id')
                ->constrained('budgets')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('threshold');
            $table->date('period_month');
            $table->decimal('spent_amount', 12, 2);
            $table->decimal('limit_amount', 12, 2);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // One alert per budget, threshold and calendar month. Repeated
            // reads and concurrent reconciliations cannot create duplicates.
            $table->unique(
                ['budget_id', 'threshold', 'period_month'],
                'budget_alert_budget_threshold_period_unique'
            );
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'period_month']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE budget_alerts ADD CONSTRAINT budget_alerts_threshold_range CHECK (threshold BETWEEN 1 AND 100)'
            );
            DB::statement(
                'ALTER TABLE budget_alerts ADD CONSTRAINT budget_alerts_spent_nonnegative CHECK (spent_amount >= 0)'
            );
            DB::statement(
                'ALTER TABLE budget_alerts ADD CONSTRAINT budget_alerts_limit_positive CHECK (limit_amount > 0)'
            );
        }
    }

    public function down(): void
    {
        // Alerts are derived from budgets and expenses and can be rebuilt.
        Schema::dropIfExists('budget_alerts');
    }
};
