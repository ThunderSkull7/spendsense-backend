<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('provider', 120)->nullable();

            // A linked subscription is a view over the existing recurring
            // expense schedule. The unique column prevents two trackers from
            // counting the same schedule.
            $table->foreignId('recurring_expense_id')
                ->nullable()
                ->unique()
                ->constrained('recurring_expenses')
                ->restrictOnDelete();

            // Standalone tracker fields. Linked rows leave these null and use
            // the schedule as the single source of amount/frequency/category.
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('frequency', 20)->nullable();
            $table->date('next_renewal_date')->nullable();
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->restrictOnDelete();

            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'next_renewal_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_valid CHECK (status IN ('active', 'paused', 'cancelled'))"
            );
            DB::statement(
                "ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_frequency_valid CHECK (frequency IS NULL OR frequency IN ('daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'half-yearly', 'yearly'))"
            );
            DB::statement(
                'ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_amount_positive CHECK (amount IS NULL OR amount > 0)'
            );
            DB::statement(
                'ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_tracking_shape CHECK ((recurring_expense_id IS NOT NULL AND amount IS NULL AND frequency IS NULL AND next_renewal_date IS NULL AND category_id IS NULL) OR (recurring_expense_id IS NULL AND amount IS NOT NULL AND frequency IS NOT NULL AND next_renewal_date IS NOT NULL))'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscriptions') && DB::table('subscriptions')->exists()) {
            throw new RuntimeException(
                'Refusing to drop subscriptions while tracker data exists. '
                .'Archive/export the tracker rows and remove them explicitly first.'
            );
        }

        Schema::dropIfExists('subscriptions');
    }
};
