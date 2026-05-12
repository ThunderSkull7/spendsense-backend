<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            $table->string('name')->unique();
            $table->string('type'); // income / expense

            $table->timestamps();

            // Indexes
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};