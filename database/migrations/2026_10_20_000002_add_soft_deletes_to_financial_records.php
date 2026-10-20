<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expenses and incomes are financial history. Ordinary API deletion moves
     * them to a recoverable state instead of removing the row immediately.
     *
     * The down migration fails closed if any recoverable rows exist: dropping
     * deleted_at would silently make those rows active again in an older
     * application image. An explicit, reviewed cleanup is required first.
     */
    public function up(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            $table->softDeletes();
            $table->index('deleted_at');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->softDeletes();
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        $recoverableRows = DB::table('incomes')->whereNotNull('deleted_at')->exists()
            || DB::table('expenses')->whereNotNull('deleted_at')->exists();

        if ($recoverableRows) {
            throw new RuntimeException(
                'Refusing to remove soft-delete columns while recoverable financial rows exist. '
                .'Restore or explicitly purge those rows after review, then retry the rollback. '
                .'No rows were changed.'
            );
        }

        Schema::table('incomes', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn('deleted_at');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn('deleted_at');
        });
    }
};
