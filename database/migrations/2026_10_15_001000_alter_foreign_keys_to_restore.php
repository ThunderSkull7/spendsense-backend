<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // This corrective DDL is MySQL-only: SQLite (used by the automated test
        // suite) does not support ALTER TABLE ... ADD CONSTRAINT and its schema
        // already declares these foreign keys with ON DELETE RESTRICT in the
        // create_expenses_table / create_budgets_table migrations.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Drop and recreate the foreign key on expenses.category_id with restrict
        try {
            DB::statement('ALTER TABLE expenses DROP FOREIGN KEY expenses_category_id_foreign');
        } catch (\Exception $e) {
            // Constraint might not exist
        }
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_category_id_foreign FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT');

        // Drop and recreate the foreign key on budgets.category_id with restrict
        try {
            DB::statement('ALTER TABLE budgets DROP FOREIGN KEY budgets_category_id_foreign');
        } catch (\Exception $e) {
            // Constraint might not exist
        }
        DB::statement('ALTER TABLE budgets ADD CONSTRAINT budgets_category_id_foreign FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT');
    }

    public function down(): void {
        if (DB::getDriverName() !== 'mysql') {
            // up() was a no-op on this driver, so there is nothing to revert.
            return;
        }

        throw new \RuntimeException(
            'Rollback not supported: this migration reintroduces ON DELETE CASCADE '
            . 'for expense and budget category foreign keys, weakening data integrity. '
            . 'To restore the original cascade behavior, manually recreate the '
            . 'foreign-key constraints with onDelete("cascade").'
        );
    }
};