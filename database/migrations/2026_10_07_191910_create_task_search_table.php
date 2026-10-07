<?php

use App\TaskSearch;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations. Where FTS5 is unavailable the search simply falls back to LIKE.
     */
    public function up(): void
    {
        $search = app(TaskSearch::class);

        if ($search->createIndexTable()) {
            $search->rebuild();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app(TaskSearch::class)->dropIndexTable();
    }
};
