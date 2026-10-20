<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            // Null means "use the application defaults". Existing rows are
            // backfilled below so the configured values are explicit.
            $table->json('alert_thresholds')->nullable()->after('month_year');
        });

        DB::table('budgets')
            ->whereNull('alert_thresholds')
            ->update(['alert_thresholds' => json_encode([50, 80, 100])]);
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn('alert_thresholds');
        });
    }
};
