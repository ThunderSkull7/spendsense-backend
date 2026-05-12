<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');

            $table->foreignId('category_id')
                  ->constrained()
                  ->onDelete('cascade');

            $table->decimal('limit_amount', 10, 2);

            $table->date('month_year');

            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('category_id');
            $table->index('month_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};