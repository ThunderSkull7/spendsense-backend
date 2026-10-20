<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_goals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('target_amount', 12, 2);
            $table->date('target_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'target_date']);
        });

        Schema::create('savings_goal_contributions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('savings_goal_id')
                ->constrained('savings_goals')
                ->cascadeOnDelete();

            // Contributions and corrections are positive ledger entries. A
            // correction subtracts money that was previously over-recorded.
            $table->string('type', 20)->default('contribution');
            $table->decimal('amount', 12, 2);
            $table->string('note', 500)->nullable();
            $table->date('contributed_at');
            $table->timestamps();

            $table->index(['savings_goal_id', 'contributed_at']);
            $table->index(['savings_goal_id', 'type']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE savings_goals ADD CONSTRAINT savings_goals_target_positive CHECK (target_amount > 0)'
            );
            DB::statement(
                "ALTER TABLE savings_goals ADD CONSTRAINT savings_goals_status_valid CHECK (status IN ('active', 'completed', 'archived'))"
            );
            DB::statement(
                'ALTER TABLE savings_goal_contributions ADD CONSTRAINT savings_goal_contributions_amount_positive CHECK (amount > 0)'
            );
            DB::statement(
                "ALTER TABLE savings_goal_contributions ADD CONSTRAINT savings_goal_contributions_type_valid CHECK (type IN ('contribution', 'correction'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::table('savings_goals')->exists()
            || DB::table('savings_goal_contributions')->exists()) {
            throw new RuntimeException(
                'Refusing to drop savings goal tables while goals or contribution history exist. '
                .'Archive/export the goals and remove them explicitly before rolling back.'
            );
        }

        Schema::dropIfExists('savings_goal_contributions');
        Schema::dropIfExists('savings_goals');
    }
};
