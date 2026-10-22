<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('saved_filters', function (Blueprint $table) {
            // The project view the saved settings belong to: the list (filters and sorting) or the board (sorting)
            $table->string('view')->default('list');
            // The view that opens by default for everybody in the project (a shared one; at most one per project and view)
            $table->boolean('is_default')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('saved_filters', function (Blueprint $table) {
            $table->dropColumn(['view', 'is_default']);
        });
    }
};
