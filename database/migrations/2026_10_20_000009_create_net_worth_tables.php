<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('net_worth_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name', 120);

            // "asset" and "liability" decide the sign in the net worth total.
            // The category is a user-facing classification, not an account.
            $table->string('type', 20);
            $table->string('category', 30);
            $table->string('institution', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'type']);
        });

        Schema::create('net_worth_valuations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('net_worth_entry_id')
                ->constrained('net_worth_entries')
                ->cascadeOnDelete();

            // Liabilities are entered as positive amounts owed; the type on the
            // entry decides subtraction. A zero valuation closes a paid loan.
            $table->decimal('value_amount', 14, 2);
            $table->date('valuation_date');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(
                ['net_worth_entry_id', 'valuation_date'],
                'net_worth_valuation_entry_date_unique'
            );
            $table->index(['net_worth_entry_id', 'valuation_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE net_worth_entries ADD CONSTRAINT net_worth_entries_type_valid CHECK (type IN ('asset', 'liability'))"
            );
            DB::statement(
                "ALTER TABLE net_worth_entries ADD CONSTRAINT net_worth_entries_category_valid CHECK ((type = 'asset' AND category IN ('cash', 'investment', 'other_asset')) OR (type = 'liability' AND category IN ('loan', 'other_liability')))"
            );
            DB::statement(
                "ALTER TABLE net_worth_entries ADD CONSTRAINT net_worth_entries_status_valid CHECK (status IN ('active', 'archived'))"
            );
            DB::statement(
                'ALTER TABLE net_worth_valuations ADD CONSTRAINT net_worth_valuations_value_nonnegative CHECK (value_amount >= 0)'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('net_worth_entries') && DB::table('net_worth_entries')->exists()) {
            throw new RuntimeException(
                'Refusing to drop net worth tables while manual valuation history exists. '
                .'Export or explicitly remove the entries and valuations first.'
            );
        }

        Schema::dropIfExists('net_worth_valuations');
        Schema::dropIfExists('net_worth_entries');
    }
};
