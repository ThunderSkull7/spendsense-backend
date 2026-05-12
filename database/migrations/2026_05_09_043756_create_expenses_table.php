<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');

            $table->foreignId('category_id')
                  ->constrained()
                  ->onDelete('cascade');

            $table->decimal('amount', 10, 2);

            $table->string('description');

            $table->date('expense_date');

            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('category_id');
            $table->index('expense_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};