<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            // How often this income is received (one-time, monthly, ...).
            // Nullable so existing rows keep their "one-time" semantics.
            $table->string('frequency')->nullable();

            // Optional free-form note stored with the entry.
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            $table->dropColumn(['frequency', 'notes']);
        });
    }
};
