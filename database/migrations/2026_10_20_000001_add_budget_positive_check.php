<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Existing zero/negative budgets are preserved for human review. The
     * constraint is NOT VALID, so PostgreSQL enforces it for new rows and
     * updates without scanning or rewriting legacy data.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE budgets ADD CONSTRAINT budgets_limit_positive CHECK (limit_amount > 0) NOT VALID'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE budgets DROP CONSTRAINT IF EXISTS budgets_limit_positive');
    }
};
