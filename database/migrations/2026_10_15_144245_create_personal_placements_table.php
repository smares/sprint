<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which of their own columns a person has put a task in, and where in it. A task without a row here is unsorted.
     * Deleting the column or the task removes the row, which sends the task back to unsorted.
     */
    public function up(): void
    {
        Schema::create('personal_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_column_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'task_id']);
            $table->index(['personal_column_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_placements');
    }
};
