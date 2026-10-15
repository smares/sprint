<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('repeat_day')->nullable()->after('repeat_until');
        });

        // The day of the month wanted by existing rules is the one their current due date has. Done in PHP, as the
        // functions to read a day from a date differ between the supported databases.
        DB::table('tasks')
            ->whereNotNull('repeat_unit')
            ->whereNotNull('due_date')
            ->select(['id', 'due_date'])
            ->orderBy('id')
            ->each(function (object $task): void {
                DB::table('tasks')->where('id', $task->id)->update(['repeat_day' => Carbon::parse($task->due_date)->day]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('repeat_day');
        });
    }
};
