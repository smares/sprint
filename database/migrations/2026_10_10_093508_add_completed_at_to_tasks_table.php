<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a task was last completed (null while it is open), for the statistics. Tasks that are done already get the
     * time of their last status change from the history, or their creation if they never changed status (imported done).
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('due_date');
            $table->index(['project_id', 'completed_at']);
        });

        DB::table('tasks')
            ->whereIn('status_id', DB::table('task_statuses')->where('is_done', true)->select('id'))
            ->update(['completed_at' => DB::raw(
                "coalesce((select max(task_activities.created_at) from task_activities where task_activities.task_id = tasks.id and task_activities.type = 'status_changed'), tasks.created_at)"
            )]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'completed_at']);
            $table->dropColumn('completed_at');
        });
    }
};
