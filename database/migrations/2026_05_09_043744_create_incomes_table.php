<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');

            $table->decimal('amount', 10, 2);

            $table->string('source');

            $table->date('income_date');

            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('income_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};